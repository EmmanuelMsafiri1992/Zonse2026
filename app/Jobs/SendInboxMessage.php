<?php

namespace App\Jobs;

use App\Inbox\Inbox;
use App\Inbox\InboxException;
use App\Models\InboxMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Sends one inbox reply over its channel, retrying when the provider is briefly unreachable. */
class SendInboxMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Seconds to wait before each retry. */
    public const RETRY_DELAYS = [30, 120];

    public function __construct(public int $messageId) {}

    public function handle(Inbox $inbox): void
    {
        $message = InboxMessage::allWorkspaces()->find($this->messageId);
        if (! $message || $message->status !== 'queued') {
            return;
        }

        try {
            $inbox->deliver($message);
        } catch (InboxException $e) {
            if ($this->attempts() < $this->tries && $this->job && $this->job->getConnectionName() !== 'sync') {
                $this->release(self::RETRY_DELAYS[$this->attempts() - 1] ?? 120);

                return;
            }
            $inbox->fail($message, $e->getMessage());
        }
    }

    public function failed(?Throwable $exception): void
    {
        InboxMessage::allWorkspaces()->whereKey($this->messageId)->where('status', 'queued')
            ->update(['status' => 'failed', 'error' => mb_substr($exception?->getMessage() ?? 'Could not be sent.', 0, 500)]);
    }
}
