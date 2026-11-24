<?php

namespace App\Http\Controllers;

use App\Domain\Knowledge\Labs;
use App\Models\{TrainingLabAttempt, TrainingLabSubmission, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Validation\ValidationException;
use Inertia\{Inertia, Response};

/** Cisco practice: subnet trainer, IOS command trainer, Packet Tracer labs with lead review. */
final class TrainingLabsController extends Controller
{
    public function subnets(Request $request, Labs $labs): Response
    {
        $this->allow($request, $labs);

        return Inertia::render('TrainingSubnets', ['state' => $labs->subnetState($request->user())]);
    }

    public function subnetsStart(Request $request, Labs $labs)
    {
        $this->allow($request, $labs);
        $attempt = $labs->startSubnets($request->user());

        return response()->json(['attempt' => $attempt->id, 'started_at' => $attempt->started_at->toIso8601String(), 'tasks' => $labs->presentSubnets($attempt)]);
    }

    public function subnetsSubmit(TrainingLabAttempt $attempt, Request $request, Labs $labs)
    {
        $this->allow($request, $labs);
        abort_unless($attempt->user_id === $request->user()->id && $attempt->kind === 'subnet', 404);
        abort_if((bool) $attempt->finished_at, 409, 'Эта тренировка уже проверена.');
        $data = $request->validate(['answers' => 'present|array|max:20', 'answers.*' => 'nullable|string|max:60']);

        return response()->json(['result' => $labs->submitSubnets($attempt, $data['answers']), 'state' => $labs->subnetState($request->user())]);
    }

    public function ios(Request $request, Labs $labs): Response
    {
        $this->allow($request, $labs);

        return Inertia::render('TrainingIos', ['tasks' => $labs->iosState($request->user())]);
    }

    public function iosCheck(string $task, Request $request, Labs $labs)
    {
        $this->allow($request, $labs);
        $item = $labs->iosTask($task);
        abort_unless($item, 404);
        $data = $request->validate(['commands' => 'required|string|max:8000']);
        $attempt = $labs->submitIos($request->user(), $item, $data['commands']);

        return response()->json(['result' => $attempt->result, 'task' => collect($labs->iosState($request->user()))->firstWhere('id', $task)]);
    }

    public function labs(Request $request, Labs $labs): Response
    {
        $this->allow($request, $labs);
        $user = $request->user();

        return Inertia::render('TrainingLabs', [
            'labs' => $labs->labsState($user),
            'review' => $labs->canReview($user) ? $labs->reviewQueue($user) : null,
        ]);
    }

    public function submit(string $lab, Request $request, Labs $labs)
    {
        $this->allow($request, $labs);
        abort_unless($labs->lab($lab), 404);
        $user = $request->user();
        $data = $request->validate([
            'comment' => 'required|string|min:10|max:3000',
            'files' => 'required|array|min:1|max:4',
            'files.*' => 'file|max:20480|extensions:pkt,pka,png,jpg,jpeg,webp,pdf',
        ], [], ['comment' => 'описание', 'files' => 'файлы', 'files.*' => 'файл']);
        $latest = TrainingLabSubmission::where('user_id', $user->id)->where('lab_id', $lab)->latest('id')->first();
        if ($latest && $latest->status === 'submitted') {
            throw ValidationException::withMessages(['files' => 'Работа уже на проверке — дождитесь решения руководителя.']);
        }
        if (TrainingLabSubmission::where('user_id', $user->id)->where('lab_id', $lab)->where('status', 'accepted')->exists()) {
            throw ValidationException::withMessages(['files' => 'Эта лабораторная уже принята.']);
        }
        $stored = [];
        try {
            foreach ($request->file('files') as $file) {
                $stored[] = ['path' => $file->store('training-labs/'.$user->id, 'local'), 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 200), 'size' => $file->getSize()];
            }
            TrainingLabSubmission::create(['user_id' => $user->id, 'lab_id' => $lab, 'comment' => trim($data['comment']), 'files' => $stored]);
        } catch (\Throwable $e) {
            foreach ($stored as $f) {
                Storage::disk('local')->delete($f['path']);
            }
            throw $e;
        }

        return back()->with('success', 'Работа отправлена на проверку руководителю.');
    }

    public function review(TrainingLabSubmission $submission, Request $request, Labs $labs)
    {
        $reviewer = $request->user();
        abort_unless($labs->canReview($reviewer, $submission->user), 403);
        $data = $request->validate([
            'status' => 'required|in:accepted,rework',
            'grade' => 'required_if:status,accepted|nullable|integer|min:0|max:100',
            'review_comment' => 'required_if:status,rework|nullable|string|max:3000',
        ], ['grade.required_if' => 'Поставьте оценку от 0 до 100.', 'review_comment.required_if' => 'Напишите, что нужно доработать.']);
        DB::transaction(function () use ($submission, $data, $reviewer) {
            $locked = TrainingLabSubmission::lockForUpdate()->findOrFail($submission->id);
            if ($locked->status !== 'submitted') {
                throw ValidationException::withMessages(['status' => 'Работа уже проверена.']);
            }
            $locked->update(['status' => $data['status'], 'grade' => $data['status'] === 'accepted' ? $data['grade'] : null,
                'review_comment' => $data['review_comment'] ?? null, 'reviewer_id' => $reviewer->id, 'reviewed_at' => now()]);
        });

        return back()->with('success', $data['status'] === 'accepted' ? 'Работа принята.' : 'Работа возвращена на доработку.');
    }

    public function file(TrainingLabSubmission $submission, int $n, Request $request, Labs $labs)
    {
        $user = $request->user();
        abort_unless($submission->user_id === $user->id || $labs->canReview($user, $submission->user), 404);
        $file = $submission->files[$n] ?? null;
        abort_unless($file && Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->download($file['path'], $file['name'], ['X-Content-Type-Options' => 'nosniff']);
    }

    private function allow(Request $request, Labs $labs): void
    {
        abort_unless($labs->allows($request->user()), 403);
    }
}
