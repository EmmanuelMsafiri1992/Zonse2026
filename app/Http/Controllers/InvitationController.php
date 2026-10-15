<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Support\Notifier;
use App\Support\PlanLimits;
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
            if (! PlanLimits::for($workspace)->canJoin()) {
                return redirect()->route('invitations.accept', $token)
                    ->withErrors(['plan' => "{$workspace->name} has no room left on its plan for another member. Ask the workspace owner to upgrade, then accept again."]);
            }

            $workspace->members()->attach($user->id, [
                'role' => $invitation->role ?: 'member',
                'joined_at' => now(),
            ]);

            Notifier::send(
                Notifier::admins($workspace)->push($invitation->inviter)->filter(), 'team', $user->name.' joined the workspace',
                'Accepted the invitation as '.($invitation->role ?: 'member').'.', route('settings.members.index'), 'user-plus', $workspace, $user,
            );
        }

        $invitation->forceFill(['accepted_at' => now()])->save();
        $user->switchWorkspace($workspace);
        $request->session()->forget(['invitation.email']);

        return redirect()->route('dashboard')
            ->with('flash', ['type' => 'success', 'message' => "You've joined {$workspace->name}."]);
    }
}
