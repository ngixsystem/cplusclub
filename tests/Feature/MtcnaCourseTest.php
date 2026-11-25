<?php

namespace Tests\Feature;

use App\Domain\Knowledge\{Lessons, Quizzes};
use App\Models\{KnowledgeProgress, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MtcnaCourseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
    }

    public function test_course_content_is_consistent(): void
    {
        $lessons = app(Lessons::class);
        $course = $lessons->course('mikrotik-mtcna');
        $this->assertSame('PL4p9O5LPYFNuK-7zIziI-eR492bBSUqph', $course['playlist']);
        $this->assertCount(26, $course['lessons']);
        $this->assertSame(range(0, 25), array_column($course['lessons'], 'index'));
        $this->assertCount(26, array_unique(array_column($course['lessons'], 'match')));
        $quizzes = app(Quizzes::class);
        foreach ($course['lessons'] as $lesson) {
            $this->assertMatchesRegularExpression('/^mikrotik (\d\d|модуль 7) $/u', $lesson['match'], $lesson['id']);
            $this->assertSame($lesson['quiz'], $quizzes->has($lesson['id']), $lesson['id']);
            $this->assertSame('mikrotik-mtcna', $lessons->find($lesson['id'])['course']);
        }
        // Lessons of different courses never mix in navigation.
        $this->assertNull($lessons->neighbours('mtcna-01')[0]);
        $this->assertSame('mtcna-02', $lessons->neighbours('mtcna-01')[1]['id']);
        $this->assertSame('ccna-1-3', $lessons->neighbours('ccna-1-2')[1]['id']);
    }

    public function test_lesson_page_watch_and_quiz_gate(): void
    {
        $user = User::factory()->create(['role' => 'specialist']);
        $this->actingAs($user)->get('/training/lesson/mtcna-07')->assertOk()->assertInertia(fn (Assert $p) => $p->component('TrainingLesson')
            ->where('course.playlist', 'PL4p9O5LPYFNuK-7zIziI-eR492bBSUqph')->where('course.total', 26)->where('lesson.number', 7)
            ->where('prev.id', 'mtcna-06')->where('next.id', 'mtcna-08')->where('enrolled', false));
        $this->postJson('/training/lesson/mtcna-07/quiz')->assertStatus(409);
        $this->post('/training/mikrotik-mtcna/start')->assertRedirect('/training');
        $this->get('/training/lesson/mtcna-07')->assertInertia(fn (Assert $p) => $p->where('enrolled', true));

        KnowledgeProgress::create(['user_id' => $user->id, 'article_id' => 'mtcna-07', 'checked' => [], 'watch_seconds' => 479, 'watch_duration' => 479, 'watched_at' => now()]);
        $this->postJson('/training/lesson/mtcna-07/quiz')->assertOk()->assertJsonCount(4, 'questions');
        $this->get('/training')->assertInertia(fn (Assert $p) => $p->where('tracks.3.id', 'mikrotik-mtcna')->where('tracks.3.kind', 'video')->where('tracks.3.total', 26));

        $this->actingAs(User::factory()->create(['role' => 'representative']))->get('/training/lesson/mtcna-07')->assertForbidden();
    }
}
