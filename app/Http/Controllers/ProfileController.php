<?php

namespace App\Http\Controllers;

use App\Support\Lists;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'timezones' => Lists::timezones(),
            'memberships' => $request->user()->workspaces()->get(),
        ]);
    }
}
