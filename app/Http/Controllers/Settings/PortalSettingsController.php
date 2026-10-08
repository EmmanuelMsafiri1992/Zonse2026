<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\PortalAccess;
use App\Notifications\PortalSignInLink;
use App\Support\Audit;
use App\Support\Portal;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Contacts\Models\Contact;

/**
 * Staff side of the client portal: who has access (customers, suppliers, patients, tenants…),
 * invitations and fresh sign-in links, and what the portal shows.
 */
class PortalSettingsController extends Controller
{
    public function __construct(protected WorkspaceContext $context, protected Portal $portal) {}

    public function index(Request $request): View
    {
        $workspace = $this->context->getOrFail();
        $accesses = PortalAccess::with('contact')->latest('id')->get();

        return view('settings.portal', [
            'workspace' => $workspace,
            'accesses' => $accesses,
            'contacts' => Contact::active()->whereNotIn('id', $accesses->pluck('contact_id'))->orderBy('name')->limit(500)->get(),
            'audiences' => PortalAccess::AUDIENCES,
            'available' => $this->portal->availableSections($workspace),
            'hidden' => (array) $workspace->setting('portal.hidden_sections', []),
            'welcome' => $this->portal->welcome($workspace),
            'loginUrl' => route('portal.login', $workspace),
            'selectedContact' => $request->integer('contact') ?: null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $data = $request->validate([
            'welcome' => ['nullable', 'string', 'max:'.Portal::WELCOME_MAX],
            'sections' => ['array'],
            'sections.*' => [Rule::in(array_keys(Portal::SECTIONS))],
        ]);

        $shown = $data['sections'] ?? [];
        $settings = $workspace->settings ?? [];
        data_set($settings, 'portal.welcome', trim((string) ($data['welcome'] ?? '')) ?: null);
        data_set($settings, 'portal.hidden_sections', array_values(array_diff(array_keys(Portal::SECTIONS), $shown)));
        $workspace->forceFill(['settings' => $settings])->save();
        Audit::log('settings', 'portal-updated', 'Updated the client portal settings');

        return back()->with('flash', ['type' => 'success', 'message' => 'Portal settings saved.']);
    }

    public function invite(Request $request): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $data = $request->validate([
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where('workspace_id', $workspace->id)->whereNull('deleted_at')],
            'audience' => ['required', Rule::in(array_keys(PortalAccess::AUDIENCES))],
            'email' => ['nullable', 'email', 'max:190'],
        ]);

        $contact = Contact::findOrFail($data['contact_id']);
        $email = mb_strtolower(trim($data['email'] ?? '') ?: (string) $contact->email);
        if ($email === '') {
            return back()->withInput()->withErrors(['email' => $contact->displayName().' has no email address. Enter one to send the invitation to.']);
        }

        $access = PortalAccess::updateOrCreate(
            ['contact_id' => $contact->id],
            ['audience' => $data['audience'], 'email' => $email, 'status' => 'active', 'invited_at' => now(), 'created_by' => $request->user()->id],
        );

        $this->sendLink($access, invitation: true);
        Audit::log('settings', 'portal-invited', 'Gave '.$contact->displayName().' portal access', $contact, ['audience' => $access->audience]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Invitation sent to '.$email.'.']);
    }

    public function resend(PortalAccess $access): RedirectResponse
    {
        abort_unless($access->isActive(), 422, 'Turn this access back on before sending a link.');
        $this->sendLink($access);

        return back()->with('flash', ['type' => 'success', 'message' => 'A new sign-in link was sent to '.$access->email.'.']);
    }

    public function toggle(PortalAccess $access): RedirectResponse
    {
        $active = ! $access->isActive();
        $access->forceFill([
            'status' => $active ? 'active' : 'disabled',
            'login_token_hash' => null,
            'login_token_expires_at' => null,
        ])->save();
        Audit::log('settings', $active ? 'portal-enabled' : 'portal-disabled', ($active ? 'Restored' : 'Turned off').' portal access for '.$access->contact?->displayName(), $access->contact);

        return back()->with('flash', ['type' => 'success', 'message' => $active ? 'Portal access restored.' : 'Portal access turned off. Any open portal session ends on the next page.']);
    }

    public function destroy(PortalAccess $access): RedirectResponse
    {
        $name = $access->contact?->displayName() ?? $access->email;
        $access->delete();
        Audit::log('settings', 'portal-removed', 'Removed portal access for '.$name);

        return back()->with('flash', ['type' => 'success', 'message' => 'Portal access removed.']);
    }

    protected function sendLink(PortalAccess $access, bool $invitation = false): void
    {
        $workspace = $this->context->getOrFail();
        Notification::route('mail', $access->email)
            ->notify(new PortalSignInLink($workspace, $access, $access->issueLoginToken(), $invitation));
    }
}
