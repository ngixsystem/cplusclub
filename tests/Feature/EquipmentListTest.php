<?php

namespace Tests\Feature;

use App\Models\{Club, Equipment, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EquipmentListTest extends TestCase
{
    use RefreshDatabase;

    public function test_equipment_is_split_by_club_filtered_by_type_and_sorted_naturally(): void
    {
        $this->withoutVite();
        $owner = User::factory()->create(['role' => 'owner']);
        $spot = Club::create(['name' => 'SPOT', 'address' => 'x']);
        $team = Club::create(['name' => 'TeamPro', 'address' => 'y']);
        foreach (['PC10', 'PC2', 'PC1'] as $n) Equipment::create(['club_id' => $spot->id, 'name' => $n, 'type' => 'pc']);
        $sw = Equipment::create(['club_id' => $spot->id, 'name' => 'SW1', 'type' => 'switch']);
        Equipment::create(['club_id' => $spot->id, 'name' => 'ИБП', 'type' => 'ups']);
        Equipment::create(['club_id' => $team->id, 'name' => 'PC1', 'type' => 'pc']);
        $pc2 = Equipment::where('name', 'PC2')->first();
        DB::table('switch_ports')->insert(['switch_id' => $sw->id, 'if_index' => 7, 'port_no' => 7, 'name' => 'ge7', 'up' => true, 'speed_mbps' => 100]);
        DB::table('equipment_ports')->insert(['equipment_id' => $pc2->id, 'switch_id' => $sw->id, 'if_index' => 7, 'seen_at' => now()]);

        // Default: first club by name, switches first, then PC1, PC2, PC10.
        $this->actingAs($owner)->get('/equipment')->assertInertia(fn (Assert $p) => $p
            ->where('equipmentFilter.club', $spot->id)
            ->where('equipmentFilter.typeCounts.pc', 3)->where('equipmentFilter.typeCounts.switch', 1)
            ->has('rows.data', 5)
            ->where('rows.data.0.name', 'SW1')->where('rows.data.1.name', 'PC1')->where('rows.data.2.name', 'PC2')->where('rows.data.3.name', 'PC10')
            ->where('rows.data.2.cable.switch', 'SW1')->where('rows.data.2.cable.port', 7));
        $this->get('/equipment?club='.$team->id)->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.club.name', 'TeamPro'));
        $this->get('/equipment?club=all')->assertInertia(fn (Assert $p) => $p->has('rows.data', 6));
        $this->get('/equipment?club='.$spot->id.'&type=switch')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1));
        $this->get('/equipment?club='.$spot->id.'&type=other')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.name', 'ИБП'));

        // A specialist of TeamPro cannot pick SPOT.
        $spec = User::factory()->create(['role' => 'specialist']); $spec->clubs()->attach($team);
        $this->actingAs($spec)->get('/equipment?club='.$spot->id)->assertInertia(fn (Assert $p) => $p->where('equipmentFilter.club', $team->id)->has('rows.data', 1));
    }
}
