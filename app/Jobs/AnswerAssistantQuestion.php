<?php

namespace App\Jobs;

use App\Assistant\AssistantException;
use App\Assistant\AssistantService;
use App\Models\AssistantConversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Answers the latest question in an assistant conversation, retrying when the provider is briefly unavailable. */
class AnswerAssistantQuestion implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Several lookup rounds, each a model call. */
    public int $timeout = 300;

    /** Seconds to wait before each retry. */
    public const RETRY_DELAYS = [15, 60];

    public function __construct(public int $conversationId) {}

    public function handle(AssistantService $assistant): void
    {
        $conversation = AssistantConversation::allWorkspaces()->find($this->conversationId);
        if (! $conversation || ! $conversation->isThinking()) {
            return;
        }

        try {
            $assistant->answer($conversation);
        } catch (AssistantException $e) {
            if ($this->attempts() < $this->tries && $this->job && $this->job->getConnectionName() !== 'sync') {
                $this->release(self::RETRY_DELAYS[$this->attempts() - 1] ?? 60);

                return;
            }
            $assistant->fail($conversation, $e->getMessage());
        }
    }

    public function failed(?Throwable $exception): void
    {
        AssistantConversation::allWorkspaces()->whereKey($this->conversationId)->where('status', 'thinking')
            ->update(['status' => 'failed', 'error' => mb_substr($exception?->getMessage() ?? 'The assistant could not answer.', 0, 500)]);
    }
}
