<?php

namespace Tests\Feature;

use App\Models\Workspace;
use App\Support\DomainVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_owner_sets_a_brand_name_and_colour_that_repaint_the_workspace(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->get(route('settings.branding.edit'))->assertOk()->assertSee('Custom domain');

        $this->actingAs($owner)->put(route('settings.branding.update'), ['brand_name' => '  Moyo Health ', 'brand_color' => '#12a150'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $workspace->refresh();
        $this->assertSame('Moyo Health', $workspace->setting('branding.name'));
        $this->assertSame('#12A150', $workspace->setting('branding.color'));

        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('<title>Dashboard · Moyo Health</title>', false)
            ->assertSee('--bs-primary:#12A150', false)
            ->assertSee('One platform, every profession.');
    }

    public function test_light_colours_get_dark_text_on_buttons(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->putSetting('branding.color', '#FFD400');

        $this->actingAs($owner)->get(route('dashboard'))->assertSee('color:#1F1F1F!important', false);
    }

    public function test_the_default_colour_and_bad_colours_add_no_styles(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->put(route('settings.branding.update'), ['brand_name' => '', 'brand_color' => '#0073ea'])->assertSessionHasNoErrors();
        $this->assertNull($workspace->refresh()->setting('branding.color'));
        $this->actingAs($owner)->get(route('dashboard'))->assertDontSee('--bs-primary:', false)->assertSee('· '.config('app.name').'</title>', false);

        $this->actingAs($owner)->put(route('settings.branding.update'), ['brand_color' => 'red;}body{display:none'])
            ->assertSessionHasErrors('brand_color');
        $this->actingAs($owner)->put(route('settings.branding.update'), ['brand_name' => str_repeat('a', 41)])
            ->assertSessionHasErrors('brand_name');
    }

    public function test_members_cannot_change_branding_or_the_domain(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $member = $this->memberOf($workspace);

        $this->actingAs($member)->get(route('settings.branding.edit'))->assertForbidden();
        $this->actingAs($member)->put(route('settings.branding.update'), ['brand_name' => 'Hack'])->assertForbidden();
        $this->actingAs($member)->put(route('settings.branding.domain.update'), ['custom_domain' => 'evil.example.com'])->assertForbidden();
        $this->assertNull($workspace->refresh()->custom_domain);
    }

    public function test_owner_adds_a_custom_domain_and_gets_dns_instructions(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->put(route('settings.branding.domain.update'), ['custom_domain' => ' App.Client-Site.example.com. '])
            ->assertSessionHasNoErrors();

        $workspace->refresh();
        $this->assertSame('app.client-site.example.com', $workspace->custom_domain);
        $this->assertNull($workspace->custom_domain_verified_at);
        $token = $workspace->setting('domain.token');
        $this->assertMatchesRegularExpression('/^zonseo-[A-Za-z0-9]{32}$/', $token);

        $this->actingAs($owner)->get(route('settings.branding.edit'))
            ->assertSee('_zonseo-verify.app.client-site.example.com')
            ->assertSee($token)
            ->assertSee('Waiting for DNS');

        // Saving the same domain again keeps the token the owner already published.
        $this->actingAs($owner)->put(route('settings.branding.domain.update'), ['custom_domain' => 'app.client-site.example.com']);
        $this->assertSame($token, $workspace->refresh()->setting('domain.token'));
    }

    public function test_domains_that_are_not_valid_or_not_yours_are_rejected(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        $other = Workspace::factory()->create();
        $other->forceFill(['custom_domain' => 'taken.example.com'])->save();
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);

        foreach (['https://x.example.com/path', 'not a domain', 'localhost', $appHost, 'shop.'.$appHost, 'taken.example.com', '-bad.example.com'] as $domain) {
            $this->actingAs($owner)->put(route('settings.branding.domain.update'), ['custom_domain' => $domain])
                ->assertSessionHasErrors('custom_domain');
        }
    }

    public function test_verifying_checks_the_txt_record(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actingAs($owner)->put(route('settings.branding.domain.update'), ['custom_domain' => 'app.client.example.com']);
        $token = $workspace->refresh()->setting('domain.token');

        $this->mock(DomainVerifier::class, function (MockInterface $mock) use ($token) {
            $mock->shouldReceive('verify')->once()->with('app.client.example.com', $token)->andReturn(false);
            $mock->shouldReceive('verify')->once()->with('app.client.example.com', $token)->andReturn(true);
        });

        $this->actingAs($owner)->post(route('settings.branding.domain.verify'))->assertSessionHas('flash.type', 'danger');
        $this->assertFalse($workspace->refresh()->hasVerifiedDomain());

        $this->actingAs($owner)->post(route('settings.branding.domain.verify'))->assertSessionHas('flash.type', 'success');
        $this->assertTrue($workspace->refresh()->hasVerifiedDomain());
    }

    public function test_the_verifier_matches_the_token_in_quoted_txt_records(): void
    {
        $verifier = $this->partialMock(DomainVerifier::class, function (MockInterface $mock) {
            $mock->shouldReceive('txtRecords')->with('_zonseo-verify.app.client.example.com')->andReturn(['v=spf1 -all', '"zonseo-abc"']);
        });

        $this->assertTrue($verifier->verify('app.client.example.com', 'zonseo-abc'));
        $this->assertFalse($verifier->verify('app.client.example.com', 'zonseo-xyz'));
    }

    public function test_sign_in_pages_on_a_verified_domain_carry_the_workspace_brand(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $workspace->forceFill(['custom_domain' => 'app.client.example.com'])->save();
        $workspace->putSetting('branding', ['name' => 'Moyo Health', 'color' => '#12A150']);

        $this->get('http://app.client.example.com/login')->assertOk()->assertDontSee('Moyo Health');

        $workspace->forceFill(['custom_domain_verified_at' => now()])->save();
        $this->get('http://app.client.example.com/login')->assertOk()
            ->assertSee('Moyo Health')
            ->assertSee('--bs-primary:#12A150', false);

        $this->get(config('app.url').'/login')->assertOk()->assertDontSee('Moyo Health');
    }

    public function test_owner_removes_the_custom_domain(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->forceFill(['custom_domain' => 'app.client.example.com', 'custom_domain_verified_at' => now()])->save();

        $this->actingAs($owner)->delete(route('settings.branding.domain.destroy'))->assertSessionHas('flash.type', 'success');

        $workspace->refresh();
        $this->assertNull($workspace->custom_domain);
        $this->assertFalse($workspace->hasVerifiedDomain());
    }
}
