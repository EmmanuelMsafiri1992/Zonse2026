<?php

namespace App\Jobs;

use App\Models\DocumentCapture;
use App\Ocr\OcrException;
use App\Ocr\OcrService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Reads one uploaded document with the workspace's OCR provider, retrying when the provider is briefly unreachable. */
class ProcessDocumentCapture implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Long enough for a provider that reads asynchronously to finish. */
    public int $timeout = 120;

    /** Seconds to wait before each retry. */
    public const RETRY_DELAYS = [30, 120];

    public function __construct(public int $captureId) {}

    public function handle(OcrService $ocr): void
    {
        $capture = DocumentCapture::allWorkspaces()->find($this->captureId);
        if ($capture && $capture->status === 'processing') {
            try {
                $ocr->process($capture);
            } catch (OcrException $e) {
                if ($this->attempts() < $this->tries && $this->job && $this->job->getConnectionName() !== 'sync') {
                    $this->release(self::RETRY_DELAYS[$this->attempts() - 1] ?? 120);

                    return;
                }
                $capture->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        DocumentCapture::allWorkspaces()->whereKey($this->captureId)->where('status', 'processing')
            ->update(['status' => 'failed', 'error' => mb_substr($exception?->getMessage() ?? 'Could not be read.', 0, 500)]);
    }
}
