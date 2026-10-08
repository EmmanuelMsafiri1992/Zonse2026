<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Notifier;
use App\Support\Webhooks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Posts one signed delivery and retries it with growing gaps; the endpoint is switched off after many failures in a row. */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    /** Seconds to wait before each retry. */
    public const RETRY_DELAYS = [60, 300, 1800, 7200, 21600];

    public int $tries = 6;

    /** @param  bool  $once  send now with no retries (the "Send test" and "Resend" buttons) */
    public function __construct(public WebhookDelivery $delivery, public bool $once = false) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::allWorkspaces()->find($this->delivery->id);
        $endpoint = $delivery ? WebhookEndpoint::allWorkspaces()->find($delivery->webhook_endpoint_id) : null;
        if (! $delivery || ! $endpoint || $delivery->status === 'succeeded') {
            return;
        }

        if (! $endpoint->is_active) {
            $delivery->forceFill(['status' => 'failed', 'error' => 'The endpoint is switched off.'])->save();

            return;
        }

        $delivery->attempts++;

        if (! Webhooks::isAllowedUrl($endpoint->url)) {
            $delivery->forceFill(['status' => 'failed', 'error' => 'The address is not a public https URL.'])->save();

            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = now()->getTimestamp();

        try {
            $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()
                ->withHeaders([
                    'User-Agent' => 'Zonseo-Webhooks/1.0',
                    'X-Zonseo-Event' => $delivery->event,
                    'X-Zonseo-Delivery' => $delivery->uuid,
                    Webhooks::SIGNATURE_HEADER => Webhooks::sign($endpoint->secret, $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $succeeded = $response->successful();
            $delivery->forceFill([
                'response_status' => $response->status(),
                'response_body' => str($response->body())->limit(2000)->toString(),
                'error' => $succeeded ? null : 'The endpoint answered '.$response->status().'.',
            ]);
        } catch (Throwable $e) {
            $succeeded = false;
            $delivery->forceFill(['response_status' => null, 'response_body' => null, 'error' => str($e->getMessage())->limit(250)->toString()]);
        }

        $succeeded ? $this->succeeded($delivery, $endpoint) : $this->failedAttempt($delivery, $endpoint);
    }

    protected function succeeded(WebhookDelivery $delivery, WebhookEndpoint $endpoint): void
    {
        $delivery->forceFill(['status' => 'succeeded', 'delivered_at' => now()])->save();
        $endpoint->forceFill(['failure_count' => 0, 'last_delivered_at' => now()])->save();
    }

    /** Try again later while retries remain; a final failure counts against the endpoint. */
    protected function failedAttempt(WebhookDelivery $delivery, WebhookEndpoint $endpoint): void
    {
        $retryIn = self::RETRY_DELAYS[$delivery->attempts - 1] ?? null;
        $canRetry = ! $this->once && $retryIn !== null && $this->job !== null && $this->job->getConnectionName() !== 'sync';

        $delivery->forceFill(['status' => $canRetry ? 'pending' : 'failed'])->save();

        if ($canRetry) {
            $this->release($retryIn);

            return;
        }

        if ($this->once) {
            return;
        }

        $endpoint->forceFill(['failure_count' => $endpoint->failure_count + 1])->save();
        if ($endpoint->failure_count >= WebhookEndpoint::DISABLE_AFTER_FAILURES && $endpoint->is_active) {
            $endpoint->forceFill(['is_active' => false, 'disabled_at' => now()])->save();
            Notifier::send(
                Notifier::admins($endpoint->workspace), 'team', 'Webhook switched off: '.$endpoint->host(),
                'It failed '.$endpoint->failure_count.' times in a row. Fix the address, then switch it back on.',
                route('settings.webhooks.show', $endpoint), 'webhook-off', $endpoint->workspace,
                actor: new User, // nobody caused this, so even the person whose save set it off is told
            );
        }
    }
}
