<?php

namespace App\Http\Controllers;

use App\Inbox\Inbox;
use App\Models\InboxChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Where providers deliver incoming messages. The random token in the URL picks the channel;
 * Meta channels also prove themselves with a signed body once the app secret is saved.
 */
class InboxWebhookController extends Controller
{
    public function __construct(protected Inbox $inbox) {}

    /** Meta's subscription handshake: echo the challenge when the verify token matches. */
    public function verify(Request $request, string $token): Response
    {
        $channel = $this->channel($token);
        abort_unless($request->query('hub_mode') === 'subscribe' && hash_equals($channel->token, (string) $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, string $token): JsonResponse
    {
        $channel = $this->channel($token);
        abort_unless($channel->driver()->authorize($request, $channel), 403);

        // A switched-off channel still answers 200 so the provider doesn't keep retrying.
        $received = 0;
        if ($channel->is_active) {
            foreach (array_slice($channel->driver()->parse($request, $channel), 0, 100) as $incoming) {
                $received += $this->inbox->receive($channel, $incoming) ? 1 : 0;
            }
        }

        return response()->json(['received' => $received]);
    }

    protected function channel(string $token): InboxChannel
    {
        abort_if(strlen($token) < 40, 404);

        return InboxChannel::allWorkspaces()->where('token', $token)->firstOrFail();
    }
}
