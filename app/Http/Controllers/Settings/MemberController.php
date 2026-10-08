<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\WorkspaceInvitationNotification;
use App\Support\Lists;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function __construct(protected WorkspaceContext $context) {}

    public function index()
    {
        $workspace = $this->context->getOrFail();

        return view('settings.members', [
            'workspace' => $workspace,
            'members' => $workspace->members()->orderBy('name')->get(),
            'invitations' => $workspace->invitations()->whereNull('accepted_at')->latest()->get(),
            'roles' => Lists::ROLES,
            'branches' => $workspace->branches()->orderBy('name')->get(),
        ]);
    }

    public function invite(Request $request)
    {
        $workspace = $this->context->getOrFail();

        $data = $request->validate([
            'email' => ['required', 'email', 'max:120'],
            'role' => ['required', Rule::in(array_keys(Lists::ROLES)), 'not_in:owner'],
        ]);

        if ($workspace->members()->where('email', $data['email'])->exists()) {
            return back()->withErrors(['email' => 'That person is already a member of this workspace.']);
        }

        $invitation = $workspace->invitations()->whereNull('accepted_at')->where('email', $data['email'])->first();
        if ($invitation) {
            $invitation->forceFill(['role' => $data['role'], 'expires_at' => now()->addDays(7)])->save();
        } else {
            $invitation = Invitation::create($data + ['workspace_id' => $workspace->id, 'invited_by' => $request->user()->id]);
        }

        try {
            Notification::route('mail', $invitation->email)
                ->notify(new WorkspaceInvitationNotification($invitation));
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('flash', ['type' => 'success', 'message' => "Invitation sent to {$invitation->email}."]);
    }

    public function update(Request $request, User $user)
    {
        $workspace = $this->context->getOrFail();
        $data = $request->validate([
            'role' => ['required', Rule::in(array_keys(Lists::ROLES)), 'not_in:owner'],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('workspace_id', $workspace->id)],
            'job_title' => ['nullable', 'string', 'max:80'],
        ]);

        abort_unless($user->belongsToWorkspace($workspace), 404);
        if ($user->isOwnerOf($workspace)) {
            return back()->withErrors(['role' => 'The workspace owner cannot be changed here.']);
        }

        $workspace->members()->updateExistingPivot($user->id, $data);

        return back()->with('flash', ['type' => 'success', 'message' => "{$user->name} updated."]);
    }

    public function destroy(Request $request, User $user)
    {
        $workspace = $this->context->getOrFail();
        abort_unless($user->belongsToWorkspace($workspace), 404);

        if ($user->isOwnerOf($workspace)) {
            return back()->withErrors(['member' => 'The owner cannot be removed from the workspace.']);
        }

        $workspace->members()->detach($user->id);
        if ($user->current_workspace_id === $workspace->id) {
            $user->forceFill(['current_workspace_id' => null])->save();
        }

        return back()->with('flash', ['type' => 'success', 'message' => "{$user->name} removed from the workspace."]);
    }

    public function destroyInvitation(Invitation $invitation)
    {
        abort_unless($invitation->workspace_id === $this->context->id(), 404);
        $invitation->delete();

        return back()->with('flash', ['type' => 'success', 'message' => 'Invitation cancelled.']);
    }
}
