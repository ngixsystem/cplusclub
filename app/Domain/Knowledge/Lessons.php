<?php

namespace App\Domain\Knowledge;

/**
 * Video lessons (resources/knowledge/cisco-ccna.json): a YouTube playlist shown through the embedded player.
 * A lesson counts as watched when the player reported at least watch_percent of its length played.
 */
final class Lessons
{
    private ?array $course = null;

    public function course(): array
    {
        return $this->course ??= json_decode(file_get_contents(resource_path('knowledge/cisco-ccna.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function find(string $id): ?array
    {
        return collect($this->course()['lessons'])->firstWhere('id', $id);
    }

    public function label(array $lesson): string
    {
        return $lesson['code'].' '.$lesson['title'];
    }

    /** Previous and next lessons for navigation. */
    public function neighbours(string $id): array
    {
        $lessons = $this->course()['lessons'];
        $i = array_search($id, array_column($lessons, 'id'), true);

        return [$i > 0 ? $lessons[$i - 1] : null, $i !== false && $i < count($lessons) - 1 ? $lessons[$i + 1] : null];
    }
}
