<?php

namespace Tests\Feature;

use App\Domain\Knowledge\Labs;
use App\Models\{TrainingLabAttempt, TrainingLabSubmission, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CiscoLabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
    }

    public function test_every_ios_reference_solution_passes_its_own_checks(): void
    {
        $labs = app(Labs::class);
        foreach ($labs->content()['ios'] as $task) {
            $check = $labs->checkIos($task, implode("\n", $task['solution']));
            $this->assertTrue($check['passed'], $task['id'].': '.json_encode($check, JSON_UNESCAPED_UNICODE));
            $this->assertSame(100, $check['score']);
            $empty = $labs->checkIos($task, "enable\nconf t\nend");
            $this->assertFalse($empty['passed'], $task['id']);
        }
        $this->assertCount(5, $labs->content()['packet_tracer']);
    }

    public function test_ios_parser_understands_prompts_abbreviations_and_modes(): void
    {
        $labs = app(Labs::class);
        $task = $labs->iosTask('vlan-access');
        $input = "SW1>en\nSW1#conf t\nSW1(config)#vlan 10\nSW1(config-vlan)#name STAFF\nSW1(config-vlan)#vlan 20\nSW1(config-vlan)#name GAMERS\n"
            ."SW1(config-vlan)#int f0/1\nSW1(config-if)#sw mo acc\nSW1(config-if)#sw acc vlan 10\n"
            ."SW1(config-if)#int ra f0/2-24\nSW1(config-if-range)#switchport mode access\nSW1(config-if-range)#switchport access vlan 20\n";
        $this->assertTrue($labs->checkIos($task, $input)['passed']);

        // The right command in the wrong interface does not count.
        $wrong = "vlan 10\nname STAFF\nvlan 20\nname GAMERS\ninterface fa0/2\nswitchport mode access\nswitchport access vlan 10\n";
        $check = $labs->checkIos($task, $wrong);
        $this->assertFalse($check['passed']);
        $this->assertFalse(collect($check['results'])->firstWhere('label', 'Fa0/1 в VLAN 10')['ok']);

        $ssh = $labs->iosTask('ssh');
        $check = $labs->checkIos($ssh, implode("\n", $ssh['solution'])."\nline vty 0 4\ntransport input telnet");
        $this->assertFalse($check['passed']);
        $this->assertNotEmpty($check['violations']);
    }

    public function test_ios_trainer_records_attempts_and_reveals_solution_after_pass_or_three_fails(): void
    {
        $user = User::factory()->create(['role' => 'specialist']);
        $this->actingAs($user)->get('/training/ios')->assertOk()->assertInertia(fn (Assert $page) => $page->component('TrainingIos')->has('tasks', 9)->where('tasks.0.solution', null));

        for ($i = 1; $i <= 3; $i++) {
            $r = $this->postJson('/training/ios/ssh', ['commands' => 'hostname SW1'])->assertOk();
            $this->assertFalse($r->json('result.passed'));
            $i < 3 ? $this->assertNull($r->json('task.solution')) : $this->assertNotNull($r->json('task.solution'));
        }
        $solution = implode("\n", app(Labs::class)->iosTask('dhcp')['solution']);
        $this->postJson('/training/ios/dhcp', ['commands' => $solution])->assertOk()->assertJsonPath('result.passed', true)->assertJsonPath('task.passed', true);
        $this->postJson('/training/ios/unknown', ['commands' => 'x'])->assertNotFound();
        $this->assertSame(4, TrainingLabAttempt::where('user_id', $user->id)->where('kind', 'ios')->count());

        $this->actingAs(User::factory()->create(['role' => 'representative']))->get('/training/ios')->assertForbidden();
    }

    public function test_subnet_trainer_hides_answers_and_grades_on_the_server(): void
    {
        $user = User::factory()->create(['role' => 'lead']);
        $this->actingAs($user)->get('/training/subnets')->assertOk()->assertInertia(fn (Assert $page) => $page->component('TrainingSubnets')->where('state.best', null));
        $start = $this->postJson('/training/subnets/session')->assertOk();
        $this->assertCount(10, $start->json('tasks'));
        $this->assertStringNotContainsString('"answer"', $start->getContent());
        $this->assertSame($start->json('attempt'), $this->postJson('/training/subnets/session')->json('attempt'), 'an open session is resumed');

        $attempt = TrainingLabAttempt::findOrFail($start->json('attempt'));
        $answers = array_map(fn ($t) => $t['answer'], $attempt->payload['tasks']);
        $answers[0] = 'неверно';
        $this->travel(95)->seconds();
        $result = $this->postJson("/training/subnets/{$attempt->id}", ['answers' => $answers])->assertOk();
        $result->assertJsonPath('result.correct', 9)->assertJsonPath('result.score', 90)->assertJsonPath('result.passed', true)
            ->assertJsonPath('result.review.0.ok', false)->assertJsonPath('state.best.score', 90);
        $this->assertGreaterThanOrEqual(95, $result->json('result.seconds'));
        $this->postJson("/training/subnets/{$attempt->id}", ['answers' => $answers])->assertStatus(409);

        $this->actingAs(User::factory()->create(['role' => 'specialist']))->postJson("/training/subnets/{$attempt->id}", ['answers' => $answers])->assertNotFound();
    }

    public function test_generated_subnet_tasks_are_self_consistent(): void
    {
        $labs = app(Labs::class);
        $user = User::factory()->create(['role' => 'specialist']);
        for ($i = 0; $i < 30; $i++) {
            $attempt = $labs->startSubnets($user);
            foreach ($attempt->payload['tasks'] as $task) {
                $this->assertNotSame('', $task['answer']);
                $this->assertContains($task['answer'], $task['accept']);
                if (preg_match('~^/(\d+)$~', $task['answer'], $m)) {
                    $this->assertMatchesRegularExpression('~в которой поместятся (\d+) узлов~u', $task['prompt']);
                    preg_match('~(\d+) узлов~u', $task['prompt'], $n);
                    $this->assertGreaterThanOrEqual((int) $n[1], 2 ** (32 - (int) $m[1]) - 2);
                    $this->assertLessThan((int) $n[1], 2 ** (31 - (int) $m[1]) - 2, 'the next longer prefix is too small');
                }
            }
            $labs->submitSubnets($attempt, array_map(fn ($t) => $t['answer'], $attempt->payload['tasks']));
            $this->assertSame(100, $attempt->fresh()->score);
        }
    }

    public function test_packet_tracer_submission_review_and_file_access(): void
    {
        Storage::fake('local');
        $engineer = User::factory()->create(['role' => 'specialist']);
        $lead = User::factory()->create(['role' => 'lead']);
        $other = User::factory()->create(['role' => 'specialist']);
        $owner = User::factory()->create(['role' => 'owner']);

        $this->actingAs($engineer)->get('/training/labs')->assertOk()->assertInertia(fn (Assert $page) => $page->component('TrainingLabs')->has('labs', 5)->where('review', null));
        $this->post('/training/labs/pt-vlans/submit', ['comment' => 'Коротко', 'files' => [UploadedFile::fake()->create('virus.exe', 10)]])
            ->assertSessionHasErrors(['comment', 'files.0']);
        $this->post('/training/labs/pt-vlans/submit', ['comment' => 'Собрал две VLAN, trunk, пинги проходят внутри VLAN.',
            'files' => [UploadedFile::fake()->create('club.pkt', 300), UploadedFile::fake()->create('trunk.png', 40, 'image/png')]])->assertRedirect()->assertSessionHasNoErrors();
        $submission = TrainingLabSubmission::sole();
        $this->assertSame('submitted', $submission->status);
        Storage::disk('local')->assertExists($submission->files[0]['path']);
        $this->post('/training/labs/pt-vlans/submit', ['comment' => 'Ещё раз отправляю работу.', 'files' => [UploadedFile::fake()->create('club.pkt', 10)]])
            ->assertSessionHasErrors('files');

        $this->get("/training/labs/submissions/{$submission->id}/files/0")->assertOk();
        $this->actingAs($other)->get("/training/labs/submissions/{$submission->id}/files/0")->assertNotFound();
        $this->actingAs($other)->post("/training/labs/submissions/{$submission->id}/review", ['status' => 'accepted', 'grade' => 100])->assertForbidden();
        $this->actingAs($engineer)->post("/training/labs/submissions/{$submission->id}/review", ['status' => 'accepted', 'grade' => 100])->assertForbidden();

        $this->actingAs($lead)->get('/training/labs')->assertInertia(fn (Assert $page) => $page->has('review', 1)->where('review.0.status', 'submitted'));
        $this->get("/training/labs/submissions/{$submission->id}/files/1")->assertOk();
        $this->post("/training/labs/submissions/{$submission->id}/review", ['status' => 'rework'])->assertSessionHasErrors('review_comment');
        $this->post("/training/labs/submissions/{$submission->id}/review", ['status' => 'rework', 'review_comment' => 'Нет скриншота show vlan brief.'])->assertSessionHasNoErrors();
        $this->assertSame('rework', $submission->fresh()->status);
        $this->post("/training/labs/submissions/{$submission->id}/review", ['status' => 'accepted', 'grade' => 90])->assertSessionHasErrors('status');

        $this->actingAs($engineer)->post('/training/labs/pt-vlans/submit', ['comment' => 'Добавил скриншот show vlan brief.', 'files' => [UploadedFile::fake()->create('vlan.png', 40, 'image/png')]])->assertSessionHasNoErrors();
        $second = TrainingLabSubmission::latest('id')->first();
        $this->actingAs($owner)->post("/training/labs/submissions/{$second->id}/review", ['status' => 'accepted', 'grade' => 92, 'review_comment' => 'Хорошо'])->assertSessionHasNoErrors();
        $this->assertSame(92, $second->fresh()->grade);
        $this->assertSame($owner->id, $second->fresh()->reviewer_id);

        $this->actingAs($owner)->get('/training')->assertInertia(fn (Assert $page) => $page
            ->where('team', fn ($team) => collect($team)->firstWhere('id', $engineer->id)['labs']['labs_accepted'] === 1
                && collect($team)->firstWhere('id', $engineer->id)['labs']['labs_grade'] === 92));
        $this->actingAs($engineer)->get('/training')->assertInertia(fn (Assert $page) => $page->where('labs.labs_accepted', 1)->where('labs.ios_total', 9));
        $this->actingAs($engineer)->post('/training/labs/pt-vlans/submit', ['comment' => 'Повторная отправка принятой.', 'files' => [UploadedFile::fake()->create('x.png', 40, 'image/png')]])
            ->assertSessionHasErrors('files');
    }
}
