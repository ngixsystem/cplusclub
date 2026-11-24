<?php

namespace Tests\Feature;

use App\Domain\Knowledge\{Lessons, Library, Quizzes, Training};
use App\Models\{KnowledgeProgress, QuizAttempt, TrainingEnrollment, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TrainingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
    }

    /** Shown option index of the right answer for every question; the first $wrongCount get a wrong option. */
    private function answers(int $attemptId, bool $right = true, int $wrongCount = PHP_INT_MAX): array
    {
        $quizzes = app(Quizzes::class);
        $out = [];
        foreach (QuizAttempt::findOrFail($attemptId)->questions as $n => $item) {
            $key = array_search($quizzes->question($item['id'])['answer'], $item['perm'], true);
            $out[$item['id']] = $right || $n >= $wrongCount ? $key : ($key + 1) % 4;
        }

        return $out;
    }

    /** Marks a chapter as fully studied: checklist confirmed and test passed. */
    private function study(string $chapter): void
    {
        $this->postJson("/knowledge/$chapter/progress", ['checked' => [0, 1, 2, 3]])->assertOk();
        $attempt = $this->postJson("/knowledge/$chapter/quiz")->assertOk()->json('attempt');
        $this->postJson("/knowledge/quiz/$attempt", ['answers' => $this->answers($attempt)])->assertOk()->assertJson(['passed' => true]);
    }

    public function test_content_tracks_and_question_bank_are_consistent(): void
    {
        $library = new Library;
        $training = app(Training::class);
        $quizzes = app(Quizzes::class);
        $ids = [];
        foreach ($training->tracks() as $track) {
            $this->assertNotEmpty($track['chapters'], $track['id']);
            $this->assertCount(count($track['chapters']), array_unique($track['chapters']), $track['id']);
            foreach ($track['chapters'] as $id) {
                if (($track['kind'] ?? null) === 'video') {
                    $lesson = app(Lessons::class)->find($id);
                    $this->assertNotNull($lesson, "$track[id]: $id");
                    $count = count($quizzes->forChapter($id));
                    $this->assertSame($lesson['quiz'], $count > 0, "quiz flag for $id");
                    if ($lesson['quiz']) {
                        $this->assertGreaterThanOrEqual(3, $count, $id);
                    }
                    continue;
                }
                $article = $library->find($id);
                $this->assertNotNull($article, "$track[id]: $id");
                $this->assertSame('guide', $article->data['kind'], "$track[id]: $id");
                $this->assertNotEmpty($article->data['checklist'], "$track[id]: $id");
                $this->assertCount(5, $quizzes->forChapter($id), "questions for $id");
            }
            if (isset($track['exam'])) {
                $pool = collect($track['chapters'])->sum(fn ($id) => count($quizzes->forChapter($id)));
                $this->assertLessThanOrEqual($pool, $track['exam']['questions'], $track['id']);
                if (($track['kind'] ?? null) !== 'video') {
                    $this->assertGreaterThanOrEqual(count($track['chapters']), $track['exam']['questions'], 'every chapter gets a question');
                }
            }
        }
        foreach ($quizzes->bank() as $chapter => $questions) {
            $this->assertTrue($library->find($chapter) !== null || app(Lessons::class)->find($chapter) !== null, $chapter);
            foreach ($questions as $q) {
                $ids[] = $q['id'];
                $this->assertCount(4, array_unique($q['options']), $q['id']);
                $this->assertContains($q['answer'], [0, 1, 2, 3], $q['id']);
                $this->assertNotSame('', trim($q['q']), $q['id']);
                $this->assertNotSame('', trim($q['explain']), $q['id']);
            }
        }
        $this->assertCount(count($ids), array_unique($ids));
        foreach (['owner', 'lead', 'specialist', 'representative'] as $role) {
            $this->assertNotEmpty($training->tracksFor($role), $role);
        }
    }

    public function test_checklist_progress_is_stored_per_user(): void
    {
        $user = User::factory()->create(['role' => 'specialist']);
        $other = User::factory()->create(['role' => 'specialist']);
        $this->actingAs($user)->postJson('/knowledge/first-start/progress', ['checked' => [2, 0, 0, 9]])
            ->assertOk()->assertJson(['checked' => [0, 2], 'completed' => false]);
        $this->get('/knowledge/first-start')->assertInertia(fn (Assert $p) => $p->where('progress', [0, 2])
            ->where('quiz.questions', 5)->where('quiz.passed', false)->where('quiz.attempts', 0));
        $this->actingAs($other)->get('/knowledge/first-start')->assertInertia(fn (Assert $p) => $p->where('progress', []));

        $this->actingAs($user)->postJson('/knowledge/first-start/progress', ['checked' => [0, 1, 2, 3]])->assertOk()->assertJson(['completed' => true]);
        $this->postJson('/knowledge/first-start/progress', ['checked' => [0]])->assertJson(['completed' => false]);
        $this->postJson('/knowledge/first-start/progress', ['checked' => []])->assertJson(['checked' => []]);
        $this->assertSame(0, KnowledgeProgress::count());
    }

    public function test_chapter_test_hides_answers_grades_on_server_and_chapter_needs_checklist_and_test(): void
    {
        $user = User::factory()->create(['role' => 'specialist']);
        $this->actingAs($user);

        $start = $this->postJson('/knowledge/first-start/quiz')->assertOk()->assertJsonCount(5, 'questions');
        foreach ($start->json('questions') as $q) {
            $this->assertSame(['id', 'q', 'options'], array_keys($q));
            $this->assertCount(4, $q['options']);
        }
        $attempt = $start->json('attempt');
        // 3 of 5 right = 60% < 80%: not passed; the review reveals the key with explanations.
        $fail = $this->postJson("/knowledge/quiz/$attempt", ['answers' => $this->answers($attempt, false, 2)])->assertOk()
            ->assertJson(['correct' => 3, 'total' => 5, 'percent' => 60, 'passed' => false, 'quiz' => ['best_percent' => 60, 'passed' => false, 'attempts' => 1]]);
        $this->assertCount(5, $fail->json('review'));
        $this->assertNotEmpty($fail->json('review.0.explain'));
        $this->postJson("/knowledge/quiz/$attempt", ['answers' => []])->assertStatus(409);

        // The checklist alone does not complete a chapter that has a test.
        $this->postJson('/knowledge/first-start/progress', ['checked' => [0, 1, 2, 3]])->assertOk();
        $this->get('/training')->assertInertia(fn (Assert $p) => $p->where('tracks.0.chapters.2.id', 'first-start')
            ->where('tracks.0.chapters.2.checklist_done', true)->where('tracks.0.chapters.2.done', false)->where('tracks.0.done', 0));

        $retry = $this->postJson('/knowledge/first-start/quiz')->json('attempt');
        $this->postJson("/knowledge/quiz/$retry", ['answers' => $this->answers($retry)])->assertOk()
            ->assertJson(['correct' => 5, 'percent' => 100, 'passed' => true, 'quiz' => ['best_percent' => 100, 'passed' => true, 'attempts' => 2]]);
        $this->assertNotNull(KnowledgeProgress::where('user_id', $user->id)->value('quiz_passed_at'));
        $this->get('/training')->assertInertia(fn (Assert $p) => $p->where('tracks.0.chapters.2.done', true)->where('tracks.0.done', 1)
            ->where('tracks.0.next.id', 'daily-operations'));

        // Someone else's attempt is invisible; unknown answers count as wrong.
        $this->actingAs(User::factory()->create(['role' => 'specialist']))->postJson("/knowledge/quiz/$retry", ['answers' => []])->assertNotFound();
        $this->actingAs($user);
        $own = $this->postJson('/knowledge/first-start/quiz')->json('attempt');
        $this->postJson("/knowledge/quiz/$own", ['answers' => 'x'])->assertUnprocessable();
        $this->postJson("/knowledge/quiz/$own", ['answers' => ['nope' => 1]])->assertOk()->assertJson(['correct' => 0, 'passed' => false]);
    }

    public function test_test_and_progress_reject_catalogue_entries_guests_and_disabled_users(): void
    {
        $this->postJson('/knowledge/first-start/progress', ['checked' => [0]])->assertUnauthorized();
        $this->postJson('/knowledge/first-start/quiz')->assertUnauthorized();
        $user = User::factory()->create(['role' => 'specialist']);
        $this->actingAs($user);
        $source = collect((new Library)->articles())->first(fn ($a) => $a['kind'] === 'source' && empty($a['local_guide_id']));
        $this->postJson("/knowledge/{$source['id']}/progress", ['checked' => [0]])->assertForbidden();
        $this->postJson("/knowledge/{$source['id']}/quiz")->assertForbidden();
        $this->postJson('/knowledge/does-not-exist/quiz')->assertNotFound();
        $this->postJson('/knowledge/first-start/progress', ['checked' => 'all'])->assertUnprocessable();
        $this->postJson('/knowledge/first-start/progress', [])->assertUnprocessable();

        $disabled = User::factory()->create(['role' => 'lead', 'active' => false]);
        $this->actingAs($disabled)->postJson('/knowledge/first-start/progress', ['checked' => [0]])->assertForbidden();
        $this->postJson('/knowledge/first-start/quiz')->assertForbidden();
        $this->get('/training')->assertForbidden();
        $this->post('/training/engineer-start/start')->assertForbidden();
        $this->assertSame(0, KnowledgeProgress::count() + QuizAttempt::count() + TrainingEnrollment::count());
    }

    public function test_intro_enrollment_exam_with_points_cooldown_and_training_duration(): void
    {
        $this->travelTo(now()->setTime(9, 0));
        $user = User::factory()->create(['role' => 'specialist']);
        $this->actingAs($user);

        // Before the start the intro is shown: no enrollment, exam locked.
        $this->get('/training')->assertInertia(fn (Assert $p) => $p->where('tracks.0.enrollment', null)
            ->where('tracks.0.exam.questions', 20)->where('tracks.0.exam.max_points', 100)->where('tracks.0.exam.pass_points', 80)
            ->where('tracks.0.exam.available', false)->has('tracks', 2)->where('tracks.1.id', 'cisco-ccna'));
        $this->post('/training/lead-server/start')->assertNotFound(); // not a specialist program
        $this->post('/training/engineer-start/start')->assertRedirect('/training');
        $this->post('/training/engineer-start/start')->assertRedirect('/training'); // idempotent
        $this->assertSame(1, TrainingEnrollment::count());

        $this->post('/training/engineer-start/exam')->assertSessionHasErrors('exam');
        $track = collect(app(Training::class)->tracks())->firstWhere('id', 'engineer-start');
        $this->travel(2)->days();
        foreach ($track['chapters'] as $chapter) {
            $this->study($chapter);
        }
        $this->get('/training')->assertInertia(fn (Assert $p) => $p->where('tracks.0.done', 12)->where('tracks.0.exam.available', true)
            ->where('tracks.0.enrollment.completed_at', null));

        // Exam: 20 questions covering every chapter, no answers on the page; starting again resumes the same attempt.
        $this->post('/training/engineer-start/exam')->assertRedirect();
        $exam = QuizAttempt::where('kind', 'exam')->latest('id')->first();
        $this->assertSame(20, $exam->total);
        $this->assertSame(100, $exam->max_points);
        $this->assertEqualsCanonicalizing($track['chapters'], collect($exam->questions)->pluck('chapter')->unique()->values()->all());
        $this->assertCount(20, collect($exam->questions)->pluck('id')->unique());
        $this->post('/training/engineer-start/exam')->assertRedirect("/training/exam/{$exam->id}");
        $this->get("/training/exam/{$exam->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('TrainingExam')
            ->has('questions', 20)->where('result', null)->where('track.points_per_answer', 5)
            ->has('questions.0', fn (Assert $q) => $q->has('id')->has('q')->has('options', 4)));

        // 15 right = 75 points < 80: failed, no key revealed, retake locked for 30 minutes.
        $this->travel(12)->minutes();
        $this->post("/training/exam/{$exam->id}", ['answers' => $this->answers($exam->id, false, 5)])->assertRedirect("/training/exam/{$exam->id}");
        $this->get("/training/exam/{$exam->id}")->assertInertia(fn (Assert $p) => $p->where('questions', null)
            ->where('result.points', 75)->where('result.max_points', 100)->where('result.passed', false)
            ->where('result.duration_seconds', 720)->has('result.chapters', 12)->missing('result.review'));
        $this->post("/training/exam/{$exam->id}", ['answers' => []])->assertRedirect("/training/exam/{$exam->id}"); // no regrade
        $this->assertSame(75, $exam->fresh()->points);
        $this->post('/training/engineer-start/exam')->assertSessionHasErrors('exam');

        $this->travel(31)->minutes();
        $this->post('/training/engineer-start/exam')->assertRedirect();
        $second = QuizAttempt::where('kind', 'exam')->latest('id')->first();
        $this->assertNotSame($exam->id, $second->id);
        $this->travel(9)->minutes();
        $this->post("/training/exam/{$second->id}", ['answers' => $this->answers($second->id, false, 2)])->assertRedirect();
        $this->assertSame(90, $second->fresh()->points);
        $this->assertTrue($second->fresh()->passed);

        $enrollment = TrainingEnrollment::first();
        $this->assertNotNull($enrollment->completed_at);
        $this->get('/training')->assertInertia(fn (Assert $p) => $p->where('tracks.0.exam.best.points', 90)->where('tracks.0.exam.attempts', 2)
            ->where('tracks.0.exam.available', false)->whereNot('tracks.0.exam.passed_at', null)
            ->where('tracks.0.enrollment.duration_seconds', (int) $enrollment->started_at->diffInSeconds($enrollment->completed_at)));
        $this->post('/training/engineer-start/exam')->assertSessionHasErrors('exam'); // already passed

        // Another user cannot open this exam.
        $this->actingAs(User::factory()->create(['role' => 'specialist']))->get("/training/exam/{$second->id}")->assertNotFound();
    }

    public function test_program_without_exam_completes_when_all_chapters_are_studied(): void
    {
        $user = User::factory()->create(['role' => 'representative']);
        $this->actingAs($user)->post('/training/club-admin/start')->assertRedirect();
        $track = collect(app(Training::class)->tracks())->firstWhere('id', 'club-admin');
        foreach ($track['chapters'] as $chapter) {
            $this->assertNull(TrainingEnrollment::first()->completed_at);
            $this->study($chapter);
        }
        $this->assertNotNull(TrainingEnrollment::first()->completed_at);
        $this->get('/training')->assertInertia(fn (Assert $p) => $p->where('tracks.0.exam', null)->where('tracks.0.done', 6)->where('team', null));
        $this->post('/training/club-admin/exam')->assertNotFound();
    }

    public function test_team_overview_is_scoped_and_shows_scores_and_duration(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'name' => 'Owner']);
        $lead = User::factory()->create(['role' => 'lead', 'name' => 'Lead']);
        $engineer = User::factory()->create(['role' => 'specialist', 'name' => 'Engineer']);
        $client = User::factory()->create(['role' => 'representative', 'name' => 'Club']);
        User::factory()->create(['role' => 'specialist', 'active' => false, 'name' => 'Former']);
        $this->actingAs($engineer)->post('/training/engineer-start/start');
        $this->study('daily-operations');

        $this->actingAs($owner)->get('/training')->assertInertia(fn (Assert $p) => $p
            ->where('team', fn ($team) => collect($team)->pluck('name')->sort()->values()->all() === ['Club', 'Engineer', 'Lead']));
        $this->actingAs($lead)->get('/training')->assertInertia(fn (Assert $p) => $p
            ->where('team', fn ($team) => collect($team)->pluck('name')->sort()->values()->all() === ['Engineer', 'Lead'])
            ->where('team', function ($team) {
                $row = collect($team)->firstWhere('name', 'Engineer')['tracks'][0];

                return $row['done'] === 1 && $row['quiz_average'] === 100 && $row['enrollment']['completed_at'] === null
                    && $row['exam'] === ['best' => null, 'attempts' => 0, 'max_points' => 100];
            }));
        foreach ([$engineer, $client] as $user) {
            $this->actingAs($user)->get('/training')->assertOk()->assertInertia(fn (Assert $p) => $p->where('team', null));
        }
        $this->actingAs($client)->get('/training')->assertInertia(fn (Assert $p) => $p->where('tracks.0.id', 'club-admin')->has('tracks', 1));
    }

    public function test_knowledge_index_shows_my_tracks(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'lead']))->get('/knowledge')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('training', 3)->where('training.0.next.id', 'daily-operations')->where('training.0.next.url', '/knowledge/daily-operations'));
    }
}
