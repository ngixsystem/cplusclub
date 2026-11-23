<?php

namespace Tests\Feature;

use App\Domain\Knowledge\{Library, TicketPractice};
use App\Models\{Ticket, TrainingTicketRun, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TicketPracticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
    }

    private function scenario(string $id): array
    {
        return app(TicketPractice::class)->scenario($id);
    }

    private function startRun(string $scenario, string $mode = 'exam'): TrainingTicketRun
    {
        $this->post("/training/practice/$scenario/start", ['mode' => $mode])->assertRedirect();

        return TrainingTicketRun::latest('id')->first();
    }

    private function act(TrainingTicketRun $run, string $type, array $data = [])
    {
        return $this->from("/training/practice/run/{$run->id}")->post("/training/practice/run/{$run->id}", ['type' => $type] + $data);
    }

    private function ok(TrainingTicketRun $run, string $type, array $data = []): void
    {
        $this->act($run, $type, $data)->assertRedirect("/training/practice/run/{$run->id}")->assertSessionHasNoErrors();
    }

    private function intake(TrainingTicketRun $run, array $sc): void
    {
        $this->ok($run, 'create', [
            'equipment_id' => $sc['expected']['equipment'], 'category' => $sc['expected']['category'], 'priority' => $sc['expected']['priority'][0],
            'description' => 'Клуб сообщает: '.implode(', ', $sc['expected']['keywords']).'. Подробное описание симптома, масштаба и уже выполненных действий.',
        ]);
        $this->ok($run, 'transition', ['to' => 'accepted']);
        $this->ok($run, 'transition', ['to' => 'working']);
    }

    /** Plays a scenario the way a good engineer would. */
    private function playWell(TrainingTicketRun $run): void
    {
        $sc = $this->scenario($run->scenario_id);
        $actions = collect($sc['actions']);
        $this->intake($run, $sc);
        foreach ($actions->where('role', 'key') as $a) {
            $this->ok($run, 'action', ['action' => $a['id']]);
        }
        if ($sc['approval']) {
            $this->ok($run, 'proposal', ['description' => 'Замена неисправного SSD writeback после закрытия зала', 'amount' => $sc['approval']['amount_min'] + 10, 'expected_duration' => 'сегодня ночью']);
        }
        $this->ok($run, 'action', ['action' => $actions->firstWhere('role', 'fix')['id']]);
        foreach ($actions->filter(fn ($a) => !empty($a['required'])) as $a) {
            $this->ok($run, 'action', ['action' => $a['id']]);
        }
        $this->ok($run, 'worklog', ['minutes' => 35, 'actions' => 'Диагностика, устранение причины и проверка результата']);
        $this->ok($run, 'transition', ['to' => 'resolved'] + $this->closing($sc));
        if (!$sc['approval']) {
            $this->ok($run, 'transition', ['to' => 'closed']);
        }
    }

    private function closing(array $sc): array
    {
        return [
            'work_result' => 'Выполнена диагностика по шагам и устранена найденная причина сбоя.',
            'verification_result' => 'Холодная загрузка и запуск игры проверены, результат совпадает с нормой.',
            'cause' => 'Основная причина подтверждена проверками на объекте и сравнением.',
            'solution' => 'Причина устранена одним изменением, результат зафиксирован в заявке.',
            'cause_option' => collect($sc['causes'])->firstWhere('correct', true)['id'],
        ];
    }

    public function test_scenarios_are_consistent(): void
    {
        $library = new Library;
        $practice = app(TicketPractice::class);
        $this->assertGreaterThanOrEqual(3, count($practice->scenarios()));
        foreach ($practice->scenarios() as $sc) {
            $actions = collect($sc['actions']);
            $ids = $actions->pluck('id');
            $this->assertCount($ids->count(), $ids->unique(), $sc['id']);
            $this->assertCount(1, $actions->where('role', 'fix'), $sc['id']);
            $this->assertNotEmpty($actions->where('role', 'key'), $sc['id']);
            $this->assertNotEmpty($actions->where('role', 'harmful'), $sc['id']);
            $this->assertNotEmpty($actions->filter(fn ($a) => !empty($a['required']) && $a['group'] === 'verify'), $sc['id']);
            $this->assertCount(1, collect($sc['causes'])->where('correct', true), $sc['id']);
            $this->assertContains($sc['expected']['category'], TicketPractice::CATEGORIES);
            foreach ($sc['chapters'] as $chapter) {
                $this->assertNotNull($library->find($chapter), $chapter);
            }
            foreach (['create', 'accept', 'work', 'diagnose', 'fix', 'verify', 'worklog', 'resolve', 'close'] as $stage) {
                $this->assertNotEmpty($sc['hints'][$stage] ?? null, "$sc[id] hint $stage");
            }
            foreach ($actions as $a) {
                $this->assertContains($a['group'], ['diag', 'fix', 'verify']);
                $this->assertContains($a['role'], ['key', 'neutral', 'harmful', 'fix', 'verify']);
                if ($a['role'] === 'harmful') {
                    $this->assertNotEmpty($a['penalty'] ?? null, $a['id']);
                }
            }
        }
    }

    public function test_good_engineer_scores_100_on_every_scenario_without_touching_real_tickets(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'specialist']));
        foreach (app(TicketPractice::class)->scenarios() as $sc) {
            $run = $this->startRun($sc['id']);
            $this->playWell($run);
            $run->refresh();
            $this->assertSame('closed', $run->status, $sc['id']);
            $this->assertNotNull($run->finished_at);
            $this->assertSame(100, $run->score, $sc['id'].': '.json_encode(app(TicketPractice::class)->score($run)['rows'], JSON_UNESCAPED_UNICODE));
            $this->assertTrue($run->passed);
        }
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, DB::table('ticket_events')->count() + DB::table('work_logs')->count() + DB::table('outbox_events')->count());

        $this->get('/training')->assertInertia(fn (Assert $p) => $p->where('practice.passed', 3)->where('practice.total', 3)
            ->whereNot('practice.completed_at', null)->where('practice.scenarios.0.best', 100));
    }

    public function test_page_hides_the_key_and_shows_hints_only_in_demo(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'specialist']));
        $exam = $this->startRun('pc-no-ip');
        $this->get("/training/practice/run/{$exam->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('TicketPractice')
            ->where('hint', null)->where('result', null)->where('run.status', 'message')
            ->where('scenario.actions', fn ($actions) => collect($actions)->every(fn ($a) => array_keys($a) === ['id', 'group', 'label']))
            ->where('scenario.causes', fn ($causes) => collect($causes)->every(fn ($c) => array_keys($c) === ['id', 'label']))
            ->missing('scenario.expected')->missing('scenario.hints'));

        $demo = $this->startRun('pc-no-ip', 'demo');
        $this->assertNotSame($exam->id, $demo->id);
        $this->assertSame($demo->id, $this->startRun('pc-no-ip', 'demo')->id); // resumes the open run
        $this->get("/training/practice/run/{$demo->id}")->assertInertia(fn (Assert $p) => $p->where('hint.stage', 'create'));
        $this->intake($demo, $this->scenario('pc-no-ip'));
        $this->get("/training/practice/run/{$demo->id}")->assertInertia(fn (Assert $p) => $p->where('hint.stage', 'diagnose')
            ->where('run.timeline', fn ($t) => collect($t)->pluck('text')->contains('Статус: В работе')));
        $this->playWell($this->startRun('group-windows-logo', 'demo'));
        // Demo runs never count as passed trials.
        $this->get('/training')->assertInertia(fn (Assert $p) => $p->where('practice.passed', 0)->where('practice.scenarios.1.demo_done', true)
            ->where('practice.scenarios.1.attempts', 0));
    }

    public function test_harmful_action_rework_and_sloppy_intake_cost_points_and_fail_the_trial(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'specialist']));
        $sc = $this->scenario('pc-no-ip');
        $run = $this->startRun('pc-no-ip');
        // Wrong PC, wrong priority, thin description.
        $this->ok($run, 'create', ['equipment_id' => 'pc-12', 'category' => 'Ремонт', 'priority' => 'critical', 'description' => 'Не работает комп']);
        $this->ok($run, 'transition', ['to' => 'accepted']);
        $this->ok($run, 'transition', ['to' => 'working']);
        $this->ok($run, 'action', ['action' => 'reboot-server']);
        $this->act($run, 'action', ['action' => 'reboot-server'])->assertSessionHasErrors('status'); // already done
        // Resolving before the fix: the club sends it back.
        $this->ok($run, 'transition', ['to' => 'resolved'] + $this->closing($sc));
        $this->assertSame('working', $run->fresh()->status);
        $this->ok($run, 'action', ['action' => 'fix-cable']);
        $this->ok($run, 'action', ['action' => 'verify-boot']);
        $this->ok($run, 'transition', ['to' => 'resolved'] + ['cause_option' => 'image'] + $this->closing($sc));
        $this->ok($run, 'transition', ['to' => 'closed']);

        $result = app(TicketPractice::class)->score($run->fresh());
        $rows = collect($result['rows'])->keyBy('label');
        $this->assertSame(0, $rows['Оформление заявки']['points']); // wrong PC, category, priority; thin description
        $this->assertSame(0, $rows['Диагностика']['points']);
        $this->assertSame(5, $rows['Проверка результата']['points']); // 1 of 2 required checks
        $this->assertSame(0, $rows['Причина']['points']); // wrong root cause chosen
        $this->assertSame(0, $rows['Без возврата на доработку']['points']);
        $this->assertSame(8, $rows['Закрытие и трудозатраты']['points']); // no work log
        $this->assertCount(1, $result['harmful']);
        $this->assertSame(23, $result['score']); // 10 fix + 5 approval n/a + 5 verify + 8 fields + 5 time − 10 harmful
        $this->assertFalse($run->fresh()->passed);
    }

    public function test_paid_work_needs_approval_and_wrong_amount_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'lead']));
        $sc = $this->scenario('writeback-ssd');
        $run = $this->startRun('writeback-ssd');
        $this->intake($run, $sc);
        $this->ok($run, 'proposal', ['description' => 'Замена SSD writeback на новый диск', 'amount' => 5000, 'expected_duration' => 'ночью']);
        $this->assertNull($run->fresh()->state['proposal']);
        $this->assertStringContainsString('отклонил', collect($run->fresh()->state['timeline'])->last(fn ($e) => $e['kind'] === 'club')['text']);
        $this->ok($run, 'action', ['action' => 'fix-ssd']); // without approval
        $this->assertTrue($run->fresh()->state['fix']['unapproved']);
        $this->act($run, 'proposal', ['description' => '', 'amount' => 'x', 'expected_duration' => ''])->assertSessionHasErrors(['description', 'amount', 'expected_duration']);

        $this->assertSame($run->id, $this->startRun('writeback-ssd')->id); // an unfinished trial is resumed, not restarted

        $this->actingAs(User::factory()->create(['role' => 'lead']));
        $good = $this->startRun('writeback-ssd');
        $this->assertNotSame($run->id, $good->id);
        $this->intake($good, $sc);
        $this->ok($good, 'proposal', ['description' => 'Замена SSD writeback на новый диск', 'amount' => 85, 'expected_duration' => 'сегодня ночью']);
        $this->assertSame('approved', $good->fresh()->state['proposal']['status']);
        $this->ok($good, 'action', ['action' => 'fix-ssd']);
        $this->assertFalse($good->fresh()->state['fix']['unapproved']);
        foreach (['verify-group', 'verify-evening'] as $a) {
            $this->ok($good, 'action', ['action' => $a]);
        }
        // Agreed works: the club confirms the result, the engineer cannot close himself.
        $this->ok($good, 'transition', ['to' => 'resolved'] + $this->closing($sc));
        $this->assertSame('closed', $good->fresh()->status);
    }

    public function test_workflow_rules_mirror_real_tickets(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'specialist']));
        $sc = $this->scenario('pc-no-ip');
        $run = $this->startRun('pc-no-ip');
        $this->act($run, 'transition', ['to' => 'accepted'])->assertSessionHasErrors('status'); // no ticket yet
        $this->act($run, 'create', ['equipment_id' => 'nope', 'category' => '', 'priority' => 'urgent', 'description' => 'x'])
            ->assertSessionHasErrors(['equipment_id', 'category', 'priority', 'description']);
        $this->ok($run, 'create', ['equipment_id' => 'pc-07', 'category' => 'Диагностика', 'priority' => 'normal', 'description' => 'PC-07 PXE-E51 нет IP, перезагрузка не помогла']);
        $this->act($run, 'create', ['equipment_id' => 'pc-07', 'category' => 'Диагностика', 'priority' => 'normal', 'description' => 'повторная заявка'])->assertSessionHasErrors('status');
        $this->act($run, 'transition', ['to' => 'working'])->assertSessionHasErrors('status'); // new → working skips acceptance
        $this->act($run, 'action', ['action' => 'link'])->assertSessionHasErrors('status'); // not in work yet
        $this->ok($run, 'transition', ['to' => 'accepted']);
        $this->ok($run, 'transition', ['to' => 'working']);
        $this->act($run, 'transition', ['to' => 'approval'])->assertSessionHasErrors('status');
        $this->act($run, 'transition', ['to' => 'closed'])->assertSessionHasErrors('status');
        $this->act($run, 'transition', ['to' => 'resolved', 'work_result' => '', 'cause_option' => 'zzz'])->assertSessionHasErrors(['work_result', 'verification_result', 'cause', 'solution', 'cause_option']);
        $this->act($run, 'action', ['action' => 'unknown'])->assertStatus(422);
        $this->act($run, 'worklog', ['minutes' => 0, 'actions' => ''])->assertSessionHasErrors(['minutes', 'actions']);
        $this->act($run, 'bogus')->assertSessionHasErrors('type');
        $this->assertSame('working', $run->fresh()->status);
    }

    public function test_access_is_for_engineers_only_and_runs_are_private(): void
    {
        $client = User::factory()->create(['role' => 'representative']);
        $this->actingAs($client)->post('/training/practice/pc-no-ip/start', ['mode' => 'exam'])->assertForbidden();
        $this->get('/training')->assertInertia(fn (Assert $p) => $p->where('practice', null));

        $engineer = User::factory()->create(['role' => 'specialist']);
        $this->actingAs($engineer);
        $run = $this->startRun('pc-no-ip');
        $this->post('/training/practice/unknown/start', ['mode' => 'exam'])->assertNotFound();
        $this->post('/training/practice/pc-no-ip/start', ['mode' => 'live'])->assertSessionHasErrors('mode');
        $this->actingAs(User::factory()->create(['role' => 'specialist']))->get("/training/practice/run/{$run->id}")->assertNotFound();
        $this->actingAs($client)->get("/training/practice/run/{$run->id}")->assertForbidden();

        $this->actingAs($engineer);
        $this->playWell($run);
        $this->act($run, 'transition', ['to' => 'accepted'])->assertStatus(409);
        $this->get("/training/practice/run/{$run->id}")->assertInertia(fn (Assert $p) => $p->where('result.score', 100)->where('result.passed', true));
    }

    public function test_team_overview_shows_practice_for_engineers_only(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $engineer = User::factory()->create(['role' => 'specialist', 'name' => 'Engineer']);
        User::factory()->create(['role' => 'representative', 'name' => 'Club']);
        $this->actingAs($engineer);
        $this->playWell($this->startRun('pc-no-ip'));

        $this->actingAs($owner)->get('/training')->assertInertia(fn (Assert $p) => $p->where('team', function ($team) {
            $team = collect($team)->keyBy('name');

            return $team['Club']['practice'] === null
                && $team['Engineer']['practice']['passed'] === 1
                && $team['Engineer']['practice']['scenarios'][0]['best'] === 100
                && $team['Engineer']['practice']['completed_at'] === null;
        }));
    }
}
