<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SocialPost;
use App\Models\User;
use App\Social\PostDraft;
use App\Social\PostPlanner;
use App\Social\SocialPoster;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class FacebookPosterTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
        config(['zonseo.facebook.page_token' => null]);
    }

    public function test_the_series_opens_with_the_introduction_then_each_suite_and_its_apps(): void
    {
        $sequence = app(PostPlanner::class)->sequence();
        $ids = array_map(fn (PostDraft $d) => $d->id(), $sequence);

        $this->assertSame('intro:welcome', $ids[0]);
        $this->assertSame(count($ids), count(array_unique($ids)), 'every post has its own kind:key');
        $this->assertGreaterThan(200, count($sequence));

        $firstSuite = collect($sequence)->search(fn (PostDraft $d) => $d->kind === 'suite');
        $this->assertSame('core', $sequence[$firstSuite]->key);
        $this->assertSame('app', $sequence[$firstSuite + 1]->kind, 'a suite post is followed by its apps');

        $clinic = collect($sequence)->first(fn (PostDraft $d) => $d->id() === 'app:clinic');
        $this->assertNotNull($clinic);
        $this->assertStringContainsString('Patients', $clinic->message);
        $this->assertStringContainsString('https://zonseob.com/register', $clinic->message);
        $this->assertStringContainsString('#Zonseob', $clinic->message);
        $this->assertStringContainsString('Healthcare', $clinic->eyebrow);
        $this->assertNotContains('app:dashboard', $ids, 'the dashboard is not an app people switch on');
    }

    public function test_the_command_posts_the_next_item_once_per_due_slot_and_records_it(): void
    {
        Http::fake([
            'graph.facebook.com/*/61595532235489/photos' => Http::response(['id' => '111', 'post_id' => '61595532235489_222']),
            'graph.facebook.com/*/61595532235489/feed' => Http::response(['id' => '61595532235489_333']),
        ]);
        $this->connect(times: '09:00,15:00', timezone: 'Africa/Johannesburg');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 09:20', 'Africa/Johannesburg'));

        $this->artisan('zonseo:post-to-facebook')->assertSuccessful()->expectsOutputToContain('intro:welcome');

        $post = SocialPost::sole();
        $this->assertSame('posted', $post->status);
        $this->assertSame('61595532235489_222', $post->external_id);
        $this->assertSame('https://www.facebook.com/61595532235489_222', $post->permalink());
        $this->assertSame('2026-10-19 07:00:00', $post->scheduled_for->toDateTimeString());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/photos')
            && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'message' && str_contains($part['contents'], 'Welcome to Zonseob')));

        // The 09:00 slot is filled; nothing more until 15:00.
        $this->artisan('zonseo:post-to-facebook')->assertSuccessful()->expectsOutputToContain('No posting time is due');
        $this->assertSame(1, SocialPost::count());

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 15:05', 'Africa/Johannesburg'));
        $this->artisan('zonseo:post-to-facebook')->assertSuccessful()->expectsOutputToContain('intro:how-it-is-organised');
        $this->assertSame(2, SocialPost::count());
        $this->assertSame('intro:shared-contacts', app(PostPlanner::class)->next()->id());
    }

    public function test_a_failed_post_is_recorded_and_retried_at_the_next_slot(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 400)]);
        $this->connect();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 09:20', 'Africa/Johannesburg'));

        $this->artisan('zonseo:post-to-facebook')->assertFailed();

        $post = SocialPost::sole();
        $this->assertSame('failed', $post->status);
        $this->assertStringContainsString('Invalid OAuth access token', $post->error);
        $this->assertSame('intro:welcome', app(PostPlanner::class)->next()->id(), 'a failed post stays next in line');
    }

    public function test_nothing_happens_until_the_page_is_connected_and_posting_is_on(): void
    {
        Http::fake();

        $this->artisan('zonseo:post-to-facebook')->assertSuccessful()->expectsOutputToContain('not connected');

        $this->connect(enabled: false);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 09:20', 'Africa/Johannesburg'));
        $this->artisan('zonseo:post-to-facebook')->assertSuccessful()->expectsOutputToContain('switched off');

        Http::assertNothingSent();
        $this->assertSame(0, SocialPost::count());
    }

    public function test_only_a_super_admin_sees_the_page_and_the_token_never_appears_in_it(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        $this->actingAs($owner)->get(route('admin.facebook.index'))->assertForbidden();

        $admin = $this->superAdmin();
        $this->actingAs($admin)->get(route('admin.facebook.index'))->assertOk()
            ->assertSee('Facebook posts')->assertSee('One platform, every profession')->assertSee('not connected yet');

        $this->actingAs($admin)->put(route('admin.facebook.settings'), [
            'page_id' => '61595532235489', 'page_token' => 'EAAG-secret-token', 'times' => '08:30, 17:00', 'timezone' => 'Africa/Harare', 'enabled' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $poster = app(SocialPoster::class);
        $this->assertSame('EAAG-secret-token', $poster->token());
        $this->assertSame(['08:30', '17:00'], $poster->times());
        $this->assertTrue($poster->isEnabled());
        $this->assertNotSame('EAAG-secret-token', Setting::platform('facebook.page_token'), 'the token is stored encrypted');

        $this->actingAs($admin)->get(route('admin.facebook.index'))->assertOk()
            ->assertSee('A token is stored')->assertDontSee('EAAG-secret-token');

        $this->actingAs($admin)->put(route('admin.facebook.settings'), [
            'page_id' => 'abc', 'times' => '9am', 'timezone' => 'Mars/Olympus',
        ])->assertSessionHasErrors(['page_id', 'times', 'timezone']);
    }

    public function test_post_now_publishes_the_next_post_from_the_admin_page(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1', 'post_id' => '61595532235489_900'])]);
        $this->connect(enabled: false);

        $this->actingAs($this->superAdmin())->post(route('admin.facebook.post-now'))->assertRedirect();

        $this->assertSame('61595532235489_900', SocialPost::sole()->external_id);
        $this->actingAs($this->superAdmin())->get(route('admin.facebook.index'))->assertOk()->assertSee('61595532235489_900');
    }

    public function test_the_card_for_a_post_is_drawn_on_request(): void
    {
        $this->actingAs($this->superAdmin())->get(route('admin.facebook.card', ['app', 'clinic']))
            ->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->superAdmin())->get(route('admin.facebook.card', ['app', 'no-such-app']))->assertNotFound();
    }

    protected function connect(string $times = '09:00,15:00', string $timezone = 'Africa/Johannesburg', bool $enabled = true): void
    {
        foreach (['facebook.page_id' => '61595532235489', 'facebook.page_token' => Crypt::encryptString('token'), 'facebook.times' => $times, 'facebook.timezone' => $timezone, 'facebook.enabled' => $enabled] as $key => $value) {
            Setting::query()->updateOrCreate(['workspace_id' => null, 'key' => $key], ['value' => ['v' => $value]]);
        }
    }

    protected function superAdmin(): User
    {
        [$admin] = $this->ownerWithWorkspace();
        $admin->forceFill(['is_super_admin' => true])->save();

        return $admin->fresh();
    }
}
