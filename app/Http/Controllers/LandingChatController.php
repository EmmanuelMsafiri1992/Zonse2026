<?php

namespace App\Http\Controllers;

use App\Assistant\LandingChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The question-and-answer chat on the public pages. */
class LandingChatController extends Controller
{
    public function __construct(protected LandingChat $chat) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:1000'],
            'website' => ['nullable', 'string', 'max:0'],
        ]);

        return response()->json($this->chat->answer($data['message']));
    }

    public function destroy(): JsonResponse
    {
        $this->chat->forget();

        return response()->json(['ok' => true]);
    }
}
