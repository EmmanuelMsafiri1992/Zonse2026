<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Ussd\UssdMenu;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The URL a USSD gateway (Africa's Talking format: sessionId, serviceCode, phoneNumber, text)
 * calls for every screen of a session. The random token in the URL picks the workspace.
 */
class UssdCallbackController extends Controller
{
    public function __invoke(Request $request, string $token, UssdMenu $menu): Response
    {
        abort_if(strlen($token) < 40, 404);
        $workspace = Workspace::query()->where('settings->ussd->token', $token)->firstOrFail();

        if (! $workspace->setting('ussd.enabled')) {
            return $this->reply('END Phone access is switched off for '.$workspace->name.'.');
        }

        $phone = (string) $request->input('phoneNumber', '');
        $text = (string) $request->input('text', '');
        if ($phone === '' || strlen($phone) > 30 || strlen($text) > 500) {
            return $this->reply('END Something went wrong. Please dial again.', 422);
        }

        return $this->reply($menu->handle($workspace, $phone, $text));
    }

    protected function reply(string $message, int $status = 200): Response
    {
        return response($message, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
