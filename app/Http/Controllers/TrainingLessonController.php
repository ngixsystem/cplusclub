<?php

namespace App\Http\Controllers;

use App\Domain\Knowledge\{Lessons, Quizzes, Training};
use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Inertia\{Inertia, Response};

/** Video lessons (Cisco CCNA, MikroTik MTCNA): player page, watch reports, lesson tests. The program id equals the course id. */
final class TrainingLessonController extends Controller
{
    public function show(string $lesson, Request $request, Lessons $lessons, Training $training): Response
    {
        $item = $this->lesson($lesson, $request, $lessons, $training);
        [$prev, $next] = $lessons->neighbours($lesson);
        $course = $lessons->course($item['course']);
        $user = $request->user();
        $position = array_search($lesson, array_column($course['lessons'], 'id'), true);

        return Inertia::render('TrainingLesson', [
            'course' => ['title' => $course['title'], 'author' => $course['author'], 'source' => $course['source'], 'playlist' => $course['playlist'],
                'note' => $course['note'], 'total' => count($course['lessons'])],
            'lesson' => $item + ['number' => $position + 1],
            'prev' => $prev ? ['id' => $prev['id'], 'title' => $lessons->label($prev)] : null,
            'next' => $next ? ['id' => $next['id'], 'title' => $lessons->label($next)] : null,
            'watch' => $training->watchState($training->lessonProgress($user, $lesson)),
            'quiz' => $training->quizStateFor($user, $lesson),
            'enrolled' => (collect($training->summary($user))->firstWhere('id', $item['course'])['enrollment'] ?? null) !== null,
        ]);
    }

    /** Player position report, sent every few seconds while the verified lesson video plays. */
    public function watch(string $lesson, Request $request, Lessons $lessons, Training $training)
    {
        $item = $this->lesson($lesson, $request, $lessons, $training);
        $data = $request->validate(['position' => 'required|numeric|min:0|max:20000', 'duration' => 'required|numeric|min:1|max:20000']);
        $row = $training->watch($request->user(), $item, (float) $data['position'], (float) $data['duration']);

        return response()->json($training->watchState($row));
    }

    public function quizStart(string $lesson, Request $request, Lessons $lessons, Training $training, Quizzes $quizzes)
    {
        $this->lesson($lesson, $request, $lessons, $training);
        abort_unless($quizzes->has($lesson), 404);
        $watch = $training->watchState($training->lessonProgress($request->user(), $lesson));
        abort_unless($watch['watched'], 409, 'Сначала досмотрите урок — тест откроется после '.$watch['required_percent'].' % просмотра.');
        $attempt = $training->startQuiz($request->user(), $lesson);

        return response()->json(['attempt' => $attempt->id, 'questions' => $quizzes->present($attempt)]);
    }

    public function quizSubmit(QuizAttempt $attempt, Request $request, Lessons $lessons, Training $training)
    {
        abort_unless($attempt->user_id === $request->user()->id && $attempt->kind === 'chapter', 404);
        $this->lesson($attempt->subject, $request, $lessons, $training);
        abort_if((bool) $attempt->finished_at, 409, 'Тест уже проверен. Начните новую попытку.');
        $data = $request->validate(['answers' => 'present|array|max:20', 'answers.*' => 'nullable|integer|min:0|max:9']);

        return response()->json($training->submitChapterQuiz($request->user(), $attempt, $data['answers']));
    }

    private function lesson(string $id, Request $request, Lessons $lessons, Training $training): array
    {
        $user = $request->user();
        abort_unless($user->active, 403);
        $item = $lessons->find($id);
        abort_unless($item, 404);
        abort_unless($training->trackFor($user, $item['course']), 403);

        return $item;
    }
}
