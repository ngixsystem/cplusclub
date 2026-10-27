<?php

namespace App\Domain\Reports;

use App\Models\Club;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What C+CLub did for one club in one calendar month (club time zone): incidents,
 * reaction and resolution times, visits and cleanings, monitoring alerts, problem PCs.
 */
final class MonthlyReport
{
    private const DONE = "COALESCE(t.resolved_at, t.closed_at)";

    public function build(Club $club, string $month): array
    {
        $tz = $club->timezone ?: 'Asia/Tashkent';
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', $tz);
        $end = $start->addMonth();
        [$from, $to] = [$start->utc(), $end->utc()];

        $created = DB::table('tickets as t')->where('t.club_id', $club->id)->where('t.created_at', '>=', $from)->where('t.created_at', '<', $to);
        $times = (clone $created)->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where '.self::DONE.' is not null) as done')
            ->selectRaw('count(*) filter (where '.self::DONE.' <= t.created_at + interval \'2 hours\') as within_2h')
            ->selectRaw('count(*) filter (where '.self::DONE.' <= t.created_at + interval \'24 hours\') as within_24h')
            ->selectRaw('percentile_cont(0.5) within group (order by extract(epoch from ('.self::DONE.' - t.created_at)) / 60) as median_resolve')
            ->selectRaw('percentile_cont(0.5) within group (order by extract(epoch from (t.first_responded_at - t.created_at)) / 60) as median_response')
            ->selectRaw("count(*) filter (where t.source in ('telegram','link')) as from_telegram")
            ->first();
        $total = (int) $times->total;
        $pct = fn ($n) => $total ? (int) round($n * 100 / $total) : null;

        $closed = DB::table('tickets as t')->where('t.club_id', $club->id)->whereRaw(self::DONE.' >= ? and '.self::DONE.' < ?', [$from, $to])->count();
        $openAtEnd = DB::table('tickets as t')->where('t.club_id', $club->id)->where('t.created_at', '<', $to)
            ->where(fn ($q) => $q->whereRaw(self::DONE.' is null')->orWhereRaw(self::DONE.' >= ?', [$to]))->count();
        $categories = (clone $created)->select('t.category')->selectRaw('count(*) as n')->groupBy('t.category')->orderByDesc('n')->limit(8)->get();
        $priorities = (clone $created)->select('t.priority')->selectRaw('count(*) as n')->groupBy('t.priority')->pluck('n', 'priority');
        $ratings = DB::table('tickets')->where('club_id', $club->id)->whereNotNull('rating')->where('rated_at', '>=', $from)->where('rated_at', '<', $to)
            ->selectRaw('count(*) as n, round(avg(rating)::numeric, 1) as avg')->first();

        $visits = DB::table('visits')->where('club_id', $club->id)->where('status', 'completed')
            ->where('completed_at', '>=', $from)->where('completed_at', '<', $to)->count();
        $works = DB::table('inspections')->where('club_id', $club->id)->where('status', 'completed')
            ->whereBetween('completed_date', [$start->toDateString(), $end->subDay()->toDateString()])
            ->select('work_type')->selectRaw('count(*) as n')->groupBy('work_type')->pluck('n', 'work_type');

        $events = DB::table('outbox_events')->where('club_id', $club->id)->where('created_at', '>=', $from)->where('created_at', '<', $to);
        $link = (clone $events)->where('kind', 'link_down')->count();
        $temp = (clone $events)->where('kind', 'monitor_problem')->whereRaw("payload->>'metric' in ('cpu_temp','gpu_temp')")->count();
        $followups = DB::table('link_followups')->where('club_id', $club->id)->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw("count(*) filter (where state = 'recovered' and responded_at is not null) as fixed_by_staff, count(*) filter (where state = 'ticketed') as ticketed")->first();

        // Problem PCs: tickets plus monitoring alerts in the month.
        $ticketCounts = (clone $created)->whereNotNull('t.equipment_id')->select('t.equipment_id')->selectRaw('count(*) as n')->groupBy('t.equipment_id')->pluck('n', 'equipment_id');
        $alertCounts = (clone $events)->whereIn('kind', ['monitor_problem', 'link_down'])->whereRaw("payload->>'equipment_id' ~ '^[0-9]+$'")
            ->selectRaw("(payload->>'equipment_id')::bigint as equipment_id, count(*) as n")->groupByRaw("payload->>'equipment_id'")->pluck('n', 'equipment_id');
        $ids = collect($ticketCounts->keys())->merge($alertCounts->keys())->unique();
        $names = DB::table('equipment')->where('club_id', $club->id)->whereIn('id', $ids)->get(['id', 'name', 'workstation_number', 'zone'])->keyBy('id');
        $top = $ids->filter(fn ($id) => $names->has($id))->map(fn ($id) => [
            'id' => (int) $id, 'name' => $names[$id]->name, 'place' => $names[$id]->workstation_number, 'zone' => $names[$id]->zone,
            'tickets' => (int) ($ticketCounts[$id] ?? 0), 'alerts' => (int) ($alertCounts[$id] ?? 0),
        ])->sortByDesc(fn ($r) => $r['tickets'] * 3 + $r['alerts'])->take(5)->values();

        return [
            'month' => $month, 'label' => mb_convert_case($start->locale('ru')->isoFormat('MMMM YYYY'), MB_CASE_TITLE), 'club' => ['id' => $club->id, 'name' => $club->name],
            'tickets' => ['created' => $total, 'closed' => $closed, 'open_at_end' => $openAtEnd, 'from_telegram' => (int) $times->from_telegram,
                'within_2h' => $pct($times->within_2h), 'within_24h' => $pct($times->within_24h),
                'median_resolve' => $times->median_resolve !== null ? (int) round($times->median_resolve) : null,
                'median_response' => $times->median_response !== null ? (int) round($times->median_response) : null,
                'categories' => $categories, 'priorities' => $priorities],
            'rating' => ['count' => (int) $ratings->n, 'avg' => $ratings->avg !== null ? (float) $ratings->avg : null],
            'service' => ['visits' => $visits, 'inspections' => (int) ($works['inspection'] ?? 0), 'cleanings' => (int) ($works['cleaning'] ?? 0),
                'other' => (int) $works->except(['inspection', 'cleaning'])->sum()],
            'monitoring' => ['link' => $link, 'temperature' => $temp, 'fixed_by_staff' => (int) $followups->fixed_by_staff, 'link_tickets' => (int) $followups->ticketed],
            'top' => $top,
        ];
    }

    public static function minutes(?int $m): string
    {
        if ($m === null) return '—';
        if ($m < 60) return $m.' мин';
        if ($m < 48 * 60) return intdiv($m, 60).' ч'.($m % 60 ? ' '.($m % 60).' мин' : '');
        return round($m / 1440, 1).' дн';
    }

    /** Short Telegram summary sent on the 1st for the month that just ended. */
    public static function telegram(array $r): string
    {
        $t = $r['tickets']; $s = $r['service']; $m = $r['monitoring'];
        $lines = ["📊 {$r['club']['name']} · отчёт за ".mb_strtolower($r['label']), '',
            "Заявок: {$t['created']}, закрыто: {$t['closed']}".($t['open_at_end'] ? ", в работе на конец месяца: {$t['open_at_end']}" : ''),
        ];
        if ($t['within_2h'] !== null) $lines[] = "Решено за 2 часа: {$t['within_2h']}% · за сутки: {$t['within_24h']}%";
        if ($t['median_resolve'] !== null) $lines[] = 'Обычное время решения: '.self::minutes($t['median_resolve']);
        $lines[] = "Выездов: {$s['visits']} · осмотров ПК: {$s['inspections']} · чисток: {$s['cleanings']}";
        if ($m['link'] || $m['temperature']) $lines[] = "Тревоги мониторинга: сеть {$m['link']}, перегрев {$m['temperature']}";
        if ($m['fixed_by_staff']) $lines[] = "Кабель переподключили сами: {$m['fixed_by_staff']} раз — спасибо!";
        if ($r['rating']['avg'] !== null) $lines[] = "Средняя оценка: {$r['rating']['avg']} из 5 ({$r['rating']['count']})";
        if (count($r['top'])) $lines[] = 'Чаще всего: '.collect($r['top'])->take(3)->pluck('name')->implode(', ');
        return implode("\n", $lines);
    }
}
