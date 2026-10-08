<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Audit;
use App\Support\Webhooks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WebhookController extends Controller
{
    /** Endpoints a workspace may hold at once. */
    public const MAX_ENDPOINTS = 10;

    public function store(Request $request): RedirectResponse
    {
        if (WebhookEndpoint::query()->count() >= self::MAX_ENDPOINTS) {
            return back()->withErrors(['url' => 'This workspace already has '.self::MAX_ENDPOINTS.' webhooks.']);
        }

        $endpoint = WebhookEndpoint::create($this->validated($request));

        Audit::log('settings', 'webhook-created', 'Added a webhook to '.$endpoint->host(), $endpoint);

        return redirect()->route('settings.webhooks.show', $endpoint)
            ->with('flash', ['type' => 'success', 'message' => 'Webhook added. Send a test event to check it works.']);
    }

    public function show(WebhookEndpoint $webhook): View
    {
        return view('settings.webhook', [
            'endpoint' => $webhook,
            'deliveries' => $webhook->deliveries()->latest('id')->paginate(25),
            'events' => Webhooks::EVENTS,
        ]);
    }

    public function update(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        $data = $this->validated($request);
        $reactivated = $request->boolean('is_active') && ! $webhook->is_active;
        $webhook->fill($data + ['is_active' => $request->boolean('is_active')]);
        if ($reactivated) {
            $webhook->forceFill(['failure_count' => 0, 'disabled_at' => null]);
        }
        $webhook->save();

        Audit::log('settings', 'webhook-updated', 'Updated the webhook to '.$webhook->host(), $webhook);

        return back()->with('flash', ['type' => 'success', 'message' => 'Webhook saved.']);
    }

    public function destroy(WebhookEndpoint $webhook): RedirectResponse
    {
        $webhook->delete();

        Audit::log('settings', 'webhook-deleted', 'Removed the webhook to '.$webhook->host());

        return redirect()->route('settings.api.index')->with('flash', ['type' => 'success', 'message' => 'Webhook removed.']);
    }

    /** Test mode: send a harmless "ping" now and show what came back. */
    public function test(WebhookEndpoint $webhook): RedirectResponse
    {
        $delivery = Webhooks::record($webhook, 'ping', ['message' => 'Test event from Zonseo. You can ignore it.']);

        return $this->sendNow($delivery, 'Test event');
    }

    public function redeliver(WebhookEndpoint $webhook, WebhookDelivery $delivery): RedirectResponse
    {
        abort_unless($delivery->webhook_endpoint_id === $webhook->id, 404);

        $copy = Webhooks::record($webhook, $delivery->event, $delivery->payload['data'] ?? []);

        return $this->sendNow($copy, 'Event resent');
    }

    public function rotateSecret(WebhookEndpoint $webhook): RedirectResponse
    {
        $webhook->forceFill(['secret' => WebhookEndpoint::newSecret()])->save();

        Audit::log('settings', 'webhook-secret-rotated', 'Made a new signing secret for the webhook to '.$webhook->host(), $webhook);

        return back()->with('flash', ['type' => 'success', 'message' => 'New signing secret made. Update it where you check signatures.']);
    }

    protected function sendNow(WebhookDelivery $delivery, string $what): RedirectResponse
    {
        DeliverWebhook::dispatchSync($delivery, true);
        $delivery->refresh();

        return back()->with('flash', $delivery->status === 'succeeded'
            ? ['type' => 'success', 'message' => $what.' delivered: the endpoint answered '.$delivery->response_status.'.']
            : ['type' => 'danger', 'message' => $what.' failed. '.$delivery->error]);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'url' => ['required', 'url:http,https', 'max:500', function (string $attribute, mixed $value, \Closure $fail) {
                if (! Webhooks::isAllowedUrl((string) $value)) {
                    $fail('Use a public https address. Private and internal network addresses are not allowed.');
                }
            }],
            'description' => ['nullable', 'string', 'max:160'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(Webhooks::EVENTS))],
        ], ['events.required' => 'Pick at least one event to send.']);
        $data['events'] = array_values(array_unique($data['events']));

        return $data;
    }
}
