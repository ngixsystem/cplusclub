<?php

namespace App\Domain\Reports;

use App\Domain\Icafe\Occupancy;
use App\Models\Club;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** The owner's morning summary of yesterday for one club (club time zone). */
final class DailyDigest
{
    public function __construct(private Occupancy $occupancy) {}

    public function build(Club $club, CarbonImmutable $day): array
    {
        $tz = $club->timezone ?: 'Asia/Tashkent';
        $day = $day->tz($tz)->startOfDay();
        $range = fn (CarbonImmutable $d) => [$d->utc(), $d->addDay()->utc()];
        $shifts = fn (CarbonImmutable $d) => DB::table('icafe_shifts')->where('club_id', $club->id)->whereBetween('started_at', $range($d))
            ->where('started_at', '<', $range($d)[1])->orderBy('started_at')->get();
        $today = $shifts($day); $week = $shifts($day->subWeek());
        $revenue = $today->sum(fn ($s) => (float) $s->total); $before = $week->sum(fn ($s) => (float) $s->total);
        $best = $today->sortByDesc(fn ($s) => (float) $s->total)->first();
        [$from, $to] = $range($day);

        $games = DB::table('game_usage_hourly')->where('club_id', $club->id)->where('hour', '>=', $from)->where('hour', '<', $to)
            ->select('game')->selectRaw('sum(minutes) as m')->groupBy('game')->orderByDesc('m')->limit(3)->get();
        $open = DB::table('tickets')->where('club_id', $club->id)->whereNotIn('status', ['resolved', 'closed']);
        return [
            'club' => $club->name, 'date' => $day->toDateString(), 'currency' => $club->icafe_currency ?: 'UZS',
            'icafe' => (bool) ($club->icafe_license && $club->icafe_token),
            'revenue' => $revenue, 'revenue_week_ago' => $before, 'shifts' => $today->count(),
            'cash' => $today->sum(fn ($s) => (float) $s->cash), 'card' => $today->sum(fn ($s) => (float) $s->card), 'qr' => $today->sum(fn ($s) => (float) $s->qr),
            'best' => $best ? ['operator' => $best->operator, 'total' => (float) $best->total] : null,
            'anomalies' => DB::table('outbox_events')->where('club_id', $club->id)->where('kind', 'cash_anomaly')->whereBetween('created_at', [$from, $to->addHours(12)])->count(),
            'occupancy' => $this->occupancy->day($club, $day),
            'games' => $games->map(fn ($g) => ['game' => $g->game, 'hours' => round($g->m / 60, 1)])->all(),
            'tickets' => ['created' => DB::table('tickets')->where('club_id', $club->id)->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
                'open' => (clone $open)->count(), 'critical' => (clone $open)->where('priority', 'critical')->count()],
            'alerts' => DB::table('alerts')->where('club_id', $club->id)->where('state', 'active')->count(),
            'remote_offline' => DB::table('remote_hosts')->where('club_id', $club->id)->where('online', false)->pluck('name')->all(),
        ];
    }

    public static function telegram(array $d): string
    {
        $m = fn ($v) => number_format((float) $v, 0, ',', ' ').' '.$d['currency'];
        $date = CarbonImmutable::parse($d['date'])->locale('ru')->isoFormat('dddd, D MMMM');
        $lines = ["☀️ {$d['club']} · итоги за ".$date];
        if ($d['icafe'] && $d['shifts']) {
            $delta = $d['revenue_week_ago'] > 0 ? (int) round(($d['revenue'] - $d['revenue_week_ago']) * 100 / $d['revenue_week_ago']) : null;
            $lines[] = '';
            $lines[] = '💰 Касса: '.$m($d['revenue']).($delta !== null ? ' ('.($delta >= 0 ? '▲ +' : '▼ ').$delta.'% к прошлой неделе)' : '');
            $parts = array_filter(['наличные '.$m($d['cash']), $d['card'] ? 'карты '.$m($d['card']) : null, $d['qr'] ? 'QR '.$m($d['qr']) : null]);
            $lines[] = '   '.implode(' · ', $parts);
            $lines[] = '   Смен: '.$d['shifts'].($d['best'] && $d['shifts'] > 1 ? ' · лучшая — '.$d['best']['operator'].' ('.$m($d['best']['total']).')' : '');
            if ($d['anomalies']) $lines[] = '   ⚠️ Смен с отклонениями: '.$d['anomalies'].' — подробности выше в чате';
        } elseif ($d['icafe']) {
            $lines[] = ''; $lines[] = '💰 Смен за день нет';
        }
        if ($d['occupancy']) {
            $lines[] = '🖥 Загрузка зала: в среднем '.$d['occupancy']['avg'].'%'
                .($d['occupancy']['peak_hour'] !== null ? ', пик '.$d['occupancy']['peak_pct'].'% в '.sprintf('%02d:00', $d['occupancy']['peak_hour']) : '');
        }
        if ($d['games']) $lines[] = '🎮 Топ игр: '.implode(', ', array_map(fn ($g) => $g['game'].' '.$g['hours'].' ч', $d['games']));
        $t = $d['tickets'];
        $lines[] = '🛠 Заявки: новых '.$t['created'].', в работе '.$t['open'].($t['critical'] ? ' (критичных '.$t['critical'].')' : '');
        $problems = [];
        if ($d['alerts']) $problems[] = 'тревог мониторинга '.$d['alerts'];
        if ($d['remote_offline']) $problems[] = 'не в сети: '.implode(', ', $d['remote_offline']);
        $lines[] = $problems ? '🔴 Сейчас: '.implode(' · ', $problems) : '🟢 Сейчас всё в порядке';
        return implode("\n", $lines);
    }
}
