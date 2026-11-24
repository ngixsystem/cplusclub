<?php

namespace App\Domain\Knowledge;

/**
 * Video courses (resources/knowledge/<course>.json): YouTube playlists shown through the embedded player.
 * The course id equals the id of its program in tracks.json. A lesson counts as watched when the player
 * reported at least watch_percent of its length played.
 */
final class Lessons
{
    public const COURSES = ['cisco-ccna', 'mikrotik-mtcna'];

    private ?array $courses = null;

    /** @return array<string, array> */
    public function courses(): array
    {
        if ($this->courses === null) {
            $this->courses = [];
            foreach (self::COURSES as $id) {
                $path = resource_path('knowledge/'.$id.'.json');
                if (is_file($path)) {
                    $this->courses[$id] = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                }
            }
        }

        return $this->courses;
    }

    public function course(string $id = 'cisco-ccna'): ?array
    {
        return $this->courses()[$id] ?? null;
    }

    /** The lesson with the id of its course in 'course'. */
    public function find(string $id): ?array
    {
        foreach ($this->courses() as $courseId => $course) {
            foreach ($course['lessons'] as $lesson) {
                if ($lesson['id'] === $id) {
                    return $lesson + ['course' => $courseId];
                }
            }
        }

        return null;
    }

    public function label(array $lesson): string
    {
        return $lesson['code'].' '.$lesson['title'];
    }

    public function watchPercent(): int
    {
        return 90;
    }

    /** Previous and next lessons of the same course, for navigation. */
    public function neighbours(string $id): array
    {
        $lesson = $this->find($id);
        if (! $lesson) {
            return [null, null];
        }
        $lessons = $this->courses()[$lesson['course']]['lessons'];
        $i = array_search($id, array_column($lessons, 'id'), true);

        return [$i > 0 ? $lessons[$i - 1] : null, $i < count($lessons) - 1 ? $lessons[$i + 1] : null];
    }
}
