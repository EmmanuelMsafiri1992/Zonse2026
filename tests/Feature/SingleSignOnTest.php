<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class SingleSignOnTest extends TestCase
{
    use InteractsWithWorkspaces, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google' => ['client_id' => 'google-client', 'client_secret' => 'google-secret'],
            'services.microsoft' => ['client_id' => 'ms-client', 'client_secret' => 'ms-secret', 'tenant' => 'common'],
        ]);
        Http::preventStrayRequests();
    }

    /** @param  array<string, mixed>  $profile */
    protected function fakeProvider(array $profile): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-token', 'token_type' => 'Bearer']),
            'openidconnect.googleapis.com/*' => Http::response($profile),
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'ms-token', 'token_type' => 'Bearer']),
            'graph.microsoft.com/*' => Http::response($profile),
        ]);
    }

    /** Starts the flow so the session holds a state, then returns the callback URL the provider would send back. */
    protected function startFlow(string $provider = 'google'): string
    {
        $location = $this->get(route('sso.redirect', $provider))->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return route('sso.callback', ['provider' => $provider, 'code' => 'auth-code', 'state' => $query['state']]);
    }

    public function test_redirect_sends_the_user_to_google_with_state_and_pkce(): void
    {
        $location = $this->get(route('sso.redirect', 'google'))->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('google-client', $query['client_id']);
        $this->assertSame(route('sso.callback', 'google'), $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(session('sso.state'), $query['state']);
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', session('sso.verifier'), true)), '+/', '-_'), '='),
            $query['code_challenge'],
        );
    }

    public function test_unconfigured_provider_is_not_found_and_hides_its_button(): void
    {
        config(['services.microsoft.client_id' => null]);

        $this->get(route('sso.redirect', 'microsoft'))->assertNotFound();
        $this->get(route('login'))->assertOk()->assertSee('Continue with Google')->assertDontSee('Continue with Microsoft');

        config(['services.google.client_id' => null]);
        $this->get(route('login'))->assertDontSee('Continue with Google');
    }

    public function test_new_google_user_gets_an_account_and_is_signed_in(): void
    {
        $this->fakeProvider(['sub' => 'g-123', 'email' => 'Amara@Example.com', 'email_verified' => true, 'name' => 'Amara Phiri']);

        $this->get($this->startFlow())->assertRedirect(config('fortify.home'));

        $user = User::where('email', 'amara@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Amara Phiri', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-123']);

        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['code'] === 'auth-code' && $request['code_verifier'] !== null && $request['grant_type'] === 'authorization_code');
    }

    public function test_linked_account_signs_in_even_when_the_email_changed(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        SocialAccount::factory()->create(['user_id' => $owner->id, 'provider' => 'google', 'provider_user_id' => 'g-555']);
        $this->fakeProvider(['sub' => 'g-555', 'email' => 'someone-else@example.com', 'email_verified' => true]);

        $this->get($this->startFlow())->assertRedirect(config('fortify.home'));

        $this->assertAuthenticatedAs($owner);
        $this->assertSame(1, User::count());
        $this->assertNotNull($owner->socialAccounts()->first()->last_used_at);
    }

    public function test_verified_google_email_joins_the_existing_account(): void
    {
        $existing = User::factory()->create(['email' => 'chipo@example.com']);
        $this->fakeProvider(['sub' => 'g-777', 'email' => 'chipo@example.com', 'email_verified' => true]);

        $this->get($this->startFlow());

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::count());
        $this->assertDatabaseHas('social_accounts', ['user_id' => $existing->id, 'provider_user_id' => 'g-777']);
    }

    public function test_unverified_google_email_does_not_take_over_an_existing_account(): void
    {
        User::factory()->create(['email' => 'chipo@example.com']);
        $this->fakeProvider(['sub' => 'g-778', 'email' => 'chipo@example.com', 'email_verified' => false]);

        $this->get($this->startFlow())->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_microsoft_never_matches_an_existing_account_by_email(): void
    {
        User::factory()->create(['email' => 'boss@example.com']);
        $this->fakeProvider(['sub' => 'ms-abc', 'email' => 'boss@example.com', 'name' => 'Not The Boss']);

        $this->get($this->startFlow('microsoft'))->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_new_microsoft_user_is_created_without_a_verified_email(): void
    {
        $this->fakeProvider(['sub' => 'ms-new', 'email' => 'tendai@contoso.com', 'name' => 'Tendai']);

        $this->get($this->startFlow('microsoft'))->assertRedirect(config('fortify.home'));

        $user = User::where('email', 'tendai@contoso.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->email_verified_at);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://login.microsoftonline.com/common/oauth2/v2.0/token'));
    }

    public function test_state_mismatch_is_rejected_without_calling_the_provider(): void
    {
        $this->fakeProvider(['sub' => 'g-1', 'email' => 'x@example.com', 'email_verified' => true]);
        $this->startFlow();

        $this->get(route('sso.callback', ['provider' => 'google', 'code' => 'auth-code', 'state' => 'forged']))
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_provider_error_and_refused_code_are_reported_to_the_user(): void
    {
        $url = $this->startFlow();
        $this->get(str_replace('code=auth-code', 'error=access_denied', $url))->assertRedirect(route('login'))->assertSessionHasErrors('email');

        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->get($this->startFlow())->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_user_with_two_factor_must_still_pass_the_challenge(): void
    {
        $user = User::factory()->create(['email' => 'safe@example.com']);
        $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
        SocialAccount::factory()->create(['user_id' => $user->id, 'provider_user_id' => 'g-2fa']);
        $this->fakeProvider(['sub' => 'g-2fa', 'email' => 'safe@example.com', 'email_verified' => true]);

        $this->get($this->startFlow())->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertSame($user->id, session('login.id'));
    }

    public function test_signed_in_user_links_and_unlinks_google_from_the_profile(): void
    {
        $this->seedCatalogue();
        [$owner] = $this->ownerWithWorkspace();
        $this->actingAs($owner);
        $this->fakeProvider(['sub' => 'g-link', 'email' => 'owner-google@example.com', 'email_verified' => true]);

        $this->get($this->startFlow())->assertRedirect(route('profile.edit'));
        $this->assertDatabaseHas('social_accounts', ['user_id' => $owner->id, 'provider' => 'google', 'email' => 'owner-google@example.com']);
        $this->assertTrue(Activity::where('event', 'sso-linked')->exists());

        $this->get(route('profile.edit'))->assertOk()->assertSee('Linked as owner-google@example.com');

        $this->delete(route('sso.destroy', 'google'))->assertRedirect();
        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertTrue(Activity::where('event', 'sso-unlinked')->exists());
    }

    public function test_cannot_link_an_account_that_belongs_to_another_user(): void
    {
        $this->seedCatalogue();
        [$owner] = $this->ownerWithWorkspace();
        $other = SocialAccount::factory()->create(['provider_user_id' => 'g-taken']);
        $this->actingAs($owner);
        $this->fakeProvider(['sub' => 'g-taken', 'email' => 'x@example.com', 'email_verified' => true]);

        $this->get($this->startFlow())->assertRedirect(route('profile.edit'))->assertSessionHas('flash.type', 'danger');

        $this->assertSame(0, $owner->socialAccounts()->count());
        $this->assertSame($other->user_id, $other->fresh()->user_id);
    }

    public function test_profile_lists_passkeys_and_they_can_be_removed_after_confirming_the_password(): void
    {
        $this->seedCatalogue();
        [$owner] = $this->ownerWithWorkspace();
        $passkey = $owner->passkeys()->create(['name' => 'Work laptop', 'credential_id' => 'cred-1', 'credential' => ['id' => 'cred-1']]);

        $this->actingAs($owner)->get(route('profile.edit'))->assertOk()->assertSee('Work laptop')->assertSee('Add passkey');

        $this->delete(route('passkey.destroy', $passkey))->assertRedirect(route('password.confirm'));
        $this->assertModelExists($passkey);

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('passkey.destroy', $passkey))->assertRedirect();
        $this->assertModelMissing($passkey);
        $this->assertTrue(Activity::where('event', 'passkey-removed')->exists());
    }

    public function test_passkey_registration_options_require_a_recent_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('passkey.registration-options'))->assertStatus(423);

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->getJson(route('passkey.registration-options'))
            ->assertOk()->assertJsonStructure(['options' => ['challenge', 'rp', 'user' => ['id', 'name']]]);
    }

    public function test_passkey_confirm_route_returns_to_the_profile(): void
    {
        $this->seedCatalogue();
        [$owner] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->get(route('profile.passkeys.confirm'))->assertRedirect(route('password.confirm'));
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('profile.passkeys.confirm'))->assertRedirect(route('profile.edit'));
    }

    public function test_guest_gets_passkey_login_options_and_sees_the_passkey_button(): void
    {
        $this->getJson(route('passkey.login-options'))->assertOk()->assertJsonStructure(['options' => ['challenge']]);
        $this->get(route('login'))->assertSee('Sign in with a passkey');
    }

    public function test_bad_passkey_login_is_refused(): void
    {
        $this->getJson(route('passkey.login-options'));

        $this->postJson(route('passkey.login'), [
            'credential' => ['id' => 'nope', 'rawId' => 'nope', 'type' => 'public-key', 'response' => ['clientDataJSON' => 'e30']],
        ])->assertStatus(422);

        $this->assertGuest();
    }
}
