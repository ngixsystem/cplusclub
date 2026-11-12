<?php

namespace App\Domain\Icafe;

use App\Domain\Monitoring\EvaluateSample;
use App\Models\Club;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cash control: each closed shift is compared with the club's usual shifts that start at
 * the same hour (last 4 weeks). Unusually low takings, an early close or a large refund
 * share raises one `cash_anomaly` message; a shift left open too long raises another.
 */
final class CashControl
{
    public const LOW_SHARE = 0.6;        // total below 60% of the usual
    public const SHORT_SHARE = 0.7;      // closed after less than 70% of the usual length
    public const REFUND_SHARE = 0.05;    // refunds above 5% of the total
    public const LONG_OPEN_HOURS = 16;
    public const MIN_BASELINE = 4;

    public function __construct(private ShiftSync $sync, private EvaluateSample $events) {}

    /** @return list<array{shift: object, reasons: list<string>}> anomalies raised now */
    public function evaluate(Club $club): array
    {
        $tz = $club->timezone ?: 'Asia/Tashkent';
        $raised = [];
        $fresh = DB::table('icafe_shifts')->where('club_id', $club->id)->whereNotNull('ended_at')->whereNull('checked_at')
            ->where('ended_at', '>=', now()->subDays(2))->orderBy('started_at')->get();
        foreach ($fresh as $s) {
            $reasons = $this->reasons($club, $s, $tz);
            DB::table('icafe_shifts')->where('id', $s->id)->update(['checked_at' => now()]);
            if (! $reasons) continue;
            $this->events->event($club->id, 'cash_anomaly', 'cash-anomaly:'.$club->id.':'.$s->shift_id,
                ['shift_id' => $s->shift_id, 'reasons' => $reasons, 'message' => $this->message($club, $s, $reasons, $tz)]);
            $raised[] = ['shift' => $s, 'reasons' => $reasons];
        }
        // Old unchecked shifts (first sync) are baseline, not news.
        DB::table('icafe_shifts')->where('club_id', $club->id)->whereNotNull('ended_at')->whereNull('checked_at')
            ->where('ended_at', '<', now()->subDays(2))->update(['checked_at' => now()]);

        $long = DB::table('icafe_shifts')->where('club_id', $club->id)->whereNull('ended_at')->whereNull('long_open_alerted_at')
            ->where('started_at', '<', now()->subHours(self::LONG_OPEN_HOURS))->where('started_at', '>', now()->subDays(3))->get();
        foreach ($long as $s) {
            $h = (int) floor(CarbonImmutable::parse($s->started_at)->diffInHours(now()));
            $msg = "⏱ {$club->name}: смена {$s->operator} открыта уже {$h} ч (с ".CarbonImmutable::parse($s->started_at)->tz($tz)->format('d.m H:i').').'
                ."\nВозможно, её забыли закрыть — касса следующей смены смешается с этой.";
            $this->events->event($club->id, 'cash_anomaly', 'cash-long:'.$club->id.':'.$s->shift_id, ['shift_id' => $s->shift_id, 'reasons' => ['long_open'], 'message' => $msg]);
            DB::table('icafe_shifts')->where('id', $s->id)->update(['long_open_alerted_at' => now()]);
            $raised[] = ['shift' => $s, 'reasons' => ['long_open']];
        }
        return $raised;
    }

    /** @return list<string> */
    public function reasons(Club $club, object $s, string $tz): array
    {
        $start = CarbonImmutable::parse($s->started_at);
        $hour = (int) $start->tz($tz)->format('G');
        // Usual shifts: closed, started within ±1 h of the same local hour, previous 28 days.
        $base = DB::table('icafe_shifts')->where('club_id', $club->id)->whereNotNull('ended_at')->where('id', '<>', $s->id)
            ->where('started_at', '>=', $start->subDays(28))->where('started_at', '<', $start->subHours(1))
            ->whereRaw('abs(((extract(hour from started_at at time zone ?)::int - ? + 36) % 24) - 12) <= 1', [$tz, $hour])
            ->selectRaw('percentile_cont(0.5) within group (order by total) as total, percentile_cont(0.5) within group (order by extract(epoch from ended_at - started_at)) as secs, count(*) as n')
            ->first();
        $reasons = [];
        $secs = CarbonImmutable::parse($s->ended_at)->getTimestamp() - $start->getTimestamp();
        if ($base && (int) $base->n >= self::MIN_BASELINE) {
            if ((float) $base->total > 0 && (float) $s->total < self::LOW_SHARE * (float) $base->total) $reasons[] = 'low_total';
            if ((float) $base->secs >= 6 * 3600 && $secs < self::SHORT_SHARE * (float) $base->secs) $reasons[] = 'short';
        }
        try {
            $refund = $this->sync->refund($club, $s->shift_id);
            DB::table('icafe_shifts')->where('id', $s->id)->update(['refund' => $refund]);
            $s->refund = $refund;
            if ($refund !== null && $refund > 0 && (float) $s->total > 0 && $refund > self::REFUND_SHARE * (float) $s->total) $reasons[] = 'refunds';
        } catch (\Throwable) { /* detail unavailable: judge by totals only */ }
        $s->baseline_total = $base->total ?? null;
        $s->baseline_secs = $base->secs ?? null;
        return $reasons;
    }

    private function message(Club $club, object $s, array $reasons, string $tz): string
    {
        $cur = ' '.($club->icafe_currency ?: 'UZS');
        $m = fn ($v) => number_format((float) $v, 0, ',', ' ').$cur;
        $from = CarbonImmutable::parse($s->started_at)->tz($tz); $to = CarbonImmutable::parse($s->ended_at)->tz($tz);
        $lines = ["⚠️ {$club->name}: проверьте смену {$s->operator}", $from->format('d.m H:i').' — '.$to->format('d.m H:i').' · касса '.$m($s->total), ''];
        if (in_array('low_total', $reasons, true)) $lines[] = '• Касса ниже обычной: '.$m($s->total).' при обычных '.$m($s->baseline_total).' ('.(int) round((float) $s->total * 100 / max(1, (float) $s->baseline_total)).'%)';
        if (in_array('short', $reasons, true)) $lines[] = '• Смена закрыта рано: '.self::hours($to->getTimestamp() - $from->getTimestamp()).' вместо обычных '.self::hours((int) $s->baseline_secs);
        if (in_array('refunds', $reasons, true)) $lines[] = '• Много возвратов: '.$m($s->refund).' ('.(int) round($s->refund * 100 / max(1, (float) $s->total)).'% кассы)';
        $lines[] = ''; $lines[] = 'Это не обвинение — просто смена выбивается из обычной картины. Сверьте с iCafeCloud.';
        return implode("\n", $lines);
    }

    public static function hours(int $secs): string
    {
        $h = intdiv($secs, 3600); $m = intdiv($secs % 3600, 60);
        return $h.' ч'.($m ? ' '.$m.' мин' : '');
    }
}
