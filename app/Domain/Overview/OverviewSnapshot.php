<?php

namespace App\Domain\Overview;

use App\Domain\Access\ClubAccess;
use App\Models\{Club, Equipment, Ticket, User};
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates for the overview page. Every query is limited to the clubs the
 * user may see (plus tickets assigned to a specialist), never to all data.
 * Calendar buckets use the service timezone, events are stored in UTC.
 */
final class OverviewSnapshot
{
    public const TIMEZONE = 'Asia/Tashkent';
    private const OPEN = ['new', 'accepted', 'working', 'approval', 'parts'];
    private const MONTHS = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

    public function __construct(private User $user) {}

    private function tickets(): Builder
    {
        $user = $this->user;
        return Ticket::query()->where(function ($q) use ($user) {
            ClubAccess::filter($q, $user);
            if ($user->role === 'specialist') $q->orWhere('assignee_id', $user->id);
        });
    }

    private function clubIds()
    {
        return ClubAccess::filter(Club::query(), $this->user, 'id')->select('id');
    }

    private static function change(int|float $now, int|float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }

    public function toArray(): array
    {
        $tz = self::TIMEZONE;
        $now = CarbonImmutable::now($tz);
        $d30 = $now->subDays(30)->utc(); $d60 = $now->subDays(60)->utc();

        // KPI cards
        $open = (clone $this->tickets())->whereIn('status', self::OPEN)->count();
        $created30 = (clone $this->tickets())->where('created_at', '>=', $d30)->count();
        $createdPrev = (clone $this->tickets())->whereBetween('created_at', [$d60, $d30])->count();
        $closed30 = (clone $this->tickets())->where('status', 'closed')->where('closed_at', '>=', $d30)->count();
        $closedPrev = (clone $this->tickets())->where('status', 'closed')->whereBetween('closed_at', [$d60, $d30])->count();

        // Tickets by month of creation, split by their current state (7 months incl. current).
        $start = $now->startOfMonth()->subMonths(6);
        $rows = (clone $this->tickets())->where('created_at', '>=', $start->utc())
            ->selectRaw("to_char(created_at AT TIME ZONE ?, 'YYYY-MM') AS month", [$tz])
            ->selectRaw("count(*) FILTER (WHERE status = 'closed') AS closed")
            ->selectRaw("count(*) FILTER (WHERE status IN ('accepted','working','approval','parts','resolved')) AS progress")
            ->selectRaw("count(*) FILTER (WHERE status = 'new') AS fresh")
            ->groupBy('month')->get()->keyBy('month');
        $monthly = ['labels' => [], 'closed' => [], 'progress' => [], 'new' => []];
        for ($i = 0; $i < 7; $i++) {
            $m = $start->addMonths($i);
            $row = $rows->get($m->format('Y-m'));
            $monthly['labels'][] = self::MONTHS[$m->month - 1];
            $monthly['closed'][] = (int) ($row->closed ?? 0);
            $monthly['progress'][] = (int) ($row->progress ?? 0);
            $monthly['new'][] = (int) ($row->fresh ?? 0);
        }

        // Daily series for the last 14 days.
        $daily = function (string $column, ?string $status = null) use ($now, $tz) {
            $from = $now->startOfDay()->subDays(13);
            $q = (clone $this->tickets())->where($column, '>=', $from->utc());
            if ($status) $q->where('status', $status);
            $counts = $q->selectRaw("to_char($column AT TIME ZONE ?, 'YYYY-MM-DD') AS day, count(*) AS n", [$tz])
                ->groupBy('day')->pluck('n', 'day');
            $series = [];
            for ($i = 0; $i < 14; $i++) $series[] = (int) ($counts[$from->addDays($i)->toDateString()] ?? 0);
            return $series;
        };
        $createdDaily = $daily('created_at');
        $closedDaily = $daily('closed_at', 'closed');
        $createdPrev14 = (clone $this->tickets())->whereBetween('created_at', [$now->startOfDay()->subDays(27)->utc(), $now->startOfDay()->subDays(13)->utc()])->count();

        // Work and costs over 30 days.
        $ticketIds = (clone $this->tickets())->select('id');
        $work = DB::table('work_logs')->whereIn('ticket_id', $ticketIds)->where('created_at', '>=', $d30);
        $minutes = (int) (clone $work)->sum('minutes');
        $costs = (clone $work)->select('currency')->selectRaw('SUM(cost_minor) AS cost_minor')->groupBy('currency')
            ->orderByDesc('cost_minor')->get()->map(fn ($r) => ['currency' => $r->currency, 'cost_minor' => (int) $r->cost_minor])->values();
        $response = (clone $this->tickets())->whereNotNull('first_responded_at')->where('created_at', '>=', $d30)
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (first_responded_at - created_at)) / 60) AS minutes')->value('minutes');

        // Inspection schedule of PCs.
        $today = $now->toDateString(); $soon = $now->addDays(7)->toDateString();
        $pcs = ClubAccess::filter(Equipment::query(), $this->user)->where('type', 'pc')->where('status', '<>', 'retired');
        $inspections = [
            'overdue' => (clone $pcs)->whereNotNull('next_inspection_date')->where('next_inspection_date', '<', $today)->count(),
            'soon' => (clone $pcs)->whereBetween('next_inspection_date', [$today, $soon])->count(),
            'planned' => (clone $pcs)->where('next_inspection_date', '>', $soon)->count(),
            'unscheduled' => (clone $pcs)->whereNull('next_inspection_date')->count(),
        ];

        // Attention lists.
        $priorityOrder = "CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END";
        $urgent = (clone $this->tickets())->whereIn('status', self::OPEN)->with(['club:id,name', 'equipment:id,name'])
            ->orderByRaw($priorityOrder)->orderBy('created_at')->limit(5)
            ->get(['id', 'club_id', 'equipment_id', 'description', 'status', 'priority', 'created_at'])
            ->map(fn ($t) => ['id' => $t->id, 'description' => $t->description, 'status' => $t->status, 'priority' => $t->priority,
                'club' => $t->club?->name, 'equipment' => $t->equipment?->name, 'created_at' => $t->created_at?->toIso8601String()]);

        $alerts = DB::table('alerts')->join('equipment', 'equipment.id', '=', 'alerts.equipment_id')
            ->join('clubs', 'clubs.id', '=', 'alerts.club_id')
            ->where('alerts.state', 'active')->whereIn('alerts.club_id', $this->clubIds())
            ->orderByDesc('alerts.opened_at')->limit(5)
            ->get(['alerts.id', 'alerts.equipment_id', 'alerts.metric', 'alerts.opened_at', 'equipment.name as equipment', 'clubs.name as club']);
        $alertCount = DB::table('alerts')->where('state', 'active')->whereIn('club_id', $this->clubIds())->count();
        $monitored = ClubAccess::filter(Equipment::query(), $this->user)->whereNotNull('last_seen_at');
        $stale = (clone $monitored)->where('last_seen_at', '<', now()->subMinutes(10))->count();

        $published = DB::table('published_versions')
            ->whereIn('app_id', DB::table('game_subscriptions')->whereIn('club_id', $this->clubIds())->select('app_id'))
            ->orderBy('app_id')->get(['app_id', 'build_id', 'source', 'source_status', 'checked_at', 'published_at']);

        return [
            'generated_at' => $now->toIso8601String(),
            'kpis' => [
                'open' => $open, 'created_30' => $created30, 'created_change' => self::change($created30, $createdPrev),
                'closed_30' => $closed30, 'closed_change' => self::change($closed30, $closedPrev),
            ],
            'monthly' => $monthly,
            'work' => ['hours_30' => round($minutes / 60, 1), 'costs' => $costs, 'first_response_minutes' => $response !== null ? (int) round($response) : null],
            'created_daily' => $createdDaily, 'created_14' => array_sum($createdDaily),
            'created_14_change' => self::change(array_sum($createdDaily), $createdPrev14),
            'closed_daily' => $closedDaily, 'closed_14' => array_sum($closedDaily),
            'inspections' => $inspections,
            'urgent' => $urgent,
            'alerts' => $alerts, 'alert_count' => $alertCount,
            'devices' => ['monitored' => (clone $monitored)->count(), 'stale' => $stale],
            'published' => $published,
        ];
    }
}
