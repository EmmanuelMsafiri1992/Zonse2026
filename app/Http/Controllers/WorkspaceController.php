<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceController extends Controller
{
    /** Create an additional workspace and start its setup wizard. */
    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $user = $request->user();

        $workspace = DB::transaction(function () use ($data, $user) {
            $workspace = Workspace::create([
                'name' => $data['name'],
                'type' => 'company',
                'owner_id' => $user->id,
                'email' => $user->email,
                'onboarding_step' => 1,
                'locale' => 'en',
            ]);
            $workspace->members()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
            Branch::create(['workspace_id' => $workspace->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true, 'is_active' => true]);
            $user->switchWorkspace($workspace);

            return $workspace;
        });

        return redirect()->route('onboarding.step', 1)
            ->with('flash', ['type' => 'success', 'message' => "Let's set up {$workspace->name}."]);
    }

    public function switch(Request $request, Workspace $workspace)
    {
        $user = $request->user();
        abort_unless($user->belongsToWorkspace($workspace) || $user->is_super_admin, 403);

        $user->switchWorkspace($workspace);

        return redirect()->route('dashboard')
            ->with('flash', ['type' => 'success', 'message' => "Switched to {$workspace->name}."]);
    }
}
