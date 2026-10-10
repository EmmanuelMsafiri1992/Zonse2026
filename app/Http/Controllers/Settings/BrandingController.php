<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Branding;
use App\Support\DomainVerifier;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Brand name and colour shown across the workspace, and a custom domain (proved with a
 * DNS TXT record) on which the sign-in pages carry the workspace's branding.
 */
class BrandingController extends Controller
{
    public function __construct(protected WorkspaceContext $context, protected Branding $branding, protected DomainVerifier $verifier) {}

    public function edit(): View
    {
        $workspace = $this->context->getOrFail();

        return view('settings.branding', [
            'workspace' => $workspace,
            'brand' => $this->branding->for($workspace),
            'brandName' => $workspace->setting('branding.name'),
            'brandColor' => $workspace->setting('branding.color'),
            'domainToken' => $workspace->setting('domain.token'),
            'recordName' => $workspace->custom_domain ? $this->verifier->recordName($workspace->custom_domain) : null,
            'cnameTarget' => config('zonseo.partners.cname_target') ?: parse_url((string) config('app.url'), PHP_URL_HOST),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $data = $request->validate([
            'brand_name' => ['nullable', 'string', 'max:40'],
            'brand_color' => ['nullable', 'regex:'.Branding::COLOR_PATTERN],
        ], ['brand_color.regex' => 'Pick a colour like #007C8A.']);

        $settings = $workspace->settings ?? [];
        data_set($settings, 'branding.name', filled($data['brand_name'] ?? null) ? trim($data['brand_name']) : null);
        data_set($settings, 'branding.color', filled($data['brand_color'] ?? null) && strcasecmp($data['brand_color'], Branding::DEFAULT_COLOR) !== 0 ? strtoupper($data['brand_color']) : null);
        $workspace->forceFill(['settings' => $settings])->save();

        Audit::log('settings', 'branding-updated', 'Updated the workspace branding', properties: ['name' => data_get($settings, 'branding.name'), 'color' => data_get($settings, 'branding.color')]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Branding saved.']);
    }

    public function updateDomain(Request $request): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $request->merge(['custom_domain' => strtolower(trim((string) $request->input('custom_domain'), " \t\n\r\0\x0B/."))]);
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        $data = $request->validate([
            'custom_domain' => [
                'required', 'string', 'max:190',
                'regex:/^(?!-)(?:[a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$/',
                Rule::notIn([$appHost]),
                'not_regex:/'.preg_quote('.'.$appHost, '/').'$/',
                Rule::unique('workspaces', 'custom_domain')->ignore($workspace->id),
            ],
        ], [
            'custom_domain.regex' => 'Enter a domain like app.yourbusiness.com, without https://.',
            'custom_domain.not_in' => 'Use a domain you own.',
            'custom_domain.not_regex' => 'Use a domain you own.',
            'custom_domain.unique' => 'Another workspace already uses that domain.',
        ]);

        if ($data['custom_domain'] !== $workspace->custom_domain) {
            $workspace->forceFill(['custom_domain' => $data['custom_domain'], 'custom_domain_verified_at' => null])->save();
            $workspace->putSetting('domain.token', 'zonseo-'.Str::random(32));
            Audit::log('settings', 'domain-added', 'Set the custom domain to '.$data['custom_domain']);
        }

        return back()->with('flash', ['type' => 'success', 'message' => 'Domain saved. Add the DNS records below, then press Verify.']);
    }

    public function verifyDomain(): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        abort_unless($workspace->custom_domain && $workspace->setting('domain.token'), 404);

        if (! $this->verifier->verify($workspace->custom_domain, (string) $workspace->setting('domain.token'))) {
            return back()->with('flash', ['type' => 'danger', 'message' => 'The TXT record was not found yet. DNS changes can take up to an hour, so try again shortly.']);
        }

        $workspace->forceFill(['custom_domain_verified_at' => now()])->save();
        Audit::log('settings', 'domain-verified', 'Verified the custom domain '.$workspace->custom_domain);

        return back()->with('flash', ['type' => 'success', 'message' => $workspace->custom_domain.' is verified.']);
    }

    public function destroyDomain(): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $domain = $workspace->custom_domain;
        $workspace->forceFill(['custom_domain' => null, 'custom_domain_verified_at' => null])->save();
        $workspace->putSetting('domain.token', null);
        Audit::log('settings', 'domain-removed', 'Removed the custom domain '.$domain);

        return back()->with('flash', ['type' => 'success', 'message' => 'Custom domain removed.']);
    }
}
