<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Mobile app authentication. Accepts email + password and returns a workspace-scoped
 * API token with read+write abilities, the same kind used by the Settings > API page.
 */
class AuthController extends Controller
{
    /** Step 1: verify credentials and return the user's workspaces. */
    public function workspaces(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $workspaces = $user->workspaces()->get()->map(fn (Workspace $ws) => [
            'id' => $ws->id,
            'name' => $ws->name,
            'currency_code' => $ws->currency_code,
            'timezone' => $ws->timezone,
        ]);

        return response()->json([
            'data' => [
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'workspaces' => $workspaces,
            ],
        ]);
    }

    /** Step 2: pick a workspace and receive a long-lived mobile token. */
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'workspace_id' => ['required', 'integer'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $workspace = Workspace::findOrFail($data['workspace_id']);

        if (! $user->belongsToWorkspace($workspace)) {
            abort(403, 'You are not a member of that workspace.');
        }

        $device = $data['device_name'] ?? 'Zonseob Mobile';
        $tokenName = $device.' — '.now()->format('d M Y');

        $newToken = $user->createToken($tokenName, ApiToken::ACCESS['write']['abilities']);
        $newToken->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return response()->json([
            'data' => [
                'token' => $newToken->plainTextToken,
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->roleIn($workspace)],
                'workspace' => ['id' => $workspace->id, 'name' => $workspace->name, 'currency_code' => $workspace->currency_code, 'timezone' => $workspace->timezone],
            ],
        ]);
    }

    /** Revoke the current token (logout). */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
