<?php

namespace App\Jobs;

use App\Models\SmsMessage;
use App\Sms\SmsException;
use App\Sms\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Hands one queued text message to the workspace's provider, retrying when the provider is briefly unreachable. */
class SendSmsMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Seconds to wait before each retry. */
    public const RETRY_DELAYS = [30, 120];

    public function __construct(public int $messageId) {}

    public function handle(SmsService $sms): void
    {
        $message = SmsMessage::allWorkspaces()->find($this->messageId);
        if ($message && $message->status === 'queued') {
            try {
                $sms->deliver($message);
            } catch (SmsException $e) {
                if ($this->attempts() < $this->tries && $this->job && $this->job->getConnectionName() !== 'sync') {
                    $this->release(self::RETRY_DELAYS[$this->attempts() - 1] ?? 120);

                    return;
                }
                $message->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        SmsMessage::allWorkspaces()->whereKey($this->messageId)->where('status', 'queued')
            ->update(['status' => 'failed', 'error' => mb_substr($exception?->getMessage() ?? 'Could not be sent.', 0, 500)]);
    }
}
