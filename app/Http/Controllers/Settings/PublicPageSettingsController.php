<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\PublicPage;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Owners set up the public page: whether it is live, the headline and bio, links,
 * which blocks show (booking, ordering, payment) and the booking rules.
 */
class PublicPageSettingsController extends Controller
{
    public function __construct(protected WorkspaceContext $context, protected PublicPage $page) {}

    public function edit(): View
    {
        $workspace = $this->context->getOrFail();

        return view('settings.public-page', [
            'workspace' => $workspace,
            'settings' => $this->page->settings($workspace),
            'available' => $this->page->availableBlocks($workspace),
            'pageUrl' => route('public.show', $workspace),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'headline' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:500'],
            'links' => ['array', 'max:'.PublicPage::MAX_LINKS],
            'links.*.label' => ['nullable', 'required_with:links.*.url', 'string', 'max:60'],
            'links.*.url' => ['nullable', 'required_with:links.*.label', 'url:http,https', 'max:255'],
            'blocks' => ['array'],
            'blocks.*' => [Rule::in(array_keys(PublicPage::BLOCKS))],
            'min_notice_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'max_days_ahead' => ['required', 'integer', 'min:1', 'max:365'],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $this->page->save($workspace, [
            'enabled' => $request->boolean('enabled'),
            'headline' => trim((string) ($data['headline'] ?? '')) ?: null,
            'bio' => trim((string) ($data['bio'] ?? '')) ?: null,
            'links' => collect($data['links'] ?? [])
                ->filter(fn (array $link) => filled($link['label'] ?? null) && filled($link['url'] ?? null))
                ->map(fn (array $link) => ['label' => trim($link['label']), 'url' => trim($link['url'])])
                ->values()->all(),
            'blocks' => array_values(array_unique($data['blocks'] ?? [])),
            'min_notice_hours' => (int) $data['min_notice_hours'],
            'max_days_ahead' => (int) $data['max_days_ahead'],
            'capacity' => (int) $data['capacity'],
        ]);
        Audit::log('settings', 'public-page-updated', 'Updated the public page');

        return back()->with('flash', ['type' => 'success', 'message' => $request->boolean('enabled') ? 'Public page saved. It is live.' : 'Public page saved. It is switched off.']);
    }
}
