<?php

namespace App\Sms\Providers;

use App\Sms\SmsProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Test mode: messages are written to the log and the message history but never leave the server. */
class TestProvider implements SmsProvider
{
    public function key(): string
    {
        return 'test';
    }

    public function label(): string
    {
        return 'Test mode';
    }

    public function description(): string
    {
        return 'Nothing is sent. Messages appear in the history so you can check reminders before paying for a provider.';
    }

    public function fields(): array
    {
        return [];
    }

    public function send(string $to, string $body, array $credentials): string
    {
        Log::info('SMS (test mode, not sent)', ['to' => $to, 'body' => $body]);

        return 'test-'.Str::uuid();
    }
}
