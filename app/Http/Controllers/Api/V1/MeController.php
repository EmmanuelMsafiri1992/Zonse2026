<?php

namespace App\Http\Controllers\Api\V1;

use App\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends ApiController
{
    /** Who the key acts as, which workspace it reaches and what it may do. */
    public function __invoke(Request $request, WorkspaceContext $context): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        $workspace = $context->getOrFail();

        return response()->json(['data' => [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->roleIn($workspace)],
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name, 'currency_code' => $workspace->currency_code, 'timezone' => $workspace->timezone],
            'key' => ['name' => $token->name, 'abilities' => $token->abilities, 'expires_at' => $token->expires_at?->toIso8601String()],
        ]]);
    }
}
