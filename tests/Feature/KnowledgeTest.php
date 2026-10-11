<?php

namespace Tests\Feature;

use App\Domain\Knowledge\Library;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class KnowledgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
    }

    public function test_guests_cannot_read_library_or_articles(): void
    {
        $this->get('/knowledge')->assertRedirect('/login');
        $this->get('/knowledge/first-start')->assertRedirect('/login');
    }

    public function test_all_active_roles_can_read_shared_references_without_club_membership(): void
    {
        foreach (['owner', 'lead', 'specialist', 'representative'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get('/knowledge')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Knowledge')->has('articles')->has('categories')->has('audit')->missing('clubs'));
            $this->actingAs($user)->get('/knowledge/super-client')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('KnowledgeArticle')->where('article.id', 'super-client')->has('article.checklist')->has('article.sources'));
        }
    }

    public function test_disabled_and_unknown_roles_are_denied(): void
    {
        foreach ([['role' => 'owner', 'active' => false], ['role' => 'unrecognized', 'active' => true]] as $attributes) {
            $user = User::factory()->create(['active' => $attributes['active']]);
            $user->role = $attributes['role'];
            $this->actingAs($user)->get('/knowledge')->assertForbidden();
            $this->actingAs($user)->get('/knowledge/first-start')->assertForbidden();
        }
    }

    public function test_unknown_article_does_not_expose_files(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'specialist']));
        $this->get('/knowledge/does-not-exist')->assertNotFound();
        $this->get('/knowledge/.env')->assertNotFound();
    }

    public function test_catalogue_is_complete_and_guides_have_valid_sources(): void
    {
        $library = new Library;
        $content = $library->content();
        $audit = json_decode(file_get_contents(resource_path('knowledge/ccboot-audit.json')), true);
        $expected = array_column(array_filter($audit['articles'], fn ($a) => !($a['is_category'] ?? false)), 'id');
        $sources = array_filter($library->articles(), fn ($a) => $a['kind'] === 'source');
        $this->assertEqualsCanonicalizing($expected, array_column($sources, 'id'));
        $this->assertGreaterThanOrEqual(370, count($sources));
        $this->assertCount(count($library->articles()), array_unique(array_column($library->articles(), 'id')));
        $categoryIds = array_column($content['categories'], 'id');
        foreach ($library->articles() as $article) {
            $this->assertContains($article['category'], $categoryIds);
            if ($article['kind'] === 'guide') {
                $this->assertNotEmpty($article['sections']);
                $this->assertNotEmpty($article['checklist']);
                foreach ($article['sources'] as $source) {
                    $this->assertSame('https', parse_url($source['url'], PHP_URL_SCHEME));
                    $this->assertSame('www.ccboot.com', parse_url($source['url'], PHP_URL_HOST));
                    $this->assertContains($source['url'], array_column($sources, 'url'));
                }
            }
        }
    }
}
