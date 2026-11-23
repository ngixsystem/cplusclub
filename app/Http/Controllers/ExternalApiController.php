<?php

namespace App\Http\Controllers;

use App\Domain\Icafe\Client;
use App\Http\Middleware\ExternalApi;
use App\Models\{ApiClient, Club};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB};

/**
 * Read-only external API (v1). Every answer is about the token's own club; times are
 * ISO 8601 in the club's time zone (e.g. 2026-11-22T16:30:00+05:00).
 */
class ExternalApiController extends Controller
{
    private function client(Request $r): ApiClient { return $r->attributes->get('api_client'); }

    private function club(Request $r): Club { return Club::findOrFail($this->client($r)->club_id); }

    private static function iso(?string $value, string $tz, bool $local = false): ?string
    {
        if (! $value || $value === '-' || str_starts_with($value, '0000')) return null;
        return ($local ? CarbonImmutable::parse($value, $tz) : CarbonImmutable::parse($value)->tz($tz))->toIso8601String();
    }

    private static function json(array $body)
    {
        return response()->json($body, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function me(Request $r)
    {
        $c = $this->client($r); $club = $this->club($r);
        $tz = $club->timezone ?: 'Asia/Tashkent';
        return self::json(['client' => $c->name, 'club' => ['id' => $club->id, 'name' => $club->name, 'timezone' => $tz, 'currency' => $club->icafe_currency ?: 'UZS'],
            'scopes' => $c->scopes, 'rate_limit_per_minute' => ExternalApi::PER_MINUTE, 'server_time' => now($tz)->toIso8601String()]);
    }

    /** Live state of every seat: PC or console, busy/free (switched-off PCs are free), who sits and since when. Cached 15 s. */
    public function pcs(Request $r, Client $icafe)
    {
        $club = $this->club($r);
        if (! $club->icafe_license || ! $club->icafe_token) return ExternalApi::error('not_connected', 'Клуб не подключён к iCafeCloud.', 409);
        $tz = $club->timezone ?: 'Asia/Tashkent';
        try {
            $data = Cache::remember('ext:pcs:'.$club->id, 15, function () use ($icafe, $club) {
                $pcs = array_values(array_filter($icafe->get($club->icafe_license, $club->icafe_token, 'pcs'),
                    fn ($p) => (int) ($p['pc_icafe_id'] ?? 0) === (int) $club->icafe_license));
                $online = collect($icafe->get($club->icafe_license, $club->icafe_token, 'onlinePcList'))
                    ->filter(fn ($p) => (int) ($p['is_connected'] ?? 0) === 1)->pluck('pc_name')->all();
                return ['pcs' => $pcs, 'online' => $online, 'fetched_at' => now()->toIso8601String()];
            });
        } catch (\Throwable) {
            return ExternalApi::error('upstream_unavailable', 'iCafeCloud сейчас не отвечает, повторите позже.', 503);
        }
        $now = CarbonImmutable::now();
        // iCafeCloud sometimes returns an empty online list (and always does for some clubs): that is "unknown", not "all off".
        $known = $data['online'] !== [];
        $rows = array_map(function ($p) use ($data, $tz, $now, $known) {
            $busy = (bool) ($p['pc_in_using'] ?? false);
            $online = $known ? in_array((string) $p['pc_name'], array_map('strval', $data['online']), true) : null;
            $console = (int) ($p['pc_console_type'] ?? 0) !== 0;
            $enabled = (bool) ($p['pc_enabled'] ?? true);
            $ends = self::iso($p['status_disconnect_time_local'] ?? null, $tz, true);
            return [
                'id' => (string) $p['pc_name'],
                'name' => (string) $p['pc_name'],
                'type' => $console ? 'console' : 'pc',
                'zone' => ($p['pc_area_name'] ?? '') !== '' ? $p['pc_area_name'] : null,
                'group_id' => isset($p['pc_group_id']) ? (int) $p['pc_group_id'] : null,
                'enabled' => $enabled,
                // A diskless club keeps unused PCs switched off: that is still a free seat. Power/link is `online`.
                'status' => $busy ? 'busy' : ($enabled ? 'free' : 'disabled'),
                'online' => $console ? null : $online,
                'session' => $busy ? [
                    'member_id' => isset($p['status_member_id']) && (int) $p['status_member_id'] > 0 ? (int) $p['status_member_id'] : null,
                    'member_account' => ($p['status_member_account'] ?? '') !== '' ? $p['status_member_account'] : null,
                    'guest' => empty($p['status_member_id']) || (int) $p['status_member_id'] <= 0,
                    'started_at' => self::iso($p['status_connect_time_local'] ?? null, $tz, true),
                    'ends_at' => $ends,
                    'minutes_left' => $ends ? max(0, (int) floor(($now->diffInSeconds(CarbonImmutable::parse($ends), false)) / 60)) : null,
                ] : null,
            ];
        }, $data['pcs']);
        usort($rows, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        $count = fn ($s) => count(array_filter($rows, fn ($x) => $x['status'] === $s));
        return self::json(['data' => $rows, 'meta' => ['total' => count($rows), 'busy' => $count('busy'), 'free' => $count('free'), 'disabled' => $count('disabled'),
            'online' => $known ? count(array_filter($rows, fn ($x) => $x['online'] === true)) : null,
            'fetched_at' => self::iso($data['fetched_at'], $tz)]]);
    }

    /** Cash shifts by start time (default last 7 days, at most 92 days per request). */
    public function shifts(Request $r)
    {
        $v = \Illuminate\Support\Facades\Validator::make($r->query(), ['from' => 'nullable|date', 'to' => 'nullable|date', 'status' => 'nullable|in:open,closed', 'limit' => 'nullable|integer|min:1|max:1000']);
        if ($v->fails()) return ExternalApi::error('bad_request', $v->errors()->first(), 422);
        $d = $v->validated();
        $club = $this->club($r); $tz = $club->timezone ?: 'Asia/Tashkent';
        $to = isset($d['to']) ? CarbonImmutable::parse($d['to'], $tz) : CarbonImmutable::now($tz);
        $from = isset($d['from']) ? CarbonImmutable::parse($d['from'], $tz) : $to->subDays(7);
        if ($from->greaterThan($to) || $from->diffInDays($to) > 92) return ExternalApi::error('bad_range', 'Период: from не позже to и не больше 92 дней.', 422);
        $q = DB::table('icafe_shifts')->where('club_id', $club->id)->where('started_at', '>=', $from->utc())->where('started_at', '<=', $to->utc())
            ->when(($d['status'] ?? null) === 'open', fn ($q) => $q->whereNull('ended_at'))
            ->when(($d['status'] ?? null) === 'closed', fn ($q) => $q->whereNotNull('ended_at'))
            ->orderBy('started_at')->orderBy('id')->limit($d['limit'] ?? 1000);
        $cur = $club->icafe_currency ?: 'UZS';
        $rows = $q->get()->map(fn ($s) => [
            'id' => $s->id, 'shift_id' => $s->shift_id, 'operator' => $s->operator,
            'status' => $s->ended_at ? 'closed' : 'open',
            'started_at' => self::iso($s->started_at, $tz), 'ended_at' => self::iso($s->ended_at, $tz),
            'cash' => (float) $s->cash, 'card' => (float) $s->card, 'qr' => (float) $s->qr, 'total' => (float) $s->total,
            'refund' => $s->refund !== null ? (float) $s->refund : null, 'currency' => $cur,
            'updated_at' => self::iso($s->synced_at, $tz),
        ])->all();
        return self::json(['data' => $rows, 'meta' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'count' => count($rows),
            'note' => 'Открытые смены обновляются каждые 15 минут; итог смены окончательный после её закрытия.']]);
    }

    /**
     * Raw billing events, one by one, as in the iCafeCloud CSV export. Cursor: pass the last
     * `id` you received as `since`. Older history loaded later gets higher ids too, so a
     * consumer that follows the cursor receives every event exactly once.
     */
    public function billingLogs(Request $r)
    {
        $v = \Illuminate\Support\Facades\Validator::make($r->query(), ['since' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:1000',
            'from' => 'nullable|date', 'to' => 'nullable|date', 'event' => 'nullable|string|max:400']);
        if ($v->fails()) return ExternalApi::error('bad_request', $v->errors()->first(), 422);
        $d = $v->validated();
        $club = $this->club($r); $tz = $club->timezone ?: 'Asia/Tashkent';
        $since = (int) ($d['since'] ?? 0); $limit = (int) ($d['limit'] ?? 500);
        $events = isset($d['event']) ? array_values(array_filter(array_map(fn ($e) => strtoupper(trim($e)), explode(',', $d['event'])))) : [];
        $q = DB::table('icafe_billing_logs')->where('club_id', $club->id)->where('id', '>', $since)
            ->when(isset($d['from']), fn ($q) => $q->where('occurred_at', '>=', CarbonImmutable::parse($d['from'], $tz)->utc()))
            ->when(isset($d['to']), fn ($q) => $q->where('occurred_at', '<=', CarbonImmutable::parse($d['to'], $tz)->utc()))
            ->when($events, fn ($q) => $q->whereIn('event', $events))
            ->orderBy('id')->limit($limit + 1);
        $rows = $q->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $cur = $club->icafe_currency ?: 'UZS';
        $data = $rows->map(fn ($e) => [
            'id' => $e->id, 'log_id' => (string) $e->log_id, 'occurred_at' => self::iso($e->occurred_at, $tz), 'event' => $e->event,
            'member_account' => $e->member_account, 'pc_name' => $e->pc_name,
            'money' => (float) $e->money, 'spend' => (float) $e->spend, 'card' => (float) $e->card, 'bonus' => (float) $e->bonus, 'coin' => (float) $e->coin,
            'used_seconds' => $e->used_seconds !== null ? (int) $e->used_seconds : null,
            'details' => $e->details, 'staff_name' => $e->staff_name, 'package' => $e->package, 'currency' => $cur,
        ])->values()->all();
        $state = DB::table('icafe_billing_sync')->where('club_id', $club->id)->first();
        return self::json(['data' => $data, 'meta' => [
            'count' => count($data), 'next_since' => $data ? end($data)['id'] : $since, 'has_more' => $more,
            'sync' => [
                'live_at' => $state?->live_at ? self::iso($state->live_at, $tz) : null,
                'history_complete' => (bool) ($state?->backfill_done_at),
                'history_from' => $state?->oldest_day,
            ],
        ]]);
    }
}
