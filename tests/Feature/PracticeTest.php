<?php

namespace Tests\Feature;

use App\Domain\Knowledge\Library;
use App\Domain\Training\{Practice, TrainingSandbox};
use App\Models\{Club, Equipment, Ticket, TrainingRun, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PracticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
    }

    private function begin(string $scenario): TrainingRun
    {
        $this->post("/training/practice/$scenario")->assertRedirect();

        return TrainingRun::where('scenario_id', $scenario)->latest('id')->firstOrFail();
    }

    private function move(TrainingRun $run, string $status, array $fields = []): \Illuminate\Testing\TestResponse
    {
        $ticket = Ticket::findOrFail($run->ticket_id);

        return $this->post("/tickets/{$ticket->id}/transition", ['status' => $status, 'version' => $ticket->version, ...$fields]);
    }

    private function model(string $scenario): array
    {
        $m = app(Practice::class)->scenario($scenario)['model'];

        return ['work_result' => $m['work'], 'verification_result' => $m['verification'], 'cause' => $m['cause'], 'solution' => $m['solution']];
    }

    public function test_scenarios_are_consistent_and_model_answers_earn_full_marks_on_text(): void
    {
        $practice = app(Practice::class);
        $library = new Library;
        $this->assertGreaterThanOrEqual(4, count($practice->scenarios()));
        foreach ($practice->scenarios() as $id => $s) {
            foreach ($s['chapters'] as $chapter) {
                $this->assertSame('guide', $library->find($chapter)?->data['kind'], "$id: $chapter");
            }
            $checks = collect($s['checks']);
            $this->assertCount($checks->count(), $checks->pluck('id')->unique(), $id);
            $this->assertTrue($checks->contains('key', true), $id);
            $this->assertTrue($checks->contains('harmful', true), $id);
            foreach (['cause' => 'cause', 'solution' => 'solution', 'verification' => 'verification'] as $field => $key) {
                $text = mb_strtolower(str_replace('ё', 'е', $s['model'][$field].($field === 'solution' ? ' '.$s['model']['work'] : '')));
                foreach ($s['expect'][$key] as $group) {
                    $this->assertTrue(collect($group)->contains(fn ($stem) => str_contains($text, $stem)), "$id model $field misses ".implode('|', $group));
                }
            }
            foreach (['rework', 'confirm'] as $reply) {
                $this->assertNotEmpty($s['client'][$reply], "$id client $reply");
            }
        }
        foreach (app(\App\Domain\Knowledge\Training::class)->tracks() as $track) {
            foreach ($track['practice']['scenarios'] ?? [] as $id) {
                $this->assertNotNull($practice->scenario($id), $id);
            }
        }
    }

    public function test_practice_tickets_and_training_club_stay_out_of_real_data(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $trainee = User::factory()->create(['role' => 'specialist', 'name' => 'Trainee']);
        $other = User::factory()->create(['role' => 'specialist']);
        $real = Club::create(['name' => 'Real club', 'address' => 'Tashkent']);

        $this->actingAs($trainee);
        $run = $this->begin('pc-no-ip');
        $ticket = Ticket::findOrFail($run->ticket_id);
        $this->assertSame($trainee->id, $ticket->assignee_id);
        $this->assertSame('new', $ticket->status);
        $this->get('/tickets/'.$ticket->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Ticket')
            ->where('training.scenario.id', 'pc-no-ip')->where('training.can_check', false)->where('training.result', null)
            ->where('training.checks.0.finding', null)->where('training.checks.0.harmful', null));
        $this->get('/tickets')->assertInertia(fn (Assert $p) => $p->where('rows.data.0.id', $ticket->id)->where('rows.data.0.training_user_id', $trainee->id));

        // Nobody else sees the practice ticket, the training club or its demo PCs.
        foreach ([$owner, $other] as $user) {
            $this->actingAs($user)->get('/tickets/'.$ticket->id)->assertNotFound();
            $this->get('/tickets')->assertInertia(fn (Assert $p) => $p->where('rows.data', [])->where('stats.tickets', 0));
        }
        $this->actingAs($owner)->get('/clubs')->assertInertia(fn (Assert $p) => $p->where('stats.clubs', 1)->where('rows.data.0.id', $real->id)
            ->where('clubs', fn ($clubs) => collect($clubs)->pluck('id')->all() === [$real->id]));
        $this->get('/equipment')->assertInertia(fn (Assert $p) => $p->where('stats.equipment', 0));
        $this->assertSame(0, Equipment::count());
        $this->assertSame(1, Equipment::withoutGlobalScope('real')->count());
        $this->assertNull(Club::find($ticket->club_id));
        $this->get('/clubs/'.$ticket->club_id.'/dashboard')->assertNotFound();
        $this->get('/users')->assertInertia(fn (Assert $p) => $p->where('users.data', fn ($users) => !collect($users)->contains('email', TrainingSandbox::CLIENT_EMAIL)));
        $this->assertSame(0, Ticket::count()); // the owner's queries never include someone else's practice ticket
        $this->assertSame(0, DB::table('outbox_events')->count());
    }

    public function test_full_practice_run_scores_100_with_breakdown_and_model_answer(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $trainee = User::factory()->create(['role' => 'specialist']);
        $this->actingAs($trainee);
        $run = $this->begin('pc-no-ip');
        $this->post("/training/practice/run/{$run->id}/check", ['check' => 'screen'])->assertSessionHasErrors('check');

        $this->travel(2)->minutes();
        $this->move($run, 'accepted')->assertSessionHasNoErrors();
        $this->move($run, 'working')->assertSessionHasNoErrors();
        foreach (['screen', 'link', 'neighbour', 'vlan', 'scan-dhcp'] as $check) {
            $this->post("/training/practice/run/{$run->id}/check", ['check' => $check])->assertSessionHasNoErrors();
        }
        $this->get('/tickets/'.$run->ticket_id)->assertInertia(fn (Assert $p) => $p->where('training.can_check', true)
            ->where('training.checks.3.done', true)->where('training.checks.3.finding', fn ($f) => str_contains($f, 'VLAN 20'))
            ->where('training.checks.5.finding', null));
        $this->post("/tickets/{$run->ticket_id}/work", ['minutes' => 25, 'cost_minor' => 0, 'currency' => 'USD', 'actions' => 'Диагностика и исправление VLAN'])->assertSessionHasNoErrors();
        $this->move($run, 'resolved', [...$this->model('pc-no-ip'), 'comment' => 'Готово'])->assertSessionHasNoErrors();

        $ticket = Ticket::findOrFail($run->ticket_id);
        $this->assertSame('closed', $ticket->status);
        $this->assertSame(app(TrainingSandbox::class)->clientId(), $ticket->events()->latest('id')->value('actor_id'));
        $run->refresh();
        $this->assertSame('finished', $run->status);
        $this->assertSame(100, $run->score, json_encode($run->breakdown, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($run->passed);
        $this->get('/tickets/'.$ticket->id)->assertInertia(fn (Assert $p) => $p->where('training.result.score', 100)
            ->has('training.result.breakdown', 9)->where('training.result.model.cause', fn ($c) => str_contains($c, 'VLAN'))
            ->where('training.checks.5.harmful', true)->where('training.checks.3.key', true));
        $this->get('/training')->assertInertia(fn (Assert $p) => $p->where('tracks.0.practice.scenarios.0.best.score', 100)
            ->where('tracks.0.practice.passed', 1)->where('tracks.0.practice.total', 4));
        $this->post("/training/practice/run/{$run->id}/check", ['check' => 'link'])->assertSessionHasErrors('check');
    }

    public function test_harmful_actions_missing_check_slow_reaction_and_client_rework_cost_points(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $this->actingAs(User::factory()->create(['role' => 'specialist']));
        $run = $this->begin('pc-no-ip');
        $this->travel(20)->minutes();
        $this->move($run, 'accepted');
        $this->move($run, 'working');
        foreach (['screen', 'vlan', 'router-dhcp'] as $check) {
            $this->post("/training/practice/run/{$run->id}/check", ['check' => $check]);
        }
        // Unneeded paid work: the simulated client still approves, but it costs the approval points.
        $ticket = Ticket::findOrFail($run->ticket_id);
        $this->post("/tickets/{$ticket->id}/proposal", ['version' => $ticket->version, 'description' => 'Замена коммутатора', 'amount_minor' => 50000, 'currency' => 'USD', 'expected_duration' => '1 день'])->assertSessionHasNoErrors();
        $this->assertSame('working', $ticket->fresh()->status);
        $this->assertSame('approved', DB::table('ticket_proposals')->where('ticket_id', $ticket->id)->value('status'));

        $answer = ['work_result' => 'Поменял настройку порта', 'verification_result' => 'Проверено', 'cause' => 'Порт в VLAN камер', 'solution' => 'Вернул порт в VLAN 10'];
        $this->move($run, 'resolved', $answer)->assertSessionHasNoErrors();
        $ticket->refresh();
        $this->assertSame('working', $ticket->status, 'client returns a result without a described check');
        $this->assertStringContainsString('Возвращено на доработку', $ticket->events()->latest('id')->value('comment'));
        $this->assertSame('active', $run->fresh()->status);

        $this->move($run, 'resolved', [...$answer, 'verification_result' => 'Холодный старт ПК-07: IP получен, Windows загрузилась'])->assertSessionHasNoErrors();
        $run->refresh();
        $this->assertSame('finished', $run->status);
        $points = collect($run->breakdown)->pluck('points', 'key');
        $this->assertSame(0, $points['reaction']);
        $this->assertSame(0, $points['diagnostics']); // 2 of 4 key checks = 10, minus 10 for switching off the router DHCP
        $this->assertSame(0, $points['worklog']);
        $this->assertSame(0, $points['approval']);
        $this->assertSame(0, $points['first_time']);
        $this->assertSame(20, $points['cause']);
        $this->assertSame(10, $points['verification']);
        $this->assertSame(5 + 20 + 15 + 10, $run->score);
        $this->assertFalse($run->passed);

        // A new attempt opens a fresh ticket; the best result is kept.
        $second = $this->begin('pc-no-ip');
        $this->assertNotSame($run->ticket_id, $second->ticket_id);
        $this->get('/training')->assertInertia(fn (Assert $p) => $p->where('tracks.0.practice.scenarios.0.attempts', 1)
            ->where('tracks.0.practice.scenarios.0.active_ticket_id', $second->ticket_id)->where('tracks.0.practice.scenarios.0.best.score', 50));
    }

    public function test_paid_repair_needs_a_proposal_approved_by_the_simulated_client(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'lead']));
        $run = $this->begin('pc-overheat');
        $this->move($run, 'accepted');
        $this->move($run, 'working');
        foreach (['temps', 'eventlog', 'inspect'] as $check) {
            $this->post("/training/practice/run/{$run->id}/check", ['check' => $check]);
        }
        $ticket = Ticket::findOrFail($run->ticket_id);
        $this->post("/tickets/{$ticket->id}/proposal", ['version' => $ticket->version, 'description' => 'Замена кулера процессора', 'amount_minor' => 3500, 'currency' => 'USD', 'expected_duration' => 'сегодня']);
        $this->assertSame('working', $ticket->fresh()->status);
        $this->assertStringContainsString('Согласовано', $ticket->events()->latest('id')->value('comment'));
        $this->post("/tickets/{$ticket->id}/work", ['minutes' => 40, 'cost_minor' => 3500, 'currency' => 'USD', 'actions' => 'Замена кулера']);
        $this->move($run, 'resolved', $this->model('pc-overheat'));
        $this->assertSame('closed', $ticket->fresh()->status);
        $run->refresh();
        $this->assertSame(10, collect($run->breakdown)->firstWhere('key', 'approval')['points']);
        $this->assertSame(100, $run->score, json_encode($run->breakdown, JSON_UNESCAPED_UNICODE));
    }

    public function test_access_rules_for_practice(): void
    {
        $rep = User::factory()->create(['role' => 'representative']);
        $this->actingAs($rep)->post('/training/practice/pc-no-ip')->assertNotFound();
        $this->post('/training/practice/no-such')->assertNotFound();

        $trainee = User::factory()->create(['role' => 'specialist']);
        $this->actingAs($trainee);
        $run = $this->begin('pc-no-ip');
        $this->assertSame($run->id, $this->begin('pc-no-ip')->id, 'an active run is resumed');
        $this->actingAs(User::factory()->create(['role' => 'specialist']))
            ->post("/training/practice/run/{$run->id}/check", ['check' => 'screen'])->assertNotFound();
        $this->actingAs($trainee)->post("/training/practice/run/{$run->id}/check", ['check' => 'nope'])->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'specialist', 'active' => false]))->post('/training/practice/pc-no-ip')->assertForbidden();
    }
}
