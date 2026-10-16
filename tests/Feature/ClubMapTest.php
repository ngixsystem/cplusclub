<?php

namespace Tests\Feature;

use App\Domain\ClubMap\{ClubMapStatus, MapLayout};
use App\Models\{Club, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

class ClubMapTest extends TestCase
{
    use RefreshDatabase;

    private function layout(): array
    {
        return ['version' => 1, 'name' => 'Test floor', 'wall_height' => 2.7, 'size' => [20, 10],
            'floor' => [[0, 0], [20, 0], [20, 10], [0, 10]],
            'zones' => [['id' => 'vip-a', 'type' => 'vip', 'label' => 'VIP 91–100', 'poly' => [[0, 0], [10, 0], [10, 10], [0, 10]], 'center' => [5, 5]]],
            'walls' => [['a' => [0, 0], 'b' => [20, 0], 'kind' => 'outer']],
            'computers' => [
                ['number' => 1, 'zone' => 'vip-a', 'tier' => 'vip', 'pos' => [2, 2], 'facing' => [0, -1]],
                ['number' => 171, 'zone' => 'vip-a', 'tier' => 'vip', 'pos' => [4, 2], 'facing' => [0, -1]],
            ],
            'furniture' => [['kind' => 'sofa', 'pos' => [8, 8], 'size' => [2, 0.8]], ['kind' => 'rocket', 'pos' => [1, 1], 'size' => [1, 1]]]];
    }

    private function club(string $name, bool $icafe = true, bool $map = true, int $license = 123): Club
    {
        $club = Club::create(['name' => $name, 'address' => 'Test']);
        if ($icafe) { $club->icafe_license = $license; $club->icafe_token = str_repeat('secret-', 8); }
        if ($map) $club->map_layout = MapLayout::normalise($this->layout(), [171 => 71]);
        $club->save();
        return $club;
    }

    public function test_layout_is_validated_renumbered_and_relabelled(): void
    {
        $l = MapLayout::normalise($this->layout(), [171 => 71]);
        $this->assertSame([1, 71], array_column($l['computers'], 'number'));
        $this->assertSame('VIP 1–71', $l['zones'][0]['label']);
        $this->assertCount(1, $l['furniture']);

        $this->expectException(InvalidArgumentException::class);
        MapLayout::normalise($this->layout(), [171 => 1]);
    }

    public function test_representative_sees_only_own_club_map_and_layout_is_never_serialised_with_the_club(): void
    {
        $own = $this->club('Own'); $other = $this->club('Other', license: 456); $this->club('No map', map: false, license: 789);
        $rep = User::factory()->create(['role' => 'representative']);
        $rep->clubs()->attach($own);

        $this->actingAs($rep)->get('/club-map?club='.$other->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('ClubMap', false)
            ->has('clubs', 1)->where('club.id', $own->id)->where('layout.computers.1.number', 71));
        $this->assertArrayNotHasKey('map_layout', $own->fresh()->toArray());

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->get('/club-map')->assertInertia(fn (Assert $p) => $p->has('clubs', 2));
    }

    public function test_status_combines_pcs_and_online_list_without_member_data(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/pcs' => Http::response(['code' => 200, 'data' => [
                ['pc_icafe_id' => 123, 'pc_name' => '001', 'pc_console_type' => 0, 'pc_in_using' => 1, 'pc_area_name' => 'VIP', 'status_member_account' => 'secret-member'],
                ['pc_icafe_id' => 123, 'pc_name' => '071', 'pc_console_type' => 0, 'pc_in_using' => 0, 'pc_area_name' => 'VIP'],
                ['pc_icafe_id' => 999, 'pc_name' => '002', 'pc_console_type' => 0, 'pc_in_using' => 1],
                ['pc_icafe_id' => 123, 'pc_name' => 'PS5-3', 'pc_console_type' => 5, 'pc_in_using' => 1],
            ]]),
            '*/onlinePcList' => Http::response(['code' => 200, 'data' => [['pc_name' => '001', 'is_connected' => 1], ['pc_name' => '071', 'is_connected' => 0]]]),
        ]);
        $own = $this->club('Own'); $other = $this->club('Other', license: 456);
        $rep = User::factory()->create(['role' => 'representative']);
        $rep->clubs()->attach($own);

        $res = $this->actingAs($rep)->getJson("/clubs/{$own->id}/map/status")->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('computers.1.busy', true)->assertJsonPath('computers.1.online', true)
            ->assertJsonPath('computers.71.online', false)->assertJsonPath('computers.71.busy', false)
            ->assertJsonMissingPath('computers.2')->assertJsonMissingPath('computers.3');
        $this->assertStringNotContainsString('secret-member', $res->getContent());
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        $this->actingAs($rep)->getJson("/clubs/{$other->id}/map/status")->assertForbidden();
    }

    public function test_status_without_map_or_icafe(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'owner']);
        $noMap = $this->club('No map', map: false);
        $noIcafe = $this->club('No iCafe', icafe: false);
        $this->actingAs($owner)->getJson("/clubs/{$noMap->id}/map/status")->assertNotFound();
        $this->actingAs($owner)->getJson("/clubs/{$noIcafe->id}/map/status")->assertOk()->assertJsonPath('connected', false)->assertJsonPath('computers', []);
    }

    public function test_number_parsing(): void
    {
        $this->assertSame(7, ClubMapStatus::number('007'));
        $this->assertSame(81, ClubMapStatus::number('VIP 081'));
        $this->assertNull(ClubMapStatus::number('Kassa'));
    }

    public function test_import_command(): void
    {
        $club = $this->club('Own', map: false);
        $file = tempnam(sys_get_temp_dir(), 'map');
        file_put_contents($file, json_encode($this->layout()));
        $this->artisan('cclub:club-map', ['club' => $club->id, 'file' => $file, '--renumber' => ['171=71']])->assertSuccessful();
        $this->assertSame(71, $club->fresh()->map_layout['computers'][1]['number']);
        $this->artisan('cclub:club-map', ['club' => $club->id, '--remove' => true])->assertSuccessful();
        $this->assertNull($club->fresh()->map_layout);
        unlink($file);
    }
}
