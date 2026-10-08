<?php

namespace App\Http\Controllers;

use App\Support\Lists;
use App\Support\SingleSignOn;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function edit(Request $request, SingleSignOn $sso)
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'timezones' => Lists::timezones(),
            'memberships' => $request->user()->workspaces()->get(),
            'passkeys' => $request->user()->passkeys()->latest()->get(),
            'socialAccounts' => $request->user()->socialAccounts()->get()->keyBy('provider'),
            'ssoProviders' => $sso->configured(),
        ]);
    }
}
