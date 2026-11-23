<?php

namespace App\Domain\Knowledge;

use App\Models\{QuizAttempt, User};
use Illuminate\Support\Arr;

/**
 * Question bank (resources/knowledge/quizzes.json) and server-side grading.
 * The browser only ever receives question text and shuffled options; the correct option
 * and its explanation are revealed for chapter tests after submission and never for exams.
 */
final class Quizzes
{
    /** A chapter test is passed with at least this share of correct answers. */
    public const CHAPTER_PASS_PERCENT = 80;

    private ?array $bank = null;

    private ?array $byId = null;

    public function bank(): array
    {
        return $this->bank ??= json_decode(file_get_contents(resource_path('knowledge/quizzes.json')), true, 512, JSON_THROW_ON_ERROR)['chapters'];
    }

    public function forChapter(string $chapter): array
    {
        return $this->bank()[$chapter] ?? [];
    }

    public function has(string $chapter): bool
    {
        return (bool) $this->forChapter($chapter);
    }

    public function question(string $id): ?array
    {
        if ($this->byId === null) {
            $this->byId = [];
            foreach ($this->bank() as $chapter => $questions) {
                foreach ($questions as $q) {
                    $this->byId[$q['id']] = $q + ['chapter' => $chapter];
                }
            }
        }

        return $this->byId[$id] ?? null;
    }

    /** Starts an attempt over the given question ids; options are shuffled per attempt. */
    public function start(User $user, string $kind, string $subject, array $ids, int $pointsPerAnswer = 1): QuizAttempt
    {
        $questions = array_map(function (string $id) {
            $q = $this->question($id);
            $perm = array_keys($q['options']);
            shuffle($perm);

            return ['id' => $id, 'chapter' => $q['chapter'], 'perm' => $perm];
        }, $ids);

        return QuizAttempt::create([
            'user_id' => $user->id, 'kind' => $kind, 'subject' => $subject, 'questions' => $questions,
            'total' => count($questions), 'max_points' => count($questions) * $pointsPerAnswer, 'started_at' => now(),
        ]);
    }

    /** Question text and options in the order this attempt shows them — no answers. */
    public function present(QuizAttempt $attempt): array
    {
        return array_map(function (array $item) {
            $q = $this->question($item['id']);

            return ['id' => $item['id'], 'q' => $q['q'], 'options' => array_map(fn ($i) => $q['options'][$i], $item['perm'])];
        }, $attempt->questions);
    }

    /**
     * Grades an unfinished attempt. $answers maps question id → shown option index; missing answers are wrong.
     * Returns per-question review data (chosen, correct shown index, explanation).
     */
    public function grade(QuizAttempt $attempt, array $answers, int $passPercent, int $pointsPerAnswer = 1): array
    {
        $review = $this->review($attempt, $answers);
        $correct = count(array_filter($review, fn ($r) => $r['ok']));
        $percent = $attempt->total ? (int) floor($correct * 100 / $attempt->total) : 0;
        $attempt->forceFill([
            'answers' => array_column($review, 'chosen', 'id'), 'correct' => $correct, 'points' => $correct * $pointsPerAnswer,
            'percent' => $percent, 'passed' => $percent >= $passPercent, 'finished_at' => now(),
        ])->save();

        return $review;
    }

    /** Per-question comparison of answers with the key, without saving anything. */
    public function review(QuizAttempt $attempt, array $answers): array
    {
        $review = [];
        foreach ($attempt->questions as $item) {
            $q = $this->question($item['id']);
            $chosen = Arr::get($answers, $item['id']);
            $chosen = is_numeric($chosen) && (int) $chosen >= 0 && (int) $chosen < count($item['perm']) ? (int) $chosen : null;
            $right = array_search($q['answer'], $item['perm'], true);
            $review[] = ['id' => $item['id'], 'chapter' => $item['chapter'], 'chosen' => $chosen, 'correct' => $right, 'ok' => $chosen !== null && $chosen === $right, 'explain' => $q['explain']];
        }

        return $review;
    }
}
