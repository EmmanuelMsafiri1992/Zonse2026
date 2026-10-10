<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Contacts\Models\Contact;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** The second marketing batch: ads, digital signage, memberships, podcasts, agency job bags and brand assets. */
class MarketingAppsBatchTwoTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_ad_campaigns_work_out_their_costs_and_stop_when_the_budget_is_spent(): void
    {
        $app = 'ads-manager-meta-google';
        [$owner, $workspace] = $this->appWorkspace($app);
        $campaign = fn (string $status, array $data, array $extra = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'campaigns']), ['title' => 'Spring sale', 'status' => $status, 'data' => ['platform' => 'meta', ...$data], ...$extra]);

        $campaign('active', [])->assertSessionHasErrors(['amount' => 'Set a budget before going live.']);
        $campaign('draft', [], ['amount' => 100, 'occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString()])->assertSessionHasErrors(['due_on' => 'The campaign cannot end before it starts.']);
        $campaign('draft', ['impressions' => 10, 'clicks' => 20], ['amount' => 100])->assertSessionHasErrors(['data.clicks' => 'Clicks cannot be more than impressions.']);
        $campaign('draft', [], ['amount' => 100])->assertSessionHasNoErrors();
        $spring = Record::query()->where('entity', 'campaigns')->firstOrFail();

        $this->actingAs($owner)->post($spring->url().'/actions/launch')->assertSessionHas('flash.message', 'Spring sale is live.');
        $this->actingAs($owner)->post($spring->url().'/actions/stats', ['impressions' => 10000, 'clicks' => 200, 'conversions' => 10, 'spend' => 100])
            ->assertSessionHas('flash.message', 'Spring sale: 2.00% click-through, '.$this->money(0.5).' a click, '.$this->money(10).' a conversion.');
        $spring->refresh();
        $this->assertEquals(2.0, $spring->value('_ctr'));
        $this->assertEquals(10.0, $spring->value('_cpm'));
        $this->actingAs($owner)->post($spring->url().'/actions/stats', ['impressions' => 9000, 'clicks' => 200, 'conversions' => 10, 'spend' => 100])->assertSessionHasErrors('impressions');

        $old = $this->record($workspace, $app, 'campaigns', 'Summer', 'active', ['platform' => 'google'], ['amount' => 500, 'due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('ended', $old->fresh()->status);
        $spring->refresh();
        $this->assertSame('paused', $spring->status);
        $this->assertSame('Budget spent', $spring->value('_paused_reason'));
        $this->actingAs($owner)->post($spring->url().'/actions/resume')->assertSessionHasErrors(['amount' => 'The budget is spent; raise it to run again.']);
        $this->actingAs($owner)->post($spring->url().'/actions/end')->assertSessionHas('flash.message', 'Spring sale ended: '.$this->money(100).' spent of '.$this->money(100).'.');

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Budget used');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Results by platform')->assertSee('2.00%');
    }

    public function test_signage_playlists_time_their_loop_and_screens_need_an_active_playlist(): void
    {
        $app = 'digital-signage-tv-display';
        [$owner, $workspace] = $this->appWorkspace($app);
        $playlist = fn (string $title, string $status, string $slides, ?int $seconds = null) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'playlists']), ['title' => $title, 'status' => $status, 'data' => ['slides' => $slides, 'seconds_per_slide' => $seconds]]);

        $playlist('Menu', 'draft', "Soup of the day\n".str_repeat('a', 130))->assertSessionHasErrors(['data.slides' => 'Slide 2 has 130 characters; keep text slides to 120 so they can be read.']);
        $playlist('Menu', 'draft', 'Soup', 2)->assertSessionHasErrors(['data.seconds_per_slide' => 'Show each slide for 3 to 300 seconds.']);
        $playlist('Menu', 'draft', "https://example.com/burger.png\nSoup of the day\n\nhttps://example.com/chips.png", 20)->assertSessionHasNoErrors();
        $playlist('Adverts', 'draft', 'Coming soon')->assertSessionHasNoErrors();
        [$menu, $adverts] = Record::query()->where('entity', 'playlists')->orderBy('id')->get()->all();
        $this->assertEquals(3, $menu->value('_slides'));
        $this->assertEquals(60, $menu->value('_loop_seconds'));
        $this->assertEquals(8, $adverts->value('seconds_per_slide'));

        $this->actingAs($owner)->post($menu->url().'/actions/activate')->assertSessionHas('flash.message', 'Menu is active: 3 slides in a 1 min loop.');

        $screen = fn (string $title, string $status, Record $showing) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'screens']), ['title' => $title, 'status' => $status, 'data' => ['location' => 'Front counter', 'playlist' => $showing->id]]);
        $screen('Counter', 'online', $adverts)->assertSessionHasErrors(['data.playlist' => 'Adverts is still a draft.']);
        $screen('Counter', 'offline', $menu)->assertSessionHasNoErrors();
        $screen(' counter', 'offline', $menu)->assertSessionHasErrors(['title' => 'There is already a screen called counter.']);
        $counter = Record::query()->where('entity', 'screens')->firstOrFail();
        $this->actingAs($owner)->post($counter->url().'/actions/online')->assertSessionHas('flash.message', 'Counter is showing Menu.');

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'playlists', $menu->id]), ['title' => 'Menu', 'status' => 'draft', 'data' => ['slides' => 'Soup', 'seconds_per_slide' => 20]])
            ->assertSessionHasErrors(['status' => '1 online screen is showing this playlist.']);

        $this->actingAs($owner)->get($menu->url())->assertOk()->assertSee('Loop length')->assertSee('Counter');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Screens by location')->assertSee('Front counter');
    }

    public function test_members_join_active_tiers_renew_and_lapse(): void
    {
        $app = 'membership-site-paywall';
        [$owner, $workspace] = $this->appWorkspace($app);
        $monthly = $this->record($workspace, $app, 'tiers', 'Monthly', 'active', ['interval' => 'monthly', 'price' => 10]);
        $gold = $this->record($workspace, $app, 'tiers', 'Gold', 'active', ['interval' => 'yearly', 'price' => 120]);
        $this->record($workspace, $app, 'tiers', 'Lifetime', 'active', ['interval' => 'lifetime', 'price' => 500]);
        $old = $this->record($workspace, $app, 'tiers', 'Starter', 'retired', ['interval' => 'monthly', 'price' => 5]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'tiers']), ['title' => 'gold', 'status' => 'active', 'data' => ['interval' => 'yearly', 'price' => 0]])
            ->assertSessionHasErrors(['title' => 'There is already a tier called gold.', 'data.price' => 'Give the tier a price.']);

        $member = fn (string $title, Record $tier, string $email) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'members']), ['title' => $title, 'status' => 'active', 'occurs_on' => today()->toDateString(), 'data' => ['tier' => $tier->id, 'email' => $email]]);
        $member('Ann', $old, 'ann@example.com')->assertSessionHasErrors(['data.tier' => 'Starter is retired and takes no new members.']);
        $member('Ann', $monthly, ' Ann@Example.com ')->assertSessionHasNoErrors();
        $member('Ann again', $gold, 'ANN@example.com')->assertSessionHasErrors(['data.email' => 'ann@example.com is already an active member (Ann).']);
        $ann = Record::query()->where('entity', 'members')->firstOrFail();
        $this->assertSame('ann@example.com', $ann->value('email'));
        $this->assertTrue($ann->due_on->isSameDay(today()->addMonthNoOverflow()));
        $this->assertEquals(10, $ann->value('_paid'));

        $renewed = today()->addMonthNoOverflow()->addMonthNoOverflow();
        $this->actingAs($owner)->post($ann->url().'/actions/renew')->assertSessionHas('flash.message', 'Ann renewed on Monthly until '.$renewed->format('d M Y').': '.$this->money(10).'.');
        $ann->refresh();
        $this->assertEquals(20, $ann->value('_paid'));
        $this->assertEquals(1, $ann->value('_renewals'));
        $this->actingAs($owner)->post($ann->url().'/actions/cancel')->assertSessionHasErrors('reason');
        $this->actingAs($owner)->post($ann->url().'/actions/cancel', ['reason' => 'Too expensive'])->assertSessionHas('flash.message', 'Ann cancelled: Too expensive.');

        $bob = $this->record($workspace, $app, 'members', 'Bob', 'active', ['tier' => $gold->id, 'email' => 'bob@example.com'], ['occurs_on' => today()->subYear(), 'due_on' => today()->subDay()]);
        $this->record($workspace, $app, 'members', 'Cara', 'active', ['tier' => $gold->id, 'email' => 'cara@example.com']);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $bob->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'content']), ['title' => 'Masterclass', 'status' => 'published', 'data' => ['type' => 'video', 'min_tier' => $gold->id]])
            ->assertSessionHasErrors(['data.url' => 'Add the file or page link before publishing.']);
        $content = $this->record($workspace, $app, 'content', 'Masterclass', 'published', ['type' => 'video', 'min_tier' => $gold->id, 'url' => 'https://example.com/class']);
        $this->actingAs($owner)->get($content->url())->assertOk()->assertSee('Who can see it')->assertSee('Lifetime');

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Monthly recurring revenue')->assertSee($this->money(10));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Members by tier')->assertSee('Joins by month');
    }

    public function test_podcast_episodes_are_numbered_and_published_with_their_audio(): void
    {
        $app = 'podcast-media-hosting';
        [$owner, $workspace] = $this->appWorkspace($app);
        $weekly = $this->record($workspace, $app, 'shows', 'The Weekly', 'active');
        $paused = $this->record($workspace, $app, 'shows', 'Side Show', 'on_hold');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'shows']), ['title' => 'the weekly', 'status' => 'active', 'data' => []])
            ->assertSessionHasErrors(['title' => 'There is already a show called the weekly.']);

        $episode = fn (string $title, string $status, Record $show, array $data = [], array $extra = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'episodes']), ['title' => $title, 'status' => $status, 'data' => ['show' => $show->id, ...$data], ...$extra]);
        $episode('Pilot', 'planned', $weekly)->assertSessionHasNoErrors();
        $episode('Second', 'planned', $weekly)->assertSessionHasNoErrors();
        $episode('Another second', 'planned', $weekly, ['episode_number' => 2])->assertSessionHasErrors(['data.episode_number' => 'The Weekly already has episode 2: Second.']);
        $episode('Straight out', 'published', $weekly, ['duration' => 30])->assertSessionHasErrors(['data.audio_url' => 'Add the audio file before publishing.']);
        $episode('Paused', 'published', $paused, ['audio_url' => 'https://cdn.example.com/a.mp3', 'duration' => 30])->assertSessionHasErrors(['data.show' => 'Side Show is on hold.']);
        [$pilot, $second] = Record::query()->where('entity', 'episodes')->orderBy('id')->get()->all();
        $this->assertEquals(1, $pilot->value('episode_number'));
        $this->assertEquals(2, $second->value('episode_number'));

        $this->actingAs($owner)->post($pilot->url().'/actions/recorded');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'episodes', $pilot->id]), ['title' => 'Pilot', 'status' => 'planned', 'data' => ['show' => $weekly->id, 'episode_number' => 1]])
            ->assertSessionHasErrors(['status' => 'An episode cannot go back to planned.']);
        $this->actingAs($owner)->post($pilot->url().'/actions/edited');
        $this->actingAs($owner)->post($pilot->url().'/actions/publish')->assertSessionHasErrors('audio_url');
        $pilot->refresh()->update(['data' => [...$pilot->data, 'audio_url' => 'https://cdn.example.com/pilot.mp3', 'duration' => 42]]);
        $this->actingAs($owner)->post($pilot->url().'/actions/publish')->assertSessionHas('flash.message', 'Episode 1, Pilot, is out.');
        $this->actingAs($owner)->post($pilot->url().'/actions/downloads', ['downloads' => 500])->assertSessionHas('flash.message', 'Pilot: 500 downloads.');
        $this->actingAs($owner)->post($pilot->url().'/actions/downloads', ['downloads' => 400])->assertSessionHasErrors('downloads');

        $second->update(['status' => 'edited', 'occurs_on' => today(), 'data' => [...$second->data, 'audio_url' => 'https://cdn.example.com/2.mp3', 'duration' => 35]]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('published', $second->fresh()->status);

        $this->actingAs($owner)->get($weekly->url())->assertOk()->assertSee('Episodes out')->assertSee('500');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Top episodes')->assertSee('#1 Pilot');
    }

    public function test_agency_jobs_bill_their_media_with_commission(): void
    {
        $app = 'advertising-agency-job-bags';
        [$owner, $workspace] = $this->appWorkspace($app);
        $acme = Contact::query()->create(['name' => 'Acme Foods', 'type' => 'customer']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), ['title' => 'Launch ad', 'status' => 'approved', 'data' => ['brief' => 'TV launch']])
            ->assertSessionHasErrors(['amount' => 'Agree the job value before it is approved.']);
        $job = $this->record($workspace, $app, 'jobs', 'Launch ad', 'brief', ['brief' => 'TV launch'], ['contact_id' => $acme->id]);

        $this->actingAs($owner)->post($job->url().'/actions/start');
        $this->actingAs($owner)->post($job->url().'/actions/review');
        $this->actingAs($owner)->post($job->url().'/actions/revise', ['changes' => 'Bigger logo'])->assertSessionHas('flash.message', 'Launch ad is back in progress (revision 1): Bigger logo.');
        $this->actingAs($owner)->post($job->url().'/actions/review');
        $this->actingAs($owner)->post($job->url().'/actions/approve')->assertSessionHasErrors('amount');
        $job->refresh()->update(['amount' => 1000]);
        $this->actingAs($owner)->post($job->url().'/actions/approve')->assertSessionHas('flash.message', 'Launch ad approved.');
        $this->actingAs($owner)->post($job->url().'/actions/deliver');

        $booking = fn (string $status, array $data, array $extra = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), ['title' => 'TV spot', 'status' => $status, 'data' => ['job' => $job->id, 'media' => 'tv', ...$data], ...$extra]);
        $booking('confirmed', [])->assertSessionHasErrors(['data.vendor' => 'Name the media owner before confirming.', 'amount' => 'Give the cost before confirming.']);
        $booking('requested', ['insertions' => 0], ['amount' => 2000])->assertSessionHasErrors(['data.insertions' => 'Book at least one insertion.']);
        $booking('requested', [], ['amount' => 2000])->assertSessionHasNoErrors();
        $spot = Record::query()->where('entity', 'bookings')->firstOrFail();
        $this->assertEquals(1, $spot->value('insertions'));

        $this->actingAs($owner)->post($job->url().'/actions/bill')->assertSessionHasErrors(['bookings' => '1 media booking still needs confirming.']);
        $this->actingAs($owner)->post($spot->url().'/actions/confirm')->assertSessionHasErrors('vendor');
        $spot->update(['data' => [...$spot->data, 'vendor' => 'National TV']]);
        $this->actingAs($owner)->post($spot->url().'/actions/confirm')->assertSessionHas('flash.message', 'TV spot confirmed.');
        $this->actingAs($owner)->post($job->url().'/actions/bill')
            ->assertSessionHas('flash.message', 'Launch ad billed: '.$this->money(3300).' ('.$this->money(1000).' work, '.$this->money(2000).' media, '.$this->money(300).' commission).');
        $job->refresh();
        $this->assertSame('billed', $job->status);
        $this->assertEquals(1, $job->value('_revisions'));
        $this->assertEquals(3300, $job->value('_bill_total'));

        $booking('requested', ['vendor' => 'Radio One', 'media' => 'radio'], ['amount' => 300])->assertSessionHasErrors(['data.job' => 'Launch ad has been billed; open a new job bag for more media.']);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'jobs', $job->id]), ['title' => 'Launch ad', 'status' => 'brief', 'amount' => 1000, 'contact_id' => $acme->id, 'data' => ['brief' => 'TV launch']])
            ->assertSessionHasErrors(['status' => 'A job cannot go back to brief.']);

        $this->actingAs($owner)->get($job->url())->assertOk()->assertSee('Commission (15%)')->assertSee($this->money(3300));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Jobs by client')->assertSee('Acme Foods')->assertSee('Media by type');
    }

    public function test_brand_assets_need_the_right_file_and_keep_why_they_were_retired(): void
    {
        $app = 'brand-asset-library';
        [$owner] = $this->appWorkspace($app);
        $asset = fn (string $title, string $type, string $url, string $status = 'draft', string $notes = '') => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'assets']), ['title' => $title, 'status' => $status, 'data' => ['type' => $type, 'file_url' => $url, 'usage_notes' => $notes]]);

        $asset('Main logo', 'logo', 'https://cdn.example.com/intro.mp4')->assertSessionHasErrors(['data.file_url' => 'A .mp4 file is not a logo; use .svg, .png, .jpg, .jpeg, .eps, .ai, .pdf, .webp.']);
        $asset('Main logo', 'logo', 'https://cdn.example.com/brand/logo.SVG?v=2')->assertSessionHasNoErrors();
        $asset('main logo', 'logo', 'https://cdn.example.com/logo2.png')->assertSessionHasErrors(['title' => 'There is already a logo called Main logo.']);
        $asset('Main logo', 'photo', 'https://cdn.example.com/logo-on-van.jpg')->assertSessionHasNoErrors();
        $asset('Brand font', 'font', 'https://drive.example.com/file/abc123')->assertSessionHasNoErrors();
        $asset('Palette', 'colour_palette', 'https://cdn.example.com/palette.pdf', 'draft', 'Blue and white')->assertSessionHasErrors(['data.usage_notes' => 'List the palette\'s hex codes, like #1A73E8, in the usage notes.']);
        $asset('Palette', 'colour_palette', 'https://cdn.example.com/palette.pdf', 'draft', 'Primary #1a73e8, accent #fff and again #1A73E8')->assertSessionHasNoErrors();
        $asset('Old logo', 'logo', 'https://cdn.example.com/old.png', 'retired')->assertSessionHasErrors(['status' => 'Retire the asset with the Retire action so the reason is kept.']);

        $palette = Record::query()->where('entity', 'assets')->where('title', 'Palette')->firstOrFail();
        $this->assertSame(['#1A73E8', '#FFFFFF'], $palette->value('_colours'));
        $logo = Record::query()->where('entity', 'assets')->where('title', 'Main logo')->orderBy('id')->firstOrFail();
        $this->actingAs($owner)->post($logo->url().'/actions/approve')->assertSessionHas('flash.message', 'Main logo is approved for use.');
        $this->actingAs($owner)->post($logo->url().'/actions/retire')->assertSessionHasErrors('reason');
        $this->actingAs($owner)->post($logo->url().'/actions/retire', ['reason' => 'Rebrand'])->assertSessionHas('flash.message', 'Main logo retired: Rebrand.');
        $this->assertSame('retired', $logo->fresh()->status);

        $this->actingAs($owner)->get($palette->url())->assertOk()->assertSee('#1A73E8 #FFFFFF');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Awaiting approval')->assertSee('Brand font');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Assets by type')->assertSee('Rebrand');
    }

    private function money(float $amount): string
    {
        return Money::format($amount);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function appWorkspace(string $app): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules([$app], $owner);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace->fresh()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attributes
     */
    private function record(Workspace $workspace, string $app, string $entity, string $title, string $status, array $data = [], array $attributes = []): Record
    {
        return Record::factory()->ofEntity($app, $entity, $data)->create([
            'workspace_id' => $workspace->id,
            'title' => $title,
            'status' => $status,
            ...$attributes,
        ]);
    }
}
