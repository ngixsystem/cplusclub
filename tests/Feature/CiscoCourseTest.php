<?php

namespace Tests\Feature;

use App\Domain\Knowledge\{Lessons, Quizzes};
use App\Models\{KnowledgeProgress, QuizAttempt, TrainingEnrollment, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CiscoCourseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
    }

    private function answers(int $attemptId, bool $right = true): array
    {
        $quizzes = app(Quizzes::class);
        $out = [];
        foreach (QuizAttempt::findOrFail($attemptId)->questions as $item) {
            $key = array_search($quizzes->question($item['id'])['answer'], $item['perm'], true);
            $out[$item['id']] = $right ? $key : ($key + 1) % 4;
        }

        return $out;
    }

    /** Plays a lesson in real time: a report every 20 seconds of playback. */
    private function watchFully(string $lesson, int $duration): void
    {
        $this->postJson("/training/lesson/$lesson/watch", ['position' => 0, 'duration' => $duration])->assertOk();
        for ($pos = 20; $pos <= $duration; $pos += 20) {
            $this->travel(20)->seconds();
            $this->postJson("/training/lesson/$lesson/watch", ['position' => $pos, 'duration' => $duration])->assertOk();
        }
        $this->travel(20)->seconds();
        $this->postJson("/training/lesson/$lesson/watch", ['position' => $duration, 'duration' => $duration])->assertOk();
    }

    public function test_course_content_is_consistent(): void
    {
        $course = app(Lessons::class)->course();
        $this->assertSame('PLHpWwR6fSBN5gTzVFoNbKfYi7ziZfxjwf', $course['playlist']);
        $this->assertCount(37, $course['lessons']);
        $this->assertSame(range(0, 36), array_column($course['lessons'], 'index'));
        $this->assertCount(37, array_unique(array_column($course['lessons'], 'match')));
        foreach ($course['lessons'] as $lesson) {
            $this->assertMatchesRegularExpression('/^itn [\d ]+ $/', $lesson['match'], $lesson['id']);
            $this->assertGreaterThan(0, $lesson['seconds']);
        }
    }

    public function test_lesson_page_and_access(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'specialist']));
        $this->get('/training/lesson/ccna-2-3')->assertOk()->assertInertia(fn (Assert $p) => $p->component('TrainingLesson')
            ->where('lesson.code', '2.3')->where('lesson.number', 12)->where('lesson.match', 'itn 2 3 ')
            ->where('watch.percent', 0)->where('watch.watched', false)->where('watch.required_percent', 90)
            ->where('quiz.questions', 4)->where('prev.id', 'ccna-2-2')->where('next.id', 'ccna-2-4')->where('enrolled', false)
            ->where('course.playlist', 'PLHpWwR6fSBN5gTzVFoNbKfYi7ziZfxjwf'));
        $this->get('/training/lesson/nope')->assertNotFound();

        $this->actingAs(User::factory()->create(['role' => 'representative']));
        $this->get('/training/lesson/ccna-2-3')->assertForbidden();
        $this->postJson('/training/lesson/ccna-2-3/watch', ['position' => 1, 'duration' => 100])->assertForbidden();
        $this->get('/training')->assertInertia(fn (Assert $p) => $p->has('tracks', 1)->where('tracks.0.id', 'club-admin'));
        $this->actingAs(User::factory()->create(['role' => 'lead', 'active' => false]))->get('/training/lesson/ccna-2-3')->assertForbidden();
    }

    public function test_only_real_watching_is_credited_and_the_test_opens_after_90_percent(): void
    {
        $user = User::factory()->create(['role' => 'specialist']);
        $this->actingAs($user);
        $this->postJson('/training/lesson/ccna-2-3/quiz')->assertStatus(409);

        $this->postJson('/training/lesson/ccna-2-3/watch', ['position' => 0, 'duration' => 1115])->assertOk()->assertJson(['percent' => 0]);
        $this->travel(10)->seconds();
        $this->postJson('/training/lesson/ccna-2-3/watch', ['position' => 10, 'duration' => 1115])->assertOk();
        $this->assertSame(10, KnowledgeProgress::first()->watch_seconds);
        // Seeking ahead: only 2× the wall-clock time since the last report is credited.
        $this->travel(10)->seconds();
        $this->postJson('/training/lesson/ccna-2-3/watch', ['position' => 900, 'duration' => 1115])->assertOk();
        $this->assertSame(32, KnowledgeProgress::first()->watch_seconds);
        // Going back is not credited; a long pause restarts the baseline.
        $this->travel(5)->seconds();
        $this->postJson('/training/lesson/ccna-2-3/watch', ['position' => 100, 'duration' => 1115])->assertOk();
        $this->travel(5)->minutes();
        $this->postJson('/training/lesson/ccna-2-3/watch', ['position' => 400, 'duration' => 1115])->assertOk();
        $this->assertSame(32, KnowledgeProgress::first()->watch_seconds);
        // Burst of reports without time passing earns at most 2 s each.
        foreach ([410, 420, 430] as $pos) {
            $this->postJson('/training/lesson/ccna-2-3/watch', ['position' => $pos, 'duration' => 1115])->assertOk();
        }
        $this->assertLessThanOrEqual(38, KnowledgeProgress::first()->watch_seconds);
        $this->postJson('/training/lesson/ccna-2-3/watch', ['position' => -1, 'duration' => 0])->assertUnprocessable();

        $this->watchFully('ccna-2-3', 1115);
        $this->get('/training/lesson/ccna-2-3')->assertInertia(fn (Assert $p) => $p->where('watch.watched', true)->where('watch.percent', 100));

        $start = $this->postJson('/training/lesson/ccna-2-3/quiz')->assertOk()->assertJsonCount(4, 'questions');
        $this->assertSame(['id', 'q', 'options'], array_keys($start->json('questions.0')));
        $attempt = $start->json('attempt');
        $this->postJson("/training/lesson-quiz/$attempt", ['answers' => $this->answers($attempt)])->assertOk()->assertJson(['passed' => true]);
        $this->postJson("/training/lesson-quiz/$attempt", ['answers' => []])->assertStatus(409);
        $this->actingAs(User::factory()->create(['role' => 'specialist']))->postJson("/training/lesson-quiz/$attempt", ['answers' => []])->assertNotFound();

        $this->actingAs($user)->get('/training')->assertInertia(fn (Assert $p) => $p->where('tracks.1.id', 'cisco-ccna')
            ->where('tracks.1.kind', 'video')->where('tracks.1.done', 1)->where('tracks.1.chapters.11.done', true)
            ->where('tracks.1.chapters.11.kind', 'lesson')->where('tracks.1.chapters.11.url', '/training/lesson/ccna-2-3')
            ->where('tracks.1.next.url', '/training/lesson/ccna-1-2'));
    }

    public function test_intro_lesson_without_test_counts_after_watching(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'lead']));
        $this->postJson('/training/lesson/ccna-2-0/quiz')->assertNotFound();
        $this->watchFully('ccna-2-0', 77);
        $this->get('/training')->assertInertia(fn (Assert $p) => $p->where('tracks.1.chapters.8.id', 'ccna-2-0')
            ->where('tracks.1.chapters.8.done', true)->where('tracks.1.chapters.8.quiz', null));
    }

    public function test_exam_and_certificate_after_all_lessons(): void
    {
        $user = User::factory()->create(['role' => 'specialist', 'name' => 'Инженер Тест']);
        $this->actingAs($user);
        $this->post('/training/cisco-ccna/start')->assertRedirect('/training');
        $this->get('/training/certificate/cisco-ccna')->assertNotFound();
        $this->post('/training/cisco-ccna/exam')->assertSessionHasErrors('exam');

        foreach (app(Lessons::class)->course()['lessons'] as $lesson) {
            KnowledgeProgress::create(['user_id' => $user->id, 'article_id' => $lesson['id'], 'checked' => [], 'watch_seconds' => $lesson['seconds'],
                'watch_duration' => $lesson['seconds'], 'watched_at' => now(), 'quiz_best_percent' => $lesson['quiz'] ? 100 : null, 'quiz_passed_at' => $lesson['quiz'] ? now() : null]);
        }
        $this->get('/training')->assertInertia(fn (Assert $p) => $p->where('tracks.1.done', 37)->where('tracks.1.exam.available', true)
            ->where('tracks.1.exam.max_points', 100)->where('tracks.1.exam.pass_points', 80));
        $this->travel(3)->days();
        $this->post('/training/cisco-ccna/exam')->assertRedirect();
        $exam = QuizAttempt::where('kind', 'exam')->latest('id')->first();
        $this->assertSame(25, $exam->total);
        $this->assertSame(25, collect($exam->questions)->pluck('chapter')->unique()->count());
        $this->post("/training/exam/{$exam->id}", ['answers' => $this->answers($exam->id)])->assertRedirect();
        $this->get("/training/exam/{$exam->id}")->assertInertia(fn (Assert $p) => $p->where('result.points', 100)
            ->where('result.chapters.0.title', fn ($t) => preg_match('/^\d/', $t) === 1));
        $this->assertNotNull(TrainingEnrollment::where('track_id', 'cisco-ccna')->value('completed_at'));

        $this->get('/training/certificate/cisco-ccna')->assertOk()->assertInertia(fn (Assert $p) => $p->component('TrainingCertificate')
            ->where('certificate.name', 'Инженер Тест')->where('certificate.track', 'Курс Cisco CCNA (видео)')->where('certificate.chapters', 37)
            ->where('certificate.exam.points', 100)->where('certificate.source', 'Академия Cisco STR')
            ->where('certificate.number', fn ($n) => preg_match('/^CPC-[0-9A-F]{10}$/', $n) === 1));
        $this->get('/training/certificate/engineer-start')->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'specialist']))->get('/training/certificate/cisco-ccna')->assertNotFound();
    }
}
