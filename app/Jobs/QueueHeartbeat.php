<?php

namespace App\Jobs;

use App\Support\Health;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Queued every few minutes by the scheduler; when a worker runs it, the queue is proven alive. */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Health::beat('queue');
    }
}
