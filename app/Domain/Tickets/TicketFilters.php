<?php

namespace App\Domain\Tickets;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Filters for the tickets list. Everything comes from the query string so a
 * filtered view can be bookmarked or sent to a colleague. Unknown values are
 * ignored, never trusted. Club access is applied by the caller beforehand.
 */
final class TicketFilters
{
    /** Status groups shown as tabs. */
    public const TABS = [
        'all' => null,
        'open' => ['new', 'accepted', 'working'],
        'waiting' => ['approval', 'parts'],
        'resolved' => ['resolved'],
        'closed' => ['closed'],
    ];

    public const PRIORITIES = ['critical', 'high', 'normal', 'low'];

    public const SORTS = ['new', 'urgent', 'oldest'];

    /** @return array{tab:string,priority:list<string>,club:?int,assignee:int|string|null,category:?string,period:?int,search:string,sort:string} */
    public static function read(Request $request): array
    {
        $tab = (string) $request->query('tab', 'all');
        $assignee = $request->query('assignee');
        if (! in_array($assignee, ['me', 'none'], true)) {
            $assignee = filter_var($assignee, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        }
        $sort = (string) $request->query('sort', 'new');

        return [
            'tab' => array_key_exists($tab, self::TABS) ? $tab : 'all',
            'priority' => array_values(array_intersect(self::PRIORITIES, explode(',', (string) $request->query('priority', '')))),
            'club' => filter_var($request->query('club'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null,
            'assignee' => $assignee,
            'category' => mb_substr(trim((string) $request->query('category', '')), 0, 80) ?: null,
            'period' => in_array((string) $request->query('period'), ['7', '30', '90'], true) ? (int) $request->query('period') : null,
            'search' => mb_substr(trim((string) $request->query('search', '')), 0, 100),
            'sort' => in_array($sort, self::SORTS, true) ? $sort : 'new',
        ];
    }

    /** Every filter except the status tab (tab counters are built on this). */
    public static function apply(Builder $query, array $f, User $user): Builder
    {
        return $query
            ->when($f['priority'], fn ($q) => $q->whereIn('priority', $f['priority']))
            ->when($f['club'], fn ($q) => $q->where('club_id', $f['club']))
            ->when($f['assignee'] === 'me', fn ($q) => $q->where('assignee_id', $user->id))
            ->when($f['assignee'] === 'none', fn ($q) => $q->whereNull('assignee_id'))
            ->when(is_int($f['assignee']), fn ($q) => $q->where('assignee_id', $f['assignee']))
            ->when($f['category'], fn ($q) => $q->where('category', $f['category']))
            ->when($f['period'], fn ($q) => $q->where('created_at', '>=', now()->subDays($f['period'])))
            ->when($f['search'] !== '', function ($q) use ($f) {
                $term = $f['search'];
                $like = '%'.addcslashes($term, '%_\\').'%';
                $number = preg_match('/^#?(\d{1,9})$/', $term, $m) ? (int) $m[1] : null;
                $q->where(function ($w) use ($like, $number) {
                    $w->where('description', 'ilike', $like)
                        ->orWhereHas('equipment', fn ($e) => $e->where('name', 'ilike', $like));
                    if ($number !== null) $w->orWhere('id', $number);
                });
            });
    }

    public static function tab(Builder $query, string $tab): Builder
    {
        $statuses = self::TABS[$tab] ?? null;
        return $statuses ? $query->whereIn('status', $statuses) : $query;
    }

    public static function sort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'urgent' => $query->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
                ->orderBy('created_at')->orderBy('id'),
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            default => $query->orderByDesc('id'),
        };
    }

    /** @return array<string,int> counts per tab for the current non-tab filters */
    public static function counts(Builder $filtered): array
    {
        $byStatus = (clone $filtered)->reorder()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $counts = [];
        foreach (self::TABS as $tab => $statuses) {
            $counts[$tab] = (int) ($statuses ? collect($statuses)->sum(fn ($s) => $byStatus[$s] ?? 0) : $byStatus->sum());
        }
        return $counts;
    }
}
