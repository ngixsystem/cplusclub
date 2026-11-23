<?php

namespace Tests\Feature;

use App\Domain\Knowledge\{Library, Training};
use App\Models\{KnowledgeProgress, User};
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

    public function test_tracks_reference_existing_guides_with_checklists_and_every_role_has_one(): void
    {
        $library = new Library;
        $training = new Training($library);
        foreach ($training->tracks() as $track) {
            $this->assertNotEmpty($track['chapters'], $track['id']);
            $this->assertCount(count($track['chapters']), array_unique($track['chapters']), $track['id']);
            foreach ($track['chapters'] as $id) {
                $article = $library->find($id);
                $this->assertNotNull($article, "$track[id]: $id");
                $this->assertSame('guide', $article->data['kind'], "$track[id]: $id");
                $this->assertNotEmpty($article->data['checklist'], "$track[id]: $id");
            }
        }
        foreach (['owner', 'lead', 'specialist', 'representative'] as $role) {
            $this->assertNotEmpty($training->tracksFor($role), $role);
        }
    }

    public function test_checklist_progress_is_stored_per_user_and_completes_the_chapter(): void
    {
        $user = User::factory()->create(['role' => 'specialist']);
        $other = User::factory()->create(['role' => 'specialist']);
        $this->actingAs($user)->postJson('/knowledge/first-start/progress', ['checked' => [2, 0, 0, 9]])
            ->assertOk()->assertJson(['checked' => [0, 2], 'completed' => false]);
        $this->get('/knowledge/first-start')->assertInertia(fn (Assert $p) => $p->where('progress', [0, 2]));
        $this->actingAs($other)->get('/knowledge/first-start')->assertInertia(fn (Assert $p) => $p->where('progress', []));

        $this->actingAs($user)->postJson('/knowledge/first-start/progress', ['checked' => [0, 1, 2, 3]])
            ->assertOk()->assertJson(['completed' => true]);
        $this->assertNotNull(KnowledgeProgress::where('user_id', $user->id)->value('completed_at'));

        $this->get('/training')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Training')
            ->where('tracks.0.id', 'engineer-start')->where('tracks.0.done', 1)
            ->where('tracks.0.next.id', 'daily-operations')->where('team', null));

        $this->postJson('/knowledge/first-start/progress', ['checked' => [0]])->assertJson(['completed' => false]);
        $this->assertNull(KnowledgeProgress::where('user_id', $user->id)->value('completed_at'));
        $this->postJson('/knowledge/first-start/progress', ['checked' => []])->assertJson(['checked' => []]);
        $this->assertSame(0, KnowledgeProgress::count());
    }

    public function test_progress_rejects_catalogue_entries_bad_input_guests_and_disabled_users(): void
    {
        $this->postJson('/knowledge/first-start/progress', ['checked' => [0]])->assertUnauthorized();
        $user = User::factory()->create(['role' => 'specialist']);
        $this->actingAs($user);
        $source = collect((new Library)->articles())->first(fn ($a) => $a['kind'] === 'source' && empty($a['local_guide_id']));
        $this->postJson("/knowledge/{$source['id']}/progress", ['checked' => [0]])->assertForbidden();
        $this->postJson('/knowledge/does-not-exist/progress', ['checked' => [0]])->assertNotFound();
        $this->postJson('/knowledge/first-start/progress', ['checked' => 'all'])->assertUnprocessable();
        $this->postJson('/knowledge/first-start/progress', ['checked' => [-1]])->assertUnprocessable();
        $this->postJson('/knowledge/first-start/progress', [])->assertUnprocessable();

        $disabled = User::factory()->create(['role' => 'lead', 'active' => false]);
        $this->actingAs($disabled)->postJson('/knowledge/first-start/progress', ['checked' => [0]])->assertForbidden();
        $this->get('/training')->assertForbidden();
        $this->assertSame(0, KnowledgeProgress::count());
    }

    public function test_team_overview_is_scoped_by_reviewer_role(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'name' => 'Owner']);
        $lead = User::factory()->create(['role' => 'lead', 'name' => 'Lead']);
        $engineer = User::factory()->create(['role' => 'specialist', 'name' => 'Engineer']);
        $client = User::factory()->create(['role' => 'representative', 'name' => 'Club']);
        User::factory()->create(['role' => 'specialist', 'active' => false, 'name' => 'Former']);
        $this->actingAs($engineer)->postJson('/knowledge/daily-operations/progress', ['checked' => [0, 1, 2, 3]])->assertOk();

        $this->actingAs($owner)->get('/training')->assertInertia(fn (Assert $p) => $p
            ->where('team', fn ($team) => collect($team)->pluck('name')->sort()->values()->all() === ['Club', 'Engineer', 'Lead']));
        $this->actingAs($lead)->get('/training')->assertInertia(fn (Assert $p) => $p
            ->where('team', fn ($team) => collect($team)->pluck('name')->sort()->values()->all() === ['Engineer', 'Lead'])
            ->where('team', fn ($team) => collect($team)->firstWhere('name', 'Engineer')['tracks'][0]['done'] === 1));
        foreach ([$engineer, $client] as $user) {
            $this->actingAs($user)->get('/training')->assertOk()->assertInertia(fn (Assert $p) => $p->where('team', null));
        }
        $this->actingAs($client)->get('/training')->assertInertia(fn (Assert $p) => $p->where('tracks.0.id', 'club-admin')->has('tracks', 1));
    }

    public function test_knowledge_index_shows_my_tracks(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'lead']))->get('/knowledge')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('training', 2)->where('training.0.next.id', 'daily-operations'));
    }
}
