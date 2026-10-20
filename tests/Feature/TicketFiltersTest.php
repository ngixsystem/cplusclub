<?php

namespace Tests\Feature;

use App\Models\{Club, Equipment, Ticket, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TicketFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function seedTickets(): array
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $tech = User::factory()->create(['role' => 'specialist', 'name' => 'Техник']);
        $a = Club::create(['name' => 'SPOT', 'address' => 'Test']);
        $b = Club::create(['name' => 'TeamPro', 'address' => 'Test']);
        $pc = Equipment::create(['club_id' => $a->id, 'name' => 'PC027', 'type' => 'pc']);
        $make = function (Club $c, array $attrs) use ($owner) {
            $t = Ticket::create(['club_id' => $c->id, 'initiator_id' => $owner->id, 'category' => $attrs['category'] ?? 'Диагностика',
                'description' => $attrs['description'] ?? 'Проблема', 'priority' => $attrs['priority'] ?? 'normal',
                'equipment_id' => $attrs['equipment_id'] ?? null, 'assignee_id' => $attrs['assignee_id'] ?? null]);
            foreach (['status', 'created_at'] as $k) if (isset($attrs[$k])) $t->{$k} = $attrs[$k];
            $t->save();
            return $t;
        };
        $t1 = $make($a, ['description' => 'Линк 100 Мбит', 'priority' => 'critical', 'equipment_id' => $pc->id, 'assignee_id' => $tech->id]);
        $t2 = $make($a, ['description' => 'Чистка зала', 'category' => 'Чистка ПК', 'status' => 'parts']);
        $t3 = $make($b, ['description' => 'Не запускается CS2', 'priority' => 'high', 'status' => 'closed', 'created_at' => now()->subDays(40)]);
        return compact('owner', 'tech', 'a', 'b', 'pc', 't1', 't2', 't3');
    }

    public function test_tabs_counts_and_combined_filters(): void
    {
        $this->withoutVite();
        ['owner' => $owner, 'a' => $a, 't1' => $t1, 't2' => $t2, 't3' => $t3] = $this->seedTickets();
        $this->actingAs($owner)->get('/tickets')->assertInertia(fn (Assert $p) => $p
            ->where('ticketCounts', ['all' => 3, 'open' => 1, 'waiting' => 1, 'resolved' => 0, 'closed' => 1])
            ->has('rows.data', 3)->where('filters.tab', 'all'));
        $this->get('/tickets?tab=open')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.id', $t1->id));
        $this->get('/tickets?tab=waiting')->assertInertia(fn (Assert $p) => $p->where('rows.data.0.id', $t2->id));
        $this->get('/tickets?club='.$a->id)->assertInertia(fn (Assert $p) => $p->has('rows.data', 2)->where('ticketCounts.all', 2));
        $this->get('/tickets?priority=critical,high')->assertInertia(fn (Assert $p) => $p->has('rows.data', 2));
        $this->get('/tickets?assignee=none')->assertInertia(fn (Assert $p) => $p->has('rows.data', 2));
        $this->get('/tickets?category='.urlencode('Чистка ПК'))->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.id', $t2->id));
        $this->get('/tickets?period=30')->assertInertia(fn (Assert $p) => $p->has('rows.data', 2));
        $this->get('/tickets?sort=urgent')->assertInertia(fn (Assert $p) => $p->where('rows.data.0.id', $t1->id)->where('rows.data.1.id', $t3->id));
        // Garbage values are ignored instead of breaking the page.
        $this->get('/tickets?tab=x&priority=drop;table&sort=1&assignee=-5&period=13')->assertInertia(fn (Assert $p) => $p->has('rows.data', 3)->where('filters.sort', 'new'));
    }

    public function test_search_by_number_text_and_pc_name(): void
    {
        $this->withoutVite();
        ['owner' => $owner, 't1' => $t1, 't3' => $t3] = $this->seedTickets();
        $this->actingAs($owner)->get('/tickets?search='.urlencode('#'.$t3->id))->assertInertia(fn (Assert $p) => $p->where('rows.data.0.id', $t3->id));
        $this->get('/tickets?search=pc027')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.id', $t1->id));
        $this->get('/tickets?search=cs2')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.id', $t3->id));
        $this->get('/tickets?search=100%25')->assertInertia(fn (Assert $p) => $p->has('rows.data', 0));
    }

    public function test_filters_never_widen_club_access(): void
    {
        $this->withoutVite();
        ['a' => $a, 'b' => $b] = $this->seedTickets();
        $rep = User::factory()->create(['role' => 'representative']);
        $rep->clubs()->attach($a);
        $this->actingAs($rep)->get('/tickets?club='.$b->id)->assertInertia(fn (Assert $p) => $p
            ->has('rows.data', 2)->where('filters.club', null)->where('ticketCounts.all', 2)
            ->where('filterOptions.categories', ['Диагностика', 'Чистка ПК']));
    }
}
