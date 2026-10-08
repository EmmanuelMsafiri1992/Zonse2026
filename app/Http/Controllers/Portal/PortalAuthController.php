<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PortalAccess;
use App\Models\Workspace;
use App\Notifications\PortalSignInLink;
use App\Support\Branding;
use App\Support\Portal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

/** Password-less sign-in for portal contacts: they ask for a link, the link signs them in once. */
class PortalAuthController extends Controller
{
    public function __construct(protected Portal $portal, protected Branding $branding) {}

    public function show(Workspace $workspace): View|RedirectResponse
    {
        if ($this->portal->signedIn($workspace)) {
            return redirect()->route('portal.home', $workspace);
        }

        return view('portal.login', [
            'portalWorkspace' => $workspace,
            'brand' => $this->branding->for($workspace),
            'welcome' => $this->portal->welcome($workspace),
        ]);
    }

    /** Always answers the same way, so the form cannot be used to find out who is a client. */
    public function send(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);

        PortalAccess::forWorkspace($workspace)
            ->where('email', mb_strtolower(trim($data['email'])))
            ->where('status', 'active')
            ->whereHas('contact')
            ->get()
            ->each(function (PortalAccess $access) use ($workspace) {
                Notification::route('mail', $access->email)
                    ->notify(new PortalSignInLink($workspace, $access, $access->issueLoginToken()));
            });

        return back()->with('flash', [
            'type' => 'success',
            'message' => 'If that email has portal access, a sign-in link is on its way. It expires in '.PortalAccess::LINK_MINUTES.' minutes.',
        ]);
    }

    public function enter(Workspace $workspace, string $token): RedirectResponse
    {
        $access = PortalAccess::redeem($workspace, $token);
        if (! $access) {
            return redirect()->route('portal.login', $workspace)->with('flash', [
                'type' => 'danger',
                'message' => 'That sign-in link has expired or was already used. Ask for a new one below.',
            ]);
        }

        $this->portal->signIn($workspace, $access);

        return redirect()->route('portal.home', $workspace);
    }

    public function logout(Workspace $workspace): RedirectResponse
    {
        $this->portal->signOut($workspace);

        return redirect()->route('portal.login', $workspace)->with('flash', ['type' => 'success', 'message' => 'You are signed out.']);
    }
}
