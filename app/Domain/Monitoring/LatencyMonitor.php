<?php

namespace App\Domain\Monitoring;

use Illuminate\Support\Facades\DB;

/**
 * Ping/loss from the club to game servers, measured by the club agent once a minute.
 * Three bad measurements in a row open a «latency_problem», three good ones close it.
 */
final class LatencyMonitor
{
    public const STREAK = 3;

    public function __construct(private EvaluateSample $events) {}

    public static function loss(int $sent, int $received): float { return $sent ? round(100 * ($sent - $received) / $sent, 1) : 100.0; }

    public static function bad(object $target, array $s): bool
    {
        return $s['received'] === 0 || self::loss($s['sent'], $s['received']) > $target->max_loss_pct
            || ($s['rtt_avg_ms'] !== null && $s['rtt_avg_ms'] > $target->max_rtt_ms);
    }

    /** Call inside a transaction with the target row locked. */
    public function evaluate(object $t, string $clubName, array $s): void
    {
        $bad = self::bad($t, $s);
        $u = $bad ? ['bad_streak' => $t->bad_streak + 1, 'good_streak' => 0] : ['good_streak' => $t->good_streak + 1, 'bad_streak' => 0];
        $loss = self::loss($s['sent'], $s['received']);
        $what = $s['received'] === 0 ? 'сервер не отвечает' : 'пинг '.round((float) $s['rtt_avg_ms']).' мс, потери '.$loss.'%';
        $payload = ['target_id' => $t->id, 'host' => $t->host, 'rtt_avg_ms' => $s['rtt_avg_ms'], 'loss_pct' => $loss];
        if ($bad && $t->state !== 'problem' && $u['bad_streak'] >= self::STREAK) {
            $u += ['state' => 'problem', 'episode' => $t->episode + 1];
            $this->events->event($t->club_id, 'latency_problem', 'latency:'.$t->id.':'.($t->episode + 1).':open', $payload + [
                'message' => "🎮 {$clubName}: {$t->name} — {$what}\nНорма: до {$t->max_rtt_ms} мс и до {$t->max_loss_pct}% потерь. Проверьте канал провайдера."]);
        } elseif (! $bad && $t->state === 'problem' && $u['good_streak'] >= self::STREAK) {
            $u['state'] = 'normal';
            $this->events->event($t->club_id, 'latency_recovered', 'latency:'.$t->id.':'.$t->episode.':recovered', $payload + [
                'message' => "✅ {$clubName}: {$t->name} — пинг в норме ({$what})."]);
        }
        DB::table('latency_targets')->where('id', $t->id)->update($u + ['last_sample_at' => now(), 'updated_at' => now()]);
    }
}
