<?php

namespace App\Http\Controllers;

use App\Domain\Knowledge\{Article, Quizzes, Training};
use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response};

final class TrainingController extends Controller
{
    public function index(Request $request, Training $training): Response
    {
        Gate::authorize('viewAny', Article::class);
        $user = $request->user();

        return Inertia::render('Training', [
            'tracks' => $training->summary($user),
            'chapterPassPercent' => Quizzes::CHAPTER_PASS_PERCENT,
            'team' => Gate::allows('viewTeam', Article::class) ? $training->team($user) : null,
        ]);
    }

    /** The employee has read the intro and starts the program: the training clock starts now. */
    public function start(string $track, Request $request, Training $training)
    {
        Gate::authorize('viewAny', Article::class);
        $training->enroll($request->user(), $track);

        return redirect()->route('training.index')->with('success', 'Обучение началось. Удачи!');
    }

    public function examStart(string $track, Request $request, Training $training)
    {
        Gate::authorize('viewAny', Article::class);
        $attempt = $training->startExam($request->user(), $track);

        return redirect()->route('training.exam', $attempt);
    }

    public function exam(QuizAttempt $attempt, Request $request, Training $training, Quizzes $quizzes): Response
    {
        $this->own($attempt, $request);
        $track = $training->trackFor($request->user(), $attempt->subject);
        abort_unless($track && isset($track['exam']), 404);

        return Inertia::render('TrainingExam', [
            'attempt' => [
                'id' => $attempt->id, 'started_at' => $attempt->started_at->toIso8601String(),
                'finished' => (bool) $attempt->finished_at,
            ],
            'track' => ['id' => $track['id'], 'title' => $track['title']] + $track['exam'],
            'questions' => $attempt->finished_at ? null : $quizzes->present($attempt),
            'result' => $attempt->finished_at ? $training->examResult($attempt, $track) : null,
        ]);
    }

    public function examSubmit(QuizAttempt $attempt, Request $request, Training $training)
    {
        $this->own($attempt, $request);
        if ($attempt->finished_at) {
            return redirect()->route('training.exam', $attempt);
        }
        $data = $request->validate(['answers' => 'present|array|max:100', 'answers.*' => 'nullable|integer|min:0|max:9']);
        $training->submitExam($request->user(), $attempt, $data['answers']);

        return redirect()->route('training.exam', $attempt);
    }

    private function own(QuizAttempt $attempt, Request $request): void
    {
        Gate::authorize('viewAny', Article::class);
        abort_unless($attempt->user_id === $request->user()->id && $attempt->kind === 'exam', 404);
    }
}
