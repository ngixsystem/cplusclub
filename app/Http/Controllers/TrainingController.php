<?php

namespace App\Http\Controllers;

use App\Domain\Knowledge\{Article, Labs, Quizzes, TicketPractice, Training, TrainingAssignments};
use App\Domain\Telegram\PersonalLink;
use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response};

final class TrainingController extends Controller
{
    public function index(Request $request, Training $training, TicketPractice $practice, Labs $labs, TrainingAssignments $assignments, PersonalLink $telegram): Response
    {
        Gate::authorize('viewAny', Article::class);
        $user = $request->user();
        $team = Gate::allows('viewTeam', Article::class) ? $training->team($user) : null;
        if ($team !== null) {
            $practiceRows = $practice->teamRows(collect($team)->pluck('id'));
            $team = array_map(fn ($row) => $row + ['practice' => in_array($row['role'], TicketPractice::ROLES, true)
                ? ($practiceRows[$row['id']] ?? ['passed' => 0, 'total' => count($practice->scenarios()), 'scenarios' => [], 'started_at' => null, 'completed_at' => null, 'duration_seconds' => null, 'pass_points' => TicketPractice::PASS_POINTS])
                : null], $team);
            $labRows = $labs->teamRows(collect($team)->filter(fn ($row) => in_array($row['role'], Labs::ROLES, true))->pluck('id'));
            $team = array_map(fn ($row) => $row + ['labs' => $labRows[$row['id']] ?? null], $team);
            $assigned = $assignments->states(collect($team)->pluck('id'));
            $team = array_map(fn ($row) => $row + ['assignments' => (object) ($assigned[$row['id']] ?? []), 'assignable' => $row['id'] !== $user->id], $team);
        }

        return Inertia::render('Training', [
            'tracks' => $training->summary($user),
            'practice' => $practice->summary($user),
            'labs' => $labs->summary($user),
            'assignments' => (object) $assignments->forUser($user),
            'telegram' => $telegram->state($user),
            'chapterPassPercent' => Quizzes::CHAPTER_PASS_PERCENT,
            'team' => $team,
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

    /** Printable certificate (browser "print → save as PDF") for a completed program. */
    public function certificate(string $track, Request $request, Training $training): Response
    {
        Gate::authorize('viewAny', Article::class);
        $data = $training->certificate($request->user(), $track);
        abort_unless($data, 404);

        return Inertia::render('TrainingCertificate', ['certificate' => $data]);
    }
}
