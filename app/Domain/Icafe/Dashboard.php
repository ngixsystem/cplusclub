<?php

namespace App\Domain\Icafe;

use App\Models\Club;
use Illuminate\Support\Facades\Cache;

final class Dashboard
{
    public function __construct(private Client $client) {}

    public function key(Club $club): string
    {
        return 'icafe:'.$club->id.':'.hash('sha256', $club->icafe_license.'|'.$club->icafe_token.'|'.$club->timezone);
    }

    public function snapshot(Club $club): array
    {
        return $this->cached($this->key($club).':snapshot', function () use ($club) {
            $get = fn ($path, $query = []) => $this->client->get($club->icafe_license, $club->icafe_token, $path, $query);
            $now = now($club->timezone);
            $rows = $get('reports/shiftList', ['date_start' => $now->copy()->subDays(6)->toDateString(),
                'date_end' => $now->toDateString(), 'time_start' => '00:00', 'time_end' => '23:59', 'shift_staff_name' => 'all']);
            $shifts = array_map(function ($r) use ($club, $now) {
                foreach (['shift_id','shift_staff_name','shift_start_time','shift_end_time','total_amount','cash','credit_card','qr'] as $field) {
                    if (! array_key_exists($field, $r)) throw new \RuntimeException('Unexpected shift schema');
                }
                return ['id' => (string) $r['shift_id'], 'operator' => $r['shift_staff_name'], 'start' => $r['shift_start_time'],
                    'end' => $r['shift_end_time'] === '-' ? null : $r['shift_end_time'],
                    'duration_seconds' => max(0, (int) \Carbon\Carbon::parse($r['shift_start_time'], $club->timezone)->diffInSeconds(
                        $r['shift_end_time'] === '-' ? $now : \Carbon\Carbon::parse($r['shift_end_time'], $club->timezone))),
                    'total' => (float) $r['total_amount'], 'cash' => (float) $r['cash'],
                    'card' => (float) $r['credit_card'], 'qr' => (float) $r['qr']];
            }, $rows);
            $pcs = array_values(array_filter($get('pcs'), fn ($pc) => (int) ($pc['pc_icafe_id'] ?? 0) === (int) $club->icafe_license && (int) ($pc['pc_console_type'] ?? -1) === 0));
            $online = collect($get('onlinePcList'))->filter(fn ($p) => (int) ($p['is_connected'] ?? 0) === 1)->pluck('pc_name')->all();
            $computers = array_map(fn ($p) => ['name' => $p['pc_name'], 'online' => in_array($p['pc_name'], $online, true), 'busy' => (bool) $p['pc_in_using']], $pcs);
            return ['shifts' => $shifts, 'computers' => $computers, 'total_pcs' => count($computers),
                'online_pcs' => count(array_filter($computers, fn ($p) => $p['online'])),
                'period_start' => $now->copy()->subDays(6)->toDateString(), 'period_end' => $now->toDateString()];
        });
    }

    public function detail(Club $club, array $shift): array
    {
        return $this->cached($this->key($club).':shift:'.$shift['id'], function () use ($club, $shift) {
            $r = $this->client->get($club->icafe_license, $club->icafe_token, 'reports/shiftDetail/'.$shift['id']);
            $fields = ['cash_sales','cash_refund','center_expenses','pc_cash_amount','console_cash_amount','shop_cash_amount',
                'pc_card_amount','console_card_amount','shop_card_amount','pc_qr_amount','console_qr_amount','shop_qr_amount'];
            $detail = [];
            foreach ($fields as $field) $detail[$field] = isset($r[$field]) && is_numeric($r[$field]) ? (float) $r[$field] : null;
            $end = $shift['end'] ?? now($club->timezone)->format('Y-m-d H:i:s');
            $chart = $this->client->get($club->icafe_license, $club->icafe_token, 'reports/reportChart', [
                'date_start' => substr($shift['start'], 0, 10), 'date_end' => substr($end, 0, 10),
                'time_start' => substr($shift['start'], 11, 5), 'time_end' => substr($end, 11, 5),
                'log_staff_name' => $shift['operator'], 'chart_type' => 'income', 'data_source' => 'recent',
            ]);
            // Total is the upstream aggregate, not the sum of overlapping Balance/Profit/Cash series.
            $total = collect($chart['series'] ?? [])->firstWhere('name', 'Total');
            $labels = $chart['categories'] ?? [];
            // API omits the axis label for a partial final hour, but returns its value.
            if (isset($total['data']) && substr($shift['start'], 0, 10) === substr($end, 0, 10)
                && count($total['data']) === count($labels) + 1
                && (count($labels) === 0 || (preg_match('/^\d{2}$/', (string) end($labels)) && (int) end($labels) + 1 === (int) substr($end, 11, 2)))) {
                $labels[] = substr($end, 11, 2);
            }
            if (! isset($total['data']) || count($labels) !== count($total['data'])) {
                throw new \RuntimeException('Unexpected chart schema');
            }
            return ['detail' => $detail, 'chart' => ['labels' => $labels, 'values' => $total['data']]];
        });
    }

    private function cached(string $key, callable $fetch): array
    {
        $previous = Cache::get($key);
        if ($previous && time() - $previous['checked_at'] < 30) return $previous;
        $lock = Cache::lock($key.':lock', 65);
        if (! $lock->get()) return $previous ? [...$previous, 'stale' => true] : ['stale' => true, 'error' => 'Загрузка данных...', 'updated_at' => null];
        try {
            $data = [...$fetch(), 'updated_at' => now()->toIso8601String(), 'checked_at' => time(), 'stale' => false, 'error' => null];
        } catch (\Throwable) {
            $data = [...($previous ?? []), 'checked_at' => time(), 'stale' => true,
                'error' => 'Не удалось обновить данные iCafeCloud. Повторная попытка выполняется автоматически.', 'updated_at' => $previous['updated_at'] ?? null];
        } finally {
            $lock->release();
        }
        Cache::put($key, $data, now()->addDays(2));
        return $data;
    }
}
