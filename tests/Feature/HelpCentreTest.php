<?php

namespace Tests\Feature;

use App\Models\HelpFeedback;
use App\Support\Help\HelpCentre;
use App\Support\Help\Tours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class HelpCentreTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_help_centre_lists_topics_searches_and_hides_guides_for_apps_that_are_off(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->get(route('help.index'))
            ->assertOk()
            ->assertSee('How can we help?')
            ->assertSee('Getting started with your workspace')
            ->assertSee('Importing data')
            ->assertDontSee('Creating and sending invoices');
        $this->actingAs($owner)->get(route('help.show', 'sending-invoices'))->assertNotFound();
        $this->actingAs($owner)->get(route('help.show', 'no-such-guide'))->assertNotFound();

        $workspace->enableModules(['contacts', 'invoicing'], $owner);

        $this->actingAs($owner)->get(route('help.index', ['q' => 'quickbooks spreadsheet']))
            ->assertOk()
            ->assertSee('1 guide for')
            ->assertSee(route('help.show', 'importing-data'));
        $this->actingAs($owner)->get(route('help.index', ['q' => 'zzqx nothing']))->assertOk()->assertSee('No guides match that');

        $this->actingAs($owner)->get(route('help.show', 'sending-invoices'))
            ->assertOk()
            ->assertSee('Record a payment')
            ->assertSee('href="'.route('invoices.index').'"', false)
            ->assertSee('Was this guide helpful?')
            ->assertSee('Quotes and your price list');
    }

    public function test_every_shipped_guide_and_release_note_renders_with_working_links(): void
    {
        $help = app(HelpCentre::class);

        $this->assertGreaterThanOrEqual(10, $help->articles()->count());
        $this->assertNotEmpty($help->releases());
        foreach ($help->articles() as $article) {
            $this->assertArrayHasKey($article->category, HelpCentre::CATEGORIES, $article->slug);
            $this->assertNotSame('', $article->summary, $article->slug);
            $this->assertStringNotContainsString('href="#"', $article->html(), $article->slug.' links to a page that does not exist');
            preg_match_all('/help\/([a-z0-9-]+)"/', $article->html(), $links);
            foreach ($links[1] as $slug) {
                $this->assertNotNull($help->article($slug), $article->slug.' links to a missing guide: '.$slug);
            }
        }
        foreach ($help->releases() as $release) {
            $this->assertStringNotContainsString('href="#"', $release->html());
        }
    }

    public function test_the_help_menu_offers_guides_for_the_page_on_screen(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['contacts'], $owner);

        $this->actingAs($owner)->get(route('contacts.index'))
            ->assertOk()
            ->assertSee('Help with this page')
            ->assertSee(route('help.show', 'adding-contacts'))
            ->assertSee('Take the tour of this page');

        $this->actingAs($owner)->get(route('settings.imports.index'))
            ->assertOk()
            ->assertSee(route('help.show', 'importing-data'));
    }

    public function test_readers_rate_a_guide_once_and_can_change_their_answer(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->post(route('help.feedback', 'importing-data'), ['helpful' => '0', 'comment' => 'How do I import from Pastel?'])
            ->assertRedirect(route('help.show', 'importing-data').'#feedback')
            ->assertSessionHas('flash.message', 'Thanks. We will use your note to improve this guide.');
        $this->actingAs($owner)->post(route('help.feedback', 'importing-data'), ['helpful' => '1'])
            ->assertSessionHas('flash.message', 'Thanks, glad it helped.');

        $feedback = HelpFeedback::query()->sole();
        $this->assertTrue($feedback->helpful);
        $this->assertNull($feedback->comment);
        $this->assertSame($workspace->id, $feedback->workspace_id);

        $this->actingAs($owner)->post(route('help.feedback', 'importing-data'), ['helpful' => 'maybe'])->assertSessionHasErrors('helpful');
        $this->actingAs($owner)->post(route('help.feedback', 'importing-data'), ['helpful' => '1', 'comment' => str_repeat('a', 1001)])->assertSessionHasErrors('comment');
        $this->actingAs($owner)->post(route('help.feedback', 'sending-invoices'), ['helpful' => '1'])->assertNotFound();
        $this->assertSame(1, HelpFeedback::query()->count());

        $this->actingAs($owner)->get(route('help.show', 'importing-data'))->assertOk()->assertSee('You answered');
    }

    public function test_only_platform_staff_see_guide_ratings(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        HelpFeedback::factory()->create(['article' => 'importing-data', 'helpful' => false, 'comment' => 'Needs a Pastel section']);
        HelpFeedback::factory()->create(['article' => 'importing-data', 'helpful' => true]);
        HelpFeedback::factory()->create(['article' => 'getting-started', 'helpful' => true]);

        $this->actingAs($owner)->get(route('help.feedback.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('dashboard'))->assertDontSee('Guide ratings');

        $owner->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($owner)->get(route('help.feedback.index'))
            ->assertOk()
            ->assertSeeInOrder(['Importing data', '50%', 'Getting started with your workspace', '100%'])
            ->assertSee('Needs a Pastel section');
    }

    public function test_whats_new_counts_unread_updates_until_opened(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        $releases = app(HelpCentre::class)->releases();
        $owner->forceFill(['created_at' => $releases->last()->date->copy()->subDay()])->save();

        $this->assertSame($releases->count(), app(HelpCentre::class)->unreadReleases($owner));
        $this->actingAs($owner)->get(route('dashboard'))->assertSee($releases->count().' new');

        $this->actingAs($owner)->get(route('help.releases'))
            ->assertOk()
            ->assertSee($releases->first()->title)
            ->assertSee('data-new-release', false);

        $owner->refresh();
        $this->assertNotNull($owner->release_notes_seen_at);
        $this->assertSame(0, app(HelpCentre::class)->unreadReleases($owner));
        $this->actingAs($owner)->get(route('help.releases'))->assertDontSee('data-new-release', false);
    }

    public function test_tours_start_by_themselves_once_and_can_be_taken_again(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $member = $this->memberOf($workspace);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-tour-key="welcome"', false)
            ->assertSee('Add more apps');
        $this->assertTrue(Tours::isPending('welcome', $owner));

        $this->actingAs($owner)->postJson(route('help.tours.done', 'welcome'), ['outcome' => 'dismissed'])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(['welcome' => 'dismissed'], $owner->fresh()->help_tours);
        $this->assertFalse(Tours::isPending('welcome', $owner->fresh()));
        $this->actingAs($owner)->postJson(route('help.tours.done', 'welcome'), ['outcome' => 'skipped'])->assertUnprocessable();
        $this->actingAs($owner)->postJson(route('help.tours.done', 'nope'), ['outcome' => 'completed'])->assertNotFound();

        $this->actingAs($owner)->get(route('help.index'))->assertSee('Take again');
        $this->actingAs($owner)->post(route('help.tours.start', 'welcome'))->assertRedirect(route('dashboard'));
        $this->assertTrue(Tours::isPending('welcome', $owner->fresh()));

        // Steps and tours a member may not use are left out.
        $memberTour = Tours::forRoute('dashboard', $member, $workspace);
        $this->assertNotContains('[data-tour="add-apps"]', array_column($memberTour['steps'], 'target'));
        $this->actingAs($member)->post(route('help.tours.start', 'settings'))->assertNotFound();
        $this->actingAs($member)->post(route('help.tours.start', 'invoices'))->assertNotFound();
    }

    public function test_markdown_is_rendered_safely(): void
    {
        $path = storage_path('framework/testing/help-'.uniqid());
        File::ensureDirectoryExists($path.'/articles');
        File::put($path.'/articles/safe.md', "---\ntitle: Safe\ncategory: settings\nroutes: settings.*\n---\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(1)) [page](route:settings.members.index) [guide](help:other) [nowhere](route:not.a.route)\n");
        File::put($path.'/articles/other.md', "---\ntitle: Other\nmodule: invoicing\n---\n\nHi");

        try {
            $help = new HelpCentre($path);
            $html = $help->article('safe')->html();

            $this->assertStringNotContainsString('<script>', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html);
            $this->assertStringNotContainsString('javascript:', $html);
            $this->assertStringContainsString('href="'.route('settings.members.index').'"', $html);
            $this->assertStringContainsString('href="'.route('help.show', 'other').'"', $html);
            $this->assertStringContainsString('href="#"', $html);
            $this->assertSame(['safe'], $help->forRoute('settings.branding.edit')->pluck('slug')->all());
        } finally {
            File::deleteDirectory($path);
        }
    }

    public function test_guests_cannot_open_the_help_centre(): void
    {
        $this->get(route('help.index'))->assertRedirect(route('login'));
    }
}
