<?php

namespace App\Domain\Knowledge;

use App\Models\KnowledgeProgress;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Role-based training tracks built from knowledge-base chapters.
 * Tracks are editorial content (resources/knowledge/tracks.json); progress lives in knowledge_progress.
 */
final class Training
{
    /** Roles whose progress a reviewer may see. Leads manage engineers; the owner also sees club staff. */
    private const TEAM_SCOPE = [
        'owner' => ['lead', 'specialist', 'representative'],
        'lead' => ['lead', 'specialist'],
    ];

    private ?array $tracks = null;

    public function __construct(private readonly Library $library) {}

    public function tracks(): array
    {
        return $this->tracks ??= json_decode(file_get_contents(resource_path('knowledge/tracks.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function tracksFor(string $role): array
    {
        return array_values(array_filter($this->tracks(), fn ($t) => in_array($role, $t['roles'], true)));
    }

    /** Checklist indices the user confirmed for one chapter, filtered to the current checklist length. */
    public function checkedFor(User $user, Article $article): array
    {
        $row = KnowledgeProgress::where('user_id', $user->id)->where('article_id', $article->data['id'])->first();

        return $row ? $this->normalize($row->checked, count($article->data['checklist'] ?? [])) : [];
    }

    public function record(User $user, Article $article, array $checked): KnowledgeProgress|null
    {
        $total = count($article->data['checklist'] ?? []);
        $checked = $this->normalize($checked, $total);
        $query = KnowledgeProgress::where('user_id', $user->id)->where('article_id', $article->data['id']);
        if (!$checked) {
            $query->delete();

            return null;
        }
        $row = $query->first() ?? new KnowledgeProgress(['user_id' => $user->id, 'article_id' => $article->data['id']]);
        $row->checked = $checked;
        $row->completed_at = count($checked) === $total ? ($row->completed_at ?? now()) : null;
        $row->save();

        return $row;
    }

    /** Tracks for the user's role with per-chapter state and the next chapter to read. */
    public function summary(User $user): array
    {
        $progress = KnowledgeProgress::where('user_id', $user->id)->get()->keyBy('article_id');

        return array_map(fn ($track) => $this->trackState($track, $progress), $this->tracksFor($user->role));
    }

    /** Progress of the people the reviewer is responsible for. Empty for roles without a team scope. */
    public function team(User $reviewer): array
    {
        $roles = self::TEAM_SCOPE[$reviewer->role] ?? [];
        if (!$roles) {
            return [];
        }
        $users = User::where('active', true)->whereIn('role', $roles)->orderBy('name')->get(['id', 'name', 'role']);
        $progress = KnowledgeProgress::whereIn('user_id', $users->pluck('id'))->get()->groupBy('user_id');

        return $users->map(function (User $user) use ($progress) {
            $rows = ($progress[$user->id] ?? collect())->keyBy('article_id');
            $tracks = array_map(function ($track) use ($rows) {
                $state = $this->trackState($track, $rows);

                return ['id' => $state['id'], 'title' => $state['title'], 'done' => $state['done'], 'total' => $state['total']];
            }, array_values(array_filter($this->tracks(), fn ($t) => in_array($user->role, $t['roles'], true))));

            return [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'tracks' => $tracks,
                'last_activity' => $rows->max('updated_at')?->toIso8601String(),
            ];
        })->values()->all();
    }

    private function trackState(array $track, Collection $progress): array
    {
        $chapters = [];
        foreach ($track['chapters'] as $id) {
            $article = $this->library->find($id);
            if (!$article || $article->data['kind'] !== 'guide') {
                continue;
            }
            $total = count($article->data['checklist'] ?? []);
            $row = $progress[$id] ?? null;
            $checked = $row ? count($this->normalize($row->checked, $total)) : 0;
            $chapters[] = [
                'id' => $id,
                'title' => $article->data['title'],
                'minutes' => $article->data['minutes'] ?? null,
                'checked' => $checked,
                'total' => $total,
                'done' => $total > 0 && $checked === $total,
                'completed_at' => $row?->completed_at?->toIso8601String(),
            ];
        }
        $next = collect($chapters)->first(fn ($c) => !$c['done']);

        return [
            'id' => $track['id'],
            'title' => $track['title'],
            'summary' => $track['summary'],
            'chapters' => $chapters,
            'done' => count(array_filter($chapters, fn ($c) => $c['done'])),
            'total' => count($chapters),
            'next' => $next ? ['id' => $next['id'], 'title' => $next['title']] : null,
        ];
    }

    private function normalize(mixed $checked, int $total): array
    {
        $values = array_filter(is_array($checked) ? $checked : [], fn ($i) => is_int($i) && $i >= 0 && $i < $total);
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
