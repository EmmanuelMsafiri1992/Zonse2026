<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Workspace;
use App\Support\Audit;
use App\Support\Partners;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The partner (reseller) program: a referral link, client workspaces set up by the partner,
 * white-label branding passed down to clients, and the monthly commission each client earns.
 */
class PartnerController extends Controller
{
    public function __construct(protected WorkspaceContext $context, protected Partners $partners) {}

    public function index(Request $request): View
    {
        $workspace = $this->context->getOrFail();
        $rows = $workspace->isPartner() ? $this->partners->clientRows($workspace) : collect();
        $memberOf = $request->user()->workspaces()->pluck('workspaces.id')->all();

        return view('settings.partner', [
            'workspace' => $workspace,
            'rows' => $rows,
            'memberOf' => $memberOf,
            'percent' => $this->partners->commissionPercent(),
            'referralUrl' => $workspace->isPartner() ? route('register', ['partner' => $workspace->setting('partner.code')]) : null,
            'totals' => [
                'clients' => $rows->count(),
                'paying' => $rows->where('status', 'paying')->count(),
                'monthly' => $rows->sum('monthly'),
                'commission' => $rows->sum('commission'),
            ],
        ]);
    }

    public function enable(): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        abort_if($workspace->reseller_id !== null, 403, 'Client workspaces of a partner cannot become partners themselves.');

        $settings = $workspace->settings ?? [];
        data_set($settings, 'partner.enabled', true);
        if (! data_get($settings, 'partner.code')) {
            data_set($settings, 'partner.code', $this->partners->newCode());
        }
        $workspace->forceFill(['settings' => $settings])->save();
        Audit::log('settings', 'partner-enabled', 'Joined the partner program');

        return back()->with('flash', ['type' => 'success', 'message' => 'You are now a partner. Share your link to bring in clients.']);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        abort_unless($workspace->isPartner(), 404);
        $data = $request->validate(['white_label' => ['nullable', 'boolean']]);

        $workspace->putSetting('partner.white_label', (bool) ($data['white_label'] ?? false));
        Audit::log('settings', 'partner-updated', 'Turned white-label '.(($data['white_label'] ?? false) ? 'on' : 'off'));

        return back()->with('flash', ['type' => 'success', 'message' => ($data['white_label'] ?? false)
            ? 'White-label is on. Your clients now see your name, colour and logo.'
            : 'White-label is off.']);
    }

    public function disable(): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $settings = $workspace->settings ?? [];
        data_set($settings, 'partner.enabled', false);
        data_set($settings, 'partner.white_label', false);
        $workspace->forceFill(['settings' => $settings])->save();
        Audit::log('settings', 'partner-disabled', 'Left the partner program');

        return back()->with('flash', ['type' => 'success', 'message' => 'You have left the partner program. Your referral link no longer works.']);
    }

    /** Sets up a workspace for a client; the partner user owns it until they invite the client in. */
    public function storeClient(Request $request): RedirectResponse
    {
        $partner = $this->context->getOrFail();
        abort_unless($partner->isPartner(), 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $user = $request->user();

        $client = DB::transaction(function () use ($data, $user, $partner) {
            $client = Workspace::create([
                'name' => $data['name'],
                'type' => 'company',
                'owner_id' => $user->id,
                'email' => $user->email,
                'onboarding_step' => 1,
                'locale' => 'en',
                'country_code' => $partner->country_code,
                'currency_code' => $partner->currency_code,
                'timezone' => $partner->timezone,
            ]);
            $client->forceFill(['reseller_id' => $partner->id])->save();
            $client->members()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
            Branch::create(['workspace_id' => $client->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true, 'is_active' => true]);

            return $client;
        });

        Audit::log('settings', 'partner-client-added', 'Set up the client workspace '.$client->name, $client);
        $user->switchWorkspace($client);

        return redirect()->route('onboarding.step', 1)
            ->with('flash', ['type' => 'success', 'message' => "Let's set up {$client->name}. Invite the client from Team members when you are done."]);
    }
}
