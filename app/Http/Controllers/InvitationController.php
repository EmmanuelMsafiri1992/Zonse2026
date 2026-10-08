<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function show(Request $request, string $token)
    {
        $invitation = Invitation::with(['workspace', 'inviter'])->where('token', $token)->firstOrFail();

        if (! $request->user()) {
            // Fortify sends the user back here after login / registration.
            $request->session()->put('url.intended', $invitation->url());
            $request->session()->put('invitation.email', $invitation->email);
        }

        return view('invitations.show', ['invitation' => $invitation, 'valid' => $invitation->isValid()]);
    }

    public function accept(Request $request, string $token)
    {
        $invitation = Invitation::with('workspace')->where('token', $token)->firstOrFail();
        $user = $request->user();

        if (! $invitation->isValid()) {
            return redirect()->route('invitations.accept', $token);
        }

        $workspace = $invitation->workspace;
        if (! $user->belongsToWorkspace($workspace)) {
            $workspace->members()->attach($user->id, [
                'role' => $invitation->role ?: 'member',
                'joined_at' => now(),
            ]);
        }

        $invitation->forceFill(['accepted_at' => now()])->save();
        $user->switchWorkspace($workspace);
        $request->session()->forget(['invitation.email']);

        return redirect()->route('dashboard')
            ->with('flash', ['type' => 'success', 'message' => "You've joined {$workspace->name}."]);
    }
}
