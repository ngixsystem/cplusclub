<?php

namespace App\Domain\Monitoring;

use App\Models\Equipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Everything that happened to one PC, newest first, plus 30-day temperature and link. */
final class EquipmentPassport
{
    private const STATUS = ['new' => 'новая', 'accepted' => 'принята', 'working' => 'в работе', 'approval' => 'согласование', 'parts' => 'ждём запчасти', 'resolved' => 'выполнена', 'closed' => 'закрыта'];
    private const WORK = ['inspection' => 'Осмотр', 'cleaning' => 'Чистка', 'disassembly' => 'Разборка', 'replacement' => 'Замена'];
    private const FIELD = ['cpu' => 'Процессор', 'gpu' => 'Видеокарта', 'ram_mb' => 'Оперативная память', 'disks' => 'Накопители', 'motherboard' => 'Материнская плата',
        'nic' => 'Сетевая карта', 'serial_number' => 'Серийный номер', 'mac' => 'MAC', 'status' => 'Статус'];

    public function build(Equipment $e, bool $finance = true): array
    {
        $tz = $e->club->timezone ?: 'Asia/Tashkent';
        $items = collect();

        foreach (DB::table('tickets')->where('equipment_id', $e->id)->orderByDesc('id')->limit(40)->get(['id', 'category', 'description', 'status', 'priority', 'created_at', 'closed_at', 'solution']) as $t) {
            $items->push(['kind' => 'ticket', 'at' => $t->created_at, 'title' => 'Заявка #'.$t->id.' · '.$t->category, 'text' => mb_strimwidth($t->description, 0, 160, '…'),
                'meta' => self::STATUS[$t->status] ?? $t->status, 'href' => '/tickets/'.$t->id, 'tone' => in_array($t->status, ['resolved', 'closed'], true) ? 'ok' : ($t->priority === 'critical' || $t->priority === 'high' ? 'bad' : 'warn')]);
            if ($t->closed_at && filled($t->solution)) {
                $items->push(['kind' => 'fix', 'at' => $t->closed_at, 'title' => 'Решено по заявке #'.$t->id, 'text' => mb_strimwidth($t->solution, 0, 160, '…'), 'meta' => null, 'href' => '/tickets/'.$t->id, 'tone' => 'ok']);
            }
        }
        $events = DB::table('outbox_events')->whereIn('kind', ['monitor_problem', 'monitor_recovered', 'link_down', 'link_restored', 'hardware_changed'])
            ->where('club_id', $e->club_id)->whereRaw("payload->>'equipment_id' = ?", [(string) $e->id])->orderByDesc('id')->limit(60)->get(['kind', 'payload', 'created_at']);
        foreach ($events as $ev) {
            $p = json_decode($ev->payload, true) ?: [];
            [$title, $tone] = match ($ev->kind) {
                'monitor_problem' => [self::metric($p['metric'] ?? '').': тревога', 'bad'],
                'monitor_recovered' => [self::metric($p['metric'] ?? '').': норма', 'ok'],
                'link_down' => ['Сеть: линк '.LinkMessages::speed((int) ($p['value'] ?? 0)), 'bad'],
                'link_restored' => ['Сеть восстановлена', 'ok'],
                'hardware_changed' => ['Изменилось железо', 'warn'],
            };
            if ($ev->kind === 'link_down' || $ev->kind === 'link_restored') $text = null;   // the club-facing text is long; the title says it
            else $text = isset($p['message']) ? mb_strimwidth(preg_replace('/^[^\n]*\n/u', '', $p['message']) ?: $p['message'], 0, 200, '…') : (isset($p['value']) ? 'Значение: '.$p['value'] : null);
            $items->push(['kind' => 'alert', 'at' => $ev->created_at, 'title' => $title, 'text' => $text, 'meta' => null, 'href' => null, 'tone' => $tone]);
        }
        foreach (DB::table('inspections')->where('equipment_id', $e->id)->where('status', 'completed')->orderByDesc('completed_date')->limit(30)->get(['work_type', 'completed_date', 'findings', 'work_done', 'visit_id']) as $i) {
            $items->push(['kind' => 'service', 'at' => CarbonImmutable::parse($i->completed_date, $tz)->setTime(12, 0)->toIso8601String(), 'title' => self::WORK[$i->work_type] ?? 'Обслуживание',
                'text' => mb_strimwidth(trim(($i->work_done ?? '').' '.($i->findings ? '· '.$i->findings : '')), 0, 200, '…') ?: null, 'meta' => null, 'href' => '/visits/'.$i->visit_id, 'tone' => 'ok']);
        }
        foreach (DB::table('equipment_changes as c')->leftJoin('users as u', 'u.id', '=', 'c.user_id')->where('c.equipment_id', $e->id)->orderByDesc('c.id')->limit(40)
            ->get(['c.field', 'c.old_value', 'c.new_value', 'c.source', 'c.created_at', 'u.name']) as $c) {
            $show = fn ($v) => $c->field === 'ram_mb' && is_numeric($v) ? BootHardware::show('ram_mb', $v) : $v;
            $items->push(['kind' => 'change', 'at' => $c->created_at, 'title' => (self::FIELD[$c->field] ?? $c->field).($c->old_value === null || $c->old_value === '' ? ': записано' : ': изменение'),
                'text' => ($c->old_value === null || $c->old_value === '' ? '' : $show($c->old_value).' → ').$show($c->new_value),
                'meta' => $c->source === 'boot' ? 'по данным iCafeCloud Boot' : ($c->name ? 'изменил '.$c->name : 'импорт'), 'href' => null, 'tone' => 'muted']);
        }

        $timeline = $items->sortByDesc(fn ($i) => CarbonImmutable::parse($i['at'])->timestamp)->take(80)->values();

        $since = now($tz)->startOfDay()->subDays(29);
        $days = DB::table('telemetry_hourly')->where('equipment_id', $e->id)->where('hour', '>=', $since->utc())
            ->selectRaw("to_char(hour AT TIME ZONE ?, 'YYYY-MM-DD') as day, round(max(cpu_temp_avg)) as cpu, round(max(gpu_temp_avg)) as gpu, min(link_min) as link", [$tz])
            ->groupBy('day')->orderBy('day')->get();

        $stats = DB::table('tickets')->where('equipment_id', $e->id)
            ->selectRaw("count(*) as total, count(*) filter (where created_at >= now() - interval '90 days') as recent, count(*) filter (where status not in ('resolved','closed')) as open")->first();

        return [
            'timeline' => $timeline,
            'charts' => ['labels' => $days->pluck('day'), 'cpu' => $days->map(fn ($d) => $d->cpu !== null ? (int) $d->cpu : 0), 'gpu' => $days->map(fn ($d) => $d->gpu !== null ? (int) $d->gpu : 0),
                'link' => $days->map(fn ($d) => $d->link !== null ? (int) $d->link : 0), 'has_link' => $days->contains(fn ($d) => $d->link !== null)],
            'hardware' => array_filter(['cpu' => $e->cpu, 'motherboard' => $e->motherboard, 'gpu' => $e->gpu, 'ram' => $e->ram_mb ? BootHardware::show('ram_mb', $e->ram_mb) : null, 'disks' => $e->disks, 'nic' => $e->nic]),
            'hardware_checked_at' => $e->hardware_checked_at,
            'stats' => ['tickets' => (int) $stats->total, 'recent' => (int) $stats->recent, 'open' => (int) $stats->open,
                'alerts' => DB::table('outbox_events')->where('club_id', $e->club_id)->whereIn('kind', ['monitor_problem', 'link_down'])->whereRaw("payload->>'equipment_id' = ?", [(string) $e->id])->where('created_at', '>=', now()->subDays(90))->count()],
        ];
    }

    private static function metric(string $m): string
    {
        return ['cpu_temp' => 'Температура CPU', 'gpu_temp' => 'Температура GPU', 'cpu_load' => 'Загрузка CPU', 'gpu_load' => 'Загрузка GPU', 'ram_used_percent' => 'Память', 'disk_free_percent' => 'Диск', 'link_speed' => 'Сеть'][$m] ?? 'Мониторинг';
    }
}
