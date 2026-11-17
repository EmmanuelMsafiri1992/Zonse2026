<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SocialPost;
use App\Models\User;
use App\Social\LinkedInPoster;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class LinkedInPosterTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    protected function connect(bool $enabled = true): void
    {
        foreach (['linkedin.organization_id' => '555', 'linkedin.token' => Crypt::encryptString('li-token'), 'linkedin.token_saved_at' => '2026-11-17 08:00:00', 'linkedin.times' => '10:30,16:30', 'linkedin.enabled' => $enabled] as $key => $value) {
            Setting::query()->updateOrCreate(['workspace_id' => null, 'key' => $key], ['value' => ['v' => $value]]);
        }
    }

    protected function fakeLinkedIn(int $status = 201): void
    {
        Http::fake([
            'api.linkedin.com/rest/images*' => Http::response(['value' => ['uploadUrl' => 'https://upload.example/img', 'image' => 'urn:li:image:1']]),
            'upload.example/*' => Http::response('', 201),
            'api.linkedin.com/rest/posts' => $status === 201
                ? Http::response('', 201, ['x-restli-id' => 'urn:li:share:99'])
                : Http::response(['message' => 'Nope'], $status),
        ]);
    }

    protected function superAdmin(): User
    {
        [$admin] = $this->ownerWithWorkspace();
        $admin->forceFill(['is_super_admin' => true])->save();

        return $admin->fresh();
    }

    public function test_the_command_posts_the_series_once_per_due_slot_as_the_page(): void
    {
        $this->fakeLinkedIn();
        $this->connect();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 10:40', 'Africa/Johannesburg'));

        $this->artisan('zonseo:post-to-linkedin')->assertSuccessful()->expectsOutputToContain('intro:welcome');
        $this->artisan('zonseo:post-to-linkedin')->assertSuccessful()->expectsOutputToContain('No posting time is due');

        $post = SocialPost::sole();
        $this->assertSame('linkedin', $post->channel);
        $this->assertSame('posted', $post->status);
        $this->assertSame('https://www.linkedin.com/feed/update/urn:li:share:99/', $post->permalink());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/rest/posts')
            && $request['author'] === 'urn:li:organization:555'
            && str_contains($request['commentary'], '{hashtag|\#|Zonseob}'));
    }

    public function test_linkedin_has_its_own_progress_through_the_series(): void
    {
        SocialPost::create(['channel' => 'facebook', 'kind' => 'intro', 'key' => 'welcome', 'title' => 'x', 'message' => 'x', 'status' => 'posted']);

        $this->assertSame('intro:welcome', app(LinkedInPoster::class)->remaining()->first()->id());
    }

    public function test_nothing_happens_until_connected_and_switched_on(): void
    {
        Http::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 10:40', 'Africa/Johannesburg'));

        $this->artisan('zonseo:post-to-linkedin')->expectsOutputToContain('not connected');
        $this->connect(enabled: false);
        $this->artisan('zonseo:post-to-linkedin')->expectsOutputToContain('switched off');
        Http::assertNothingSent();
    }

    public function test_a_failure_is_recorded_and_waits_for_the_next_slot(): void
    {
        $this->fakeLinkedIn(422);
        $this->connect();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 10:40', 'Africa/Johannesburg'));

        $this->artisan('zonseo:post-to-linkedin')->assertFailed();

        $this->assertSame('failed', SocialPost::sole()->status);
        $this->assertNull(app(LinkedInPoster::class)->dueSlot());
    }

    public function test_commentary_escapes_markup_but_keeps_hashtags(): void
    {
        $this->assertSame('Clinic \(beta\) \@ Zonseob {hashtag|\#|Zonseob}', LinkedInPoster::commentary('Clinic (beta) @ Zonseob #Zonseob'));
    }

    public function test_only_a_super_admin_sees_the_page_and_the_token_never_appears(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        $this->actingAs($owner)->get(route('admin.linkedin.index'))->assertForbidden();

        $this->connect();
        $this->actingAs($this->superAdmin())->get(route('admin.linkedin.index'))->assertOk()
            ->assertSee('LinkedIn posts')->assertSee('2027-01-16')->assertDontSee('li-token');
    }

    public function test_a_pasted_token_finds_the_page_and_is_stored_encrypted(): void
    {
        Http::fake([
            'api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => [['role' => 'ADMINISTRATOR', 'organization' => 'urn:li:organization:777']]]),
            'api.linkedin.com/rest/organizations/777' => Http::response(['localizedName' => 'Zonseob']),
        ]);

        $this->actingAs($this->superAdmin())->put(route('admin.linkedin.settings'), ['token' => 'new-token', 'times' => '10:30'])
            ->assertSessionHas('flash.type', 'success');

        $poster = app(LinkedInPoster::class);
        $this->assertSame('777', $poster->settings()['organization_id']);
        $this->assertSame('new-token', $poster->token());
        $this->assertFalse($poster->settings()['enabled']);
    }

    public function test_a_token_linkedin_refuses_is_not_stored(): void
    {
        Http::fake(['api.linkedin.com/*' => Http::response(['message' => 'Invalid access token'], 401)]);

        $this->actingAs($this->superAdmin())->put(route('admin.linkedin.settings'), ['token' => 'bad', 'times' => '10:30'])
            ->assertSessionHas('flash.type', 'danger');

        $this->assertNull(app(LinkedInPoster::class)->token());
    }
}
