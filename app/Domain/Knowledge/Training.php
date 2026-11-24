<?php

namespace App\Domain\Knowledge;

use App\Models\{KnowledgeProgress, QuizAttempt, TrainingEnrollment, User};
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Role-based training programs built from knowledge-base chapters.
 *
 * A chapter is studied when its "check the result" list is fully confirmed AND its test is passed.
 * A program starts when the employee reads the intro and presses "start" (training_enrollments.started_at)
 * and ends when the final exam is passed — or, for a program without an exam, when every chapter is studied.
 * Programs are editorial content (resources/knowledge/tracks.json), questions live in quizzes.json.
 */
final class Training
{
    /** Roles whose progress a reviewer may see. Leads manage engineers; the owner also sees club staff. */
    private const TEAM_SCOPE = [
        'owner' => ['lead', 'specialist', 'representative'],
        'lead' => ['lead', 'specialist'],
    ];

    private ?array $tracks = null;

    public function __construct(private readonly Library $library, private readonly Quizzes $quizzes, private readonly Lessons $lessons) {}

    public function tracks(): array
    {
        return $this->tracks ??= json_decode(file_get_contents(resource_path('knowledge/tracks.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function tracksFor(string $role): array
    {
        return array_values(array_filter($this->tracks(), fn ($t) => in_array($role, $t['roles'], true)));
    }

    public function trackFor(User $user, string $id): ?array
    {
        return collect($this->tracksFor($user->role))->firstWhere('id', $id);
    }

    // ---- Chapter checklist -------------------------------------------------------------------

    /** Checklist indices the user confirmed for one chapter, filtered to the current checklist length. */
    public function checkedFor(User $user, Article $article): array
    {
        $row = KnowledgeProgress::where('user_id', $user->id)->where('article_id', $article->data['id'])->first();

        return $row ? $this->normalize($row->checked, count($article->data['checklist'] ?? [])) : [];
    }

    public function record(User $user, Article $article, array $checked): ?KnowledgeProgress
    {
        $total = count($article->data['checklist'] ?? []);
        $checked = $this->normalize($checked, $total);
        $row = $this->progressRow($user, $article->data['id']);
        $row->checked = $checked;
        $row->completed_at = $checked && count($checked) === $total ? ($row->completed_at ?? now()) : null;
        if (!$checked && !$row->quiz_best_percent && !$row->quiz_passed_at && !$row->watch_seconds) {
            if ($row->exists) {
                $row->delete();
            }
            $this->syncCompletion($user);

            return null;
        }
        $row->save();
        $this->syncCompletion($user);

        return $row;
    }

    // ---- Chapter test ------------------------------------------------------------------------

    /** Test state shown on the chapter page. */
    public function quizState(User $user, Article $article): ?array
    {
        return $this->quizStateFor($user, $article->data['id']);
    }

    /** Test state of a chapter or video lesson. */
    public function quizStateFor(User $user, string $id): ?array
    {
        if (!$this->quizzes->has($id)) {
            return null;
        }
        $row = KnowledgeProgress::where('user_id', $user->id)->where('article_id', $id)->first();

        return [
            'questions' => count($this->quizzes->forChapter($id)),
            'pass_percent' => Quizzes::CHAPTER_PASS_PERCENT,
            'best_percent' => $row?->quiz_best_percent,
            'passed' => (bool) $row?->quiz_passed_at,
            'attempts' => QuizAttempt::where('user_id', $user->id)->where('kind', 'chapter')->where('subject', $id)->whereNotNull('finished_at')->count(),
        ];
    }

    public function startChapterQuiz(User $user, Article $article): QuizAttempt
    {
        return $this->startQuiz($user, $article->data['id']);
    }

    public function startQuiz(User $user, string $id): QuizAttempt
    {
        $ids = array_column($this->quizzes->forChapter($id), 'id');
        abort_unless($ids, 404);

        return $this->quizzes->start($user, 'chapter', $id, $ids);
    }

    // ---- Video lessons -----------------------------------------------------------------------------

    /**
     * Credits watched time from a player position report. Only forward progress since the previous report
     * is credited, at most twice the wall-clock time between reports (2× playback speed): seeking ahead
     * or a burst of fake reports does not count. A pause longer than 90 s restarts the baseline.
     */
    public function watch(User $user, array $lesson, float $position, float $duration): KnowledgeProgress
    {
        $row = $this->progressRow($user, $lesson['id']);
        $duration = (int) max(30, min(14400, round($duration)));
        $position = (int) max(0, min($duration, floor($position)));
        $now = now();
        $credit = 0;
        if ($row->watch_ping_at && $row->watch_position !== null) {
            $wall = (int) floor($row->watch_ping_at->diffInSeconds($now));
            $advance = $position - $row->watch_position;
            if ($wall <= 90 && $advance > 0) {
                $credit = (int) min($advance, $wall * 2 + 2, 40);
            }
        }
        $row->watch_seconds = min($duration, (int) $row->watch_seconds + $credit);
        $row->watch_duration = $duration;
        $row->watch_position = $position;
        $row->watch_ping_at = $now;
        if (!$row->watched_at && $row->watch_seconds >= $duration * $this->watchShare()) {
            $row->watched_at = $now;
        }
        $row->save();
        $this->syncCompletion($user);

        return $row;
    }

    public function watchState(?KnowledgeProgress $row): array
    {
        $duration = $row?->watch_duration;

        return [
            'percent' => $duration ? (int) min(100, floor($row->watch_seconds * 100 / $duration)) : 0,
            'watched' => (bool) $row?->watched_at,
            'required_percent' => (int) round($this->watchShare() * 100),
        ];
    }

    public function lessonProgress(User $user, string $id): ?KnowledgeProgress
    {
        return KnowledgeProgress::where('user_id', $user->id)->where('article_id', $id)->first();
    }

    private function watchShare(): float
    {
        return ($this->lessons->course()['watch_percent'] ?? 90) / 100;
    }

    public function submitChapterQuiz(User $user, QuizAttempt $attempt, array $answers): array
    {
        $review = $this->quizzes->grade($attempt, $answers, Quizzes::CHAPTER_PASS_PERCENT);
        $row = $this->progressRow($user, $attempt->subject);
        $row->checked ??= [];
        $row->quiz_best_percent = max((int) $row->quiz_best_percent, $attempt->percent);
        if ($attempt->passed && !$row->quiz_passed_at) {
            $row->quiz_passed_at = now();
        }
        $row->save();
        $this->syncCompletion($user);

        return [
            'correct' => $attempt->correct, 'total' => $attempt->total, 'percent' => $attempt->percent,
            'passed' => $attempt->passed, 'pass_percent' => Quizzes::CHAPTER_PASS_PERCENT, 'review' => $review,
            'quiz' => $this->quizStateFor($user, $attempt->subject),
        ];
    }

    // ---- Program enrollment and exam ------------------------------------------------------------

    public function enroll(User $user, string $trackId): TrainingEnrollment
    {
        abort_unless($this->trackFor($user, $trackId), 404);
        $enrollment = TrainingEnrollment::firstOrCreate(['user_id' => $user->id, 'track_id' => $trackId], ['started_at' => now()]);
        $this->syncCompletion($user);

        return $enrollment;
    }

    /** Starts (or resumes) the final exam once every chapter of the program is studied. */
    public function startExam(User $user, string $trackId): QuizAttempt
    {
        $track = $this->trackFor($user, $trackId);
        abort_unless($track && isset($track['exam']), 404);
        $state = $this->trackState($track, $this->progressFor($user), $this->enrollmentsFor($user)->get($trackId), $this->examsFor($user)->get($trackId, collect()));
        $exam = $state['exam'];
        if ($exam['in_progress_id']) {
            return QuizAttempt::findOrFail($exam['in_progress_id']);
        }
        if (!$exam['available']) {
            throw ValidationException::withMessages(['exam' => $exam['locked_reason']]);
        }

        return $this->quizzes->start($user, 'exam', $trackId, $this->examQuestions($track), $track['exam']['points_per_answer']);
    }

    public function submitExam(User $user, QuizAttempt $attempt, array $answers): array
    {
        $track = $this->trackFor($user, $attempt->subject);
        abort_unless($track && isset($track['exam']), 404);
        $config = $track['exam'];
        $this->quizzes->grade($attempt, $answers, $config['pass_percent'], $config['points_per_answer']);
        if ($attempt->passed) {
            TrainingEnrollment::where('user_id', $user->id)->where('track_id', $track['id'])->whereNull('completed_at')
                ->update(['completed_at' => $attempt->finished_at]);
        }

        return $this->examResult($attempt, $track);
    }

    /** Result of a finished exam. Exams never reveal the right answers — only which chapters to revisit. */
    public function examResult(QuizAttempt $attempt, array $track): array
    {
        $config = $track['exam'];
        $review = $this->quizzes->review($attempt, $attempt->answers ?? []);
        $byChapter = collect($review)->groupBy('chapter')->map(fn ($rows, $id) => [
            'id' => $id, 'title' => $this->titleOf($id),
            'correct' => $rows->where('ok', true)->count(), 'total' => $rows->count(),
        ])->sortBy(fn ($c) => $c['correct'] / max(1, $c['total']))->values()->all();

        return [
            'points' => $attempt->points, 'max_points' => $attempt->max_points, 'correct' => $attempt->correct,
            'total' => $attempt->total, 'percent' => $attempt->percent, 'passed' => $attempt->passed,
            'pass_percent' => $config['pass_percent'], 'duration_seconds' => (int) $attempt->started_at->diffInSeconds($attempt->finished_at),
            'chapters' => $byChapter,
        ];
    }

    /** One question from every chapter (when there is room), the rest drawn from the whole program. */
    private function examQuestions(array $track): array
    {
        $count = $track['exam']['questions'];
        $pools = collect($track['chapters'])->mapWithKeys(fn ($id) => [$id => collect($this->quizzes->forChapter($id))->pluck('id')->shuffle()])->filter->isNotEmpty();
        $picked = $pools->keys()->shuffle()->take($count)->map(fn ($id) => $pools[$id]->shift());
        $rest = $pools->flatten()->shuffle()->take(max(0, $count - $picked->count()));

        return $picked->concat($rest)->shuffle()->values()->all();
    }

    /** Data for the printable certificate; null while the program is not completed. */
    public function certificate(User $user, string $trackId): ?array
    {
        $track = $this->trackFor($user, $trackId);
        $state = $track ? collect($this->summary($user))->firstWhere('id', $trackId) : null;
        if (!$state || !($state['enrollment']['completed_at'] ?? null)) {
            return null;
        }
        $completed = $state['enrollment']['completed_at'];

        return [
            'number' => 'CPC-'.strtoupper(substr(hash_hmac('sha256', $user->id.'|'.$trackId.'|'.$completed, (string) config('app.key')), 0, 10)),
            'name' => $user->name, 'track' => $state['title'], 'kind' => $state['kind'],
            'chapters' => $state['total'], 'started_at' => $state['enrollment']['started_at'], 'completed_at' => $completed,
            'duration_seconds' => $state['enrollment']['duration_seconds'],
            'exam' => $state['exam']['best'] ?? null,
            'source' => ($track['kind'] ?? null) === 'video' ? $this->lessons->course()['author'] : null,
        ];
    }

    // ---- Summaries ---------------------------------------------------------------------------------

    /** Programs for the user's role with per-chapter state, enrollment, timing and exam. */
    public function summary(User $user): array
    {
        $progress = $this->progressFor($user);
        $enrollments = $this->enrollmentsFor($user);
        $exams = $this->examsFor($user);

        return array_map(fn ($track) => $this->trackState($track, $progress, $enrollments->get($track['id']), $exams->get($track['id'], collect())), $this->tracksFor($user->role));
    }

    /** Progress of the people the reviewer is responsible for. Empty for roles without a team scope. */
    public function team(User $reviewer): array
    {
        $roles = self::TEAM_SCOPE[$reviewer->role] ?? [];
        if (!$roles) {
            return [];
        }
        $users = User::where('active', true)->whereIn('role', $roles)->orderBy('name')->get(['id', 'name', 'role']);
        $ids = $users->pluck('id');
        $progress = KnowledgeProgress::whereIn('user_id', $ids)->get()->groupBy('user_id');
        $enrollments = TrainingEnrollment::whereIn('user_id', $ids)->get()->groupBy('user_id');
        $exams = QuizAttempt::whereIn('user_id', $ids)->where('kind', 'exam')->get()->groupBy('user_id');

        return $users->map(function (User $user) use ($progress, $enrollments, $exams) {
            $rows = ($progress[$user->id] ?? collect())->keyBy('article_id');
            $userEnrollments = ($enrollments[$user->id] ?? collect())->keyBy('track_id');
            $userExams = ($exams[$user->id] ?? collect())->groupBy('subject');
            $tracks = array_map(function ($track) use ($rows, $userEnrollments, $userExams) {
                $s = $this->trackState($track, $rows, $userEnrollments->get($track['id']), $userExams->get($track['id'], collect()));
                $quizzes = collect($s['chapters'])->pluck('quiz.best_percent')->filter(fn ($v) => $v !== null);

                return [
                    'id' => $s['id'], 'title' => $s['title'], 'done' => $s['done'], 'total' => $s['total'],
                    'enrollment' => $s['enrollment'], 'quiz_average' => $quizzes->isNotEmpty() ? (int) round($quizzes->avg()) : null,
                    'exam' => $s['exam'] ? ['best' => $s['exam']['best'], 'attempts' => $s['exam']['attempts'], 'max_points' => $s['exam']['max_points']] : null,
                ];
            }, $this->tracksFor($user->role));
            $activity = collect([$rows->max('updated_at'), ($userExams->flatten()->max('finished_at'))])->filter()->max();

            return ['id' => $user->id, 'name' => $user->name, 'role' => $user->role, 'tracks' => $tracks, 'last_activity' => $activity?->toIso8601String()];
        })->values()->all();
    }

    private function trackState(array $track, Collection $progress, ?TrainingEnrollment $enrollment, Collection $exams): array
    {
        $chapters = [];
        foreach ($track['chapters'] as $id) {
            $row = $progress[$id] ?? null;
            $hasQuiz = $this->quizzes->has($id);
            $quizPassed = !$hasQuiz || (bool) $row?->quiz_passed_at;
            $quiz = $hasQuiz ? ['best_percent' => $row?->quiz_best_percent, 'passed' => (bool) $row?->quiz_passed_at] : null;
            if ($lesson = $this->lessons->find($id)) {
                $watch = $this->watchState($row);
                $done = $watch['watched'] && $quizPassed;
                $chapters[] = [
                    'id' => $id, 'kind' => 'lesson', 'url' => '/training/lesson/'.$id, 'title' => $this->lessons->label($lesson),
                    'minutes' => (int) ceil($lesson['seconds'] / 60), 'checked' => $watch['watched'] ? 1 : 0, 'total' => 1,
                    'checklist_done' => $watch['watched'], 'watch_percent' => $watch['percent'], 'quiz' => $quiz, 'done' => $done,
                    'completed_at' => $done ? collect([$row?->watched_at, $row?->quiz_passed_at])->filter()->max()?->toIso8601String() : null,
                ];
                continue;
            }
            $article = $this->library->find($id);
            if (!$article || $article->data['kind'] !== 'guide') {
                continue;
            }
            $total = count($article->data['checklist'] ?? []);
            $checked = $row ? count($this->normalize($row->checked, $total)) : 0;
            $checklistDone = $total > 0 && $checked === $total;
            $done = $checklistDone && $quizPassed;
            $chapters[] = [
                'id' => $id, 'kind' => 'guide', 'url' => '/knowledge/'.$id, 'title' => $article->data['title'], 'minutes' => $article->data['minutes'] ?? null,
                'checked' => $checked, 'total' => $total, 'checklist_done' => $checklistDone, 'watch_percent' => null,
                'quiz' => $quiz, 'done' => $done,
                'completed_at' => $done ? collect([$row?->completed_at, $row?->quiz_passed_at])->filter()->max()?->toIso8601String() : null,
            ];
        }
        $next = collect($chapters)->first(fn ($c) => !$c['done']);
        $doneCount = count(array_filter($chapters, fn ($c) => $c['done']));

        return [
            'id' => $track['id'], 'title' => $track['title'], 'summary' => $track['summary'],
            'chapters' => $chapters, 'done' => $doneCount, 'total' => count($chapters),
            'next' => $next ? ['id' => $next['id'], 'title' => $next['title'], 'url' => $next['url']] : null,
            'kind' => $track['kind'] ?? 'guides',
            'enrollment' => $enrollment ? [
                'started_at' => $enrollment->started_at->toIso8601String(),
                'completed_at' => $enrollment->completed_at?->toIso8601String(),
                'duration_seconds' => (int) $enrollment->started_at->diffInSeconds($enrollment->completed_at ?? now()),
            ] : null,
            'exam' => isset($track['exam']) ? $this->examState($track['exam'], $exams, $enrollment, $doneCount === count($chapters)) : null,
        ];
    }

    private function examState(array $config, Collection $attempts, ?TrainingEnrollment $enrollment, bool $chaptersDone): array
    {
        $finished = $attempts->whereNotNull('finished_at')->sortByDesc('finished_at');
        $best = $finished->sortByDesc('points')->first();
        $inProgress = $attempts->whereNull('finished_at')->sortByDesc('started_at')->first();
        $last = $finished->first();
        $retakeAt = $last && !$last->passed ? $last->finished_at->copy()->addMinutes($config['retake_minutes']) : null;
        $passed = $finished->firstWhere('passed', true);
        $reason = match (true) {
            !$enrollment => 'Сначала начните программу.',
            !$chaptersDone => 'Экзамен откроется, когда все главы программы будут изучены: чек-лист и тест в каждой.',
            (bool) $passed => 'Экзамен уже сдан.',
            $retakeAt && $retakeAt->isFuture() => 'Повторная попытка будет доступна '.$retakeAt->timezone('Asia/Tashkent')->format('d.m H:i').'. Повторите главы, где были ошибки.',
            default => null,
        };
        $maxPoints = $config['questions'] * $config['points_per_answer'];

        return [
            'questions' => $config['questions'], 'points_per_answer' => $config['points_per_answer'], 'max_points' => $maxPoints,
            'pass_percent' => $config['pass_percent'], 'pass_points' => (int) ceil($maxPoints * $config['pass_percent'] / 100),
            'retake_minutes' => $config['retake_minutes'],
            'available' => $reason === null, 'locked_reason' => $reason, 'attempts' => $finished->count(),
            'in_progress_id' => $inProgress?->id,
            'best' => $best ? [
                'points' => $best->points, 'max_points' => $best->max_points, 'percent' => $best->percent, 'passed' => $best->passed,
                'finished_at' => $best->finished_at->toIso8601String(), 'duration_seconds' => (int) $best->started_at->diffInSeconds($best->finished_at),
            ] : null,
            'passed_at' => $passed?->finished_at->toIso8601String(),
        ];
    }

    /** Marks programs without an exam as completed once every chapter is studied. */
    private function syncCompletion(User $user): void
    {
        $open = TrainingEnrollment::where('user_id', $user->id)->whereNull('completed_at')->get();
        if ($open->isEmpty()) {
            return;
        }
        $progress = $this->progressFor($user);
        foreach ($open as $enrollment) {
            $track = $this->trackFor($user, $enrollment->track_id);
            if (!$track || isset($track['exam'])) {
                continue;
            }
            $state = $this->trackState($track, $progress, $enrollment, collect());
            if ($state['total'] && $state['done'] === $state['total']) {
                $enrollment->update(['completed_at' => now()]);
            }
        }
    }

    private function titleOf(string $id): string
    {
        $lesson = $this->lessons->find($id);

        return $lesson ? $this->lessons->label($lesson) : ($this->library->find($id)?->data['title'] ?? $id);
    }

    private function progressRow(User $user, string $articleId): KnowledgeProgress
    {
        return KnowledgeProgress::firstOrNew(['user_id' => $user->id, 'article_id' => $articleId], ['checked' => []]);
    }

    private function progressFor(User $user): Collection
    {
        return KnowledgeProgress::where('user_id', $user->id)->get()->keyBy('article_id');
    }

    private function enrollmentsFor(User $user): Collection
    {
        return TrainingEnrollment::where('user_id', $user->id)->get()->keyBy('track_id');
    }

    private function examsFor(User $user): Collection
    {
        return QuizAttempt::where('user_id', $user->id)->where('kind', 'exam')->get()->groupBy('subject');
    }

    private function normalize(mixed $checked, int $total): array
    {
        $values = array_filter(is_array($checked) ? $checked : [], fn ($i) => is_int($i) && $i >= 0 && $i < $total);
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
