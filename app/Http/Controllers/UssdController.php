<?php

namespace App\Http\Controllers;

use App\Models\WorkspaceMembership;
use App\Sms\PhoneNumber;
use App\Support\Audit;
use App\Tenancy\WorkspaceContext;
use App\Ussd\UssdMenu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Phone access: each member sets the PIN they type when dialling the workspace's USSD code;
 * owners and admins switch the service on, copy the gateway callback URL and try the menu in a simulator.
 */
class UssdController extends Controller
{
    public function __construct(protected WorkspaceContext $context, protected UssdMenu $menu) {}

    public function index(Request $request): View
    {
        $workspace = $this->context->getOrFail();
        $user = $request->user();
        $canConfigure = Gate::allows('manage-workspace');

        return view('ussd.index', [
            'enabled' => (bool) $workspace->setting('ussd.enabled', false),
            'serviceCode' => $workspace->setting('ussd.service_code'),
            'membership' => $this->membership($request),
            'phone' => PhoneNumber::normalize($user->phone, $workspace->country_code),
            'canConfigure' => $canConfigure,
            'callbackUrl' => $canConfigure && $workspace->setting('ussd.token') ? route('ussd.callback', $workspace->setting('ussd.token')) : null,
            'loggable' => $canConfigure ? $this->context->run($workspace, fn () => $this->menu->loggableEntities()) : [],
        ]);
    }

    public function updatePin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pin' => ['required', 'digits:4', 'confirmed', 'not_in:0000,1111,1234,4321,9999'],
            'current_password' => ['required', 'current_password'],
        ], ['pin.not_in' => 'Choose a PIN that is harder to guess.']);

        $this->membership($request)->forceFill(['ussd_pin' => Hash::make($data['pin'])])->save();
        Audit::log('access', 'ussd-pin-set', 'Set their phone (USSD) PIN');

        return back()->with('flash', ['type' => 'success', 'message' => 'Phone PIN saved.']);
    }

    public function destroyPin(Request $request): RedirectResponse
    {
        $this->membership($request)->forceFill(['ussd_pin' => null])->save();
        Audit::log('access', 'ussd-pin-removed', 'Removed their phone (USSD) PIN');

        return back()->with('flash', ['type' => 'success', 'message' => 'Phone PIN removed. This number can no longer use the phone menu.']);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'service_code' => ['nullable', 'string', 'max:30', 'regex:/^[\d\*#]+$/'],
        ], ['service_code.regex' => 'Use the code as people dial it, e.g. *384*123#.']);

        $settings = $workspace->settings ?? [];
        data_set($settings, 'ussd.enabled', (bool) ($data['enabled'] ?? false));
        data_set($settings, 'ussd.service_code', $data['service_code'] ?? null);
        if (! data_get($settings, 'ussd.token')) {
            data_set($settings, 'ussd.token', Str::random(40));
        }
        $workspace->forceFill(['settings' => $settings])->save();

        Audit::log('settings', 'ussd-updated', 'Updated the phone access (USSD) settings', properties: ['enabled' => (bool) ($data['enabled'] ?? false)]);

        return back()->with('flash', ['type' => 'success', 'message' => ($data['enabled'] ?? false) ? 'Phone access is on.' : 'Phone access is off.']);
    }

    /** A new callback URL, for when the old one may have leaked. The gateway must be updated afterwards. */
    public function rotateToken(): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $workspace->putSetting('ussd.token', Str::random(40));
        Audit::log('settings', 'ussd-token-rotated', 'Changed the phone access (USSD) callback URL');

        return back()->with('flash', ['type' => 'warning', 'message' => 'New callback URL made. Paste it into your USSD gateway, the old one has stopped working.']);
    }

    /** Runs the real menu for a typed number without any gateway, so admins can try it before going live. */
    public function simulate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'text' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['reply' => $this->menu->handle($this->context->getOrFail(), $data['phone'], (string) ($data['text'] ?? ''))]);
    }

    protected function membership(Request $request): WorkspaceMembership
    {
        return WorkspaceMembership::query()->where('workspace_id', $this->context->id())->where('user_id', $request->user()->id)->firstOrFail();
    }
}
