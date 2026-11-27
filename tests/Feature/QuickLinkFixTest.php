<?php

namespace Tests\Feature;

use App\Models\{Club, Equipment, Ticket, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuickLinkFixTest extends TestCase
{
    use RefreshDatabase;

    private function linkTicket(Club $c, array $attrs = []): Ticket
    {
        $pc = Equipment::create(['club_id' => $c->id, 'name' => 'PC12', 'type' => 'pc']);
        $t = new Ticket(['club_id' => $c->id, 'equipment_id' => $pc->id, 'category' => 'Сеть', 'description' => 'Линк 100 Мбит/с', 'priority' => 'high',
            'initiator_id' => User::factory()->create(['role' => 'lead'])->id]);
        $t->source = 'link';
        foreach ($attrs as $k => $v) $t->$k = $v;
        $t->save();
        return $t->fresh();
    }

    public function test_engineer_ticks_sides_and_closes(): void
    {
        $this->withoutVite();
        $u = User::factory()->create(['role' => 'specialist']); $c = Club::create(['name' => 'Club', 'address' => 'x']); $u->clubs()->attach($c);
        $t = $this->linkTicket($c);
        DB::table('alerts')->insert(['club_id' => $c->id, 'equipment_id' => $t->equipment_id, 'metric' => 'link_speed', 'state' => 'normal']);

        // Nothing ticked: refused.
        $this->actingAs($u)->postJson('/tickets/'.$t->id.'/link-fix', ['version' => $t->version])->assertUnprocessable();
        $this->assertSame('new', $t->fresh()->status);

        $this->post('/tickets/'.$t->id.'/link-fix', ['version' => $t->version, 'pc_side' => true, 'switch_side' => true])->assertRedirect('/tickets/'.$t->id);
        $t = $t->fresh();
        $this->assertSame('closed', $t->status);
        $this->assertSame("Обжат со стороны ПК\nОбжат со стороны сетевого коммутатора", $t->work_result);
        $this->assertSame('Мониторинг: скорость линка в норме.', $t->verification_result);
        $this->assertSame($u->id, $t->assignee_id);
        $this->assertNotNull($t->closed_at);
        $this->assertDatabaseHas('ticket_events', ['ticket_id' => $t->id, 'from_status' => 'new', 'to_status' => 'closed']);
        // Already closed: no second close.
        $this->postJson('/tickets/'.$t->id.'/link-fix', ['version' => $t->version, 'pc_side' => true])->assertUnprocessable();
    }

    public function test_only_link_tickets_stale_version_and_representative(): void
    {
        $u = User::factory()->create(['role' => 'specialist']); $c = Club::create(['name' => 'Club', 'address' => 'x']); $u->clubs()->attach($c);
        $t = $this->linkTicket($c);
        $this->actingAs($u)->postJson('/tickets/'.$t->id.'/link-fix', ['version' => $t->version + 5, 'pc_side' => true])->assertStatus(409);
        $rep = User::factory()->create(['role' => 'representative']); $rep->clubs()->attach($c);
        $this->actingAs($rep)->postJson('/tickets/'.$t->id.'/link-fix', ['version' => $t->version, 'pc_side' => true])->assertForbidden();
        $plain = Ticket::create(['club_id' => $c->id, 'initiator_id' => $u->id, 'category' => 'Сеть', 'description' => 'x']);
        $this->actingAs($u)->postJson('/tickets/'.$plain->id.'/link-fix', ['version' => 1, 'pc_side' => true])->assertUnprocessable();
        $this->assertSame('new', $t->fresh()->status);
    }
}
