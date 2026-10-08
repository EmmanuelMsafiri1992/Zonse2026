<?php

namespace App\Support;

use App\Http\Resources\V1\AppointmentResource;
use App\Http\Resources\V1\ContactResource;
use App\Http\Resources\V1\InvoiceResource;
use App\Http\Resources\V1\PaymentResource;
use App\Http\Resources\V1\SignatureRequestResource;
use App\Http\Resources\V1\TaskResource;
use App\Http\Resources\V1\TicketResource;
use App\Jobs\DeliverWebhook;
use App\Models\SignatureRequest;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Modules\Appointments\Models\Appointment;
use Modules\Contacts\Models\Contact;
use Modules\Helpdesk\Models\Ticket;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Payment;
use Modules\Tasks\Models\Task;

/** Records an event for each endpoint that listens to it, signs it and checks where it may be sent. */
class Webhooks
{
    /** @var array<string, string> */
    public const EVENTS = [
        'contact.created' => 'A contact is added',
        'contact.updated' => 'A contact is changed',
        'task.created' => 'A task is added',
        'task.updated' => 'A task is changed',
        'task.completed' => 'A task is marked done',
        'ticket.created' => 'A ticket is opened',
        'ticket.updated' => 'A ticket is changed',
        'invoice.created' => 'An invoice is created',
        'invoice.paid' => 'An invoice is paid in full',
        'payment.received' => 'A payment is recorded',
        'appointment.created' => 'An appointment is booked',
        'appointment.updated' => 'An appointment is changed',
        'signature.completed' => 'A document is signed by everyone',
        'signature.declined' => 'A signer declines a document',
    ];

    /** @var array<class-string<Model>, class-string> */
    public const RESOURCES = [
        Contact::class => ContactResource::class,
        Task::class => TaskResource::class,
        Ticket::class => TicketResource::class,
        Invoice::class => InvoiceResource::class,
        Payment::class => PaymentResource::class,
        Appointment::class => AppointmentResource::class,
        SignatureRequest::class => SignatureRequestResource::class,
    ];

    /** Header carrying "t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>">". */
    public const SIGNATURE_HEADER = 'X-Zonseo-Signature';

    protected static ?Closure $hostResolver = null;

    /**
     * Queue the event for every active endpoint in the workspace that listens to it.
     *
     * @return int how many deliveries were queued
     */
    public static function dispatch(string $event, Model $model, ?Workspace $workspace = null): int
    {
        $workspaceId = $workspace?->id ?? $model->workspace_id ?? null;
        if (! $workspaceId || ! isset(self::EVENTS[$event])) {
            return 0;
        }

        $endpoints = WebhookEndpoint::forWorkspace($workspaceId)->listeningTo($event)->get();
        foreach ($endpoints as $endpoint) {
            DeliverWebhook::dispatch(self::record($endpoint, $event, self::data($model)))->afterCommit();
        }

        return $endpoints->count();
    }

    /** @param array<string, mixed> $data */
    public static function record(WebhookEndpoint $endpoint, string $event, array $data): WebhookDelivery
    {
        $delivery = new WebhookDelivery([
            'workspace_id' => $endpoint->workspace_id,
            'webhook_endpoint_id' => $endpoint->id,
            'event' => $event,
        ]);
        $delivery->uuid = (string) str()->uuid();
        $delivery->payload = [
            'id' => $delivery->uuid,
            'event' => $event,
            'created_at' => now()->toIso8601String(),
            'workspace_id' => $endpoint->workspace_id,
            'data' => $data,
        ];
        $delivery->save();

        return $delivery;
    }

    /** @return array<string, mixed> */
    public static function data(Model $model): array
    {
        $resource = self::RESOURCES[$model::class] ?? null;

        return $resource ? (new $resource($model))->resolve() : ['id' => $model->getKey()];
    }

    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Only public web addresses: https (plain http just on a local machine), and never a host that
     * points at a private, loopback or reserved network, so a webhook cannot reach inside our servers.
     */
    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        $local = app()->isLocal();

        if ($host === '' || isset($parts['user']) || ! ($scheme === 'https' || ($local && $scheme === 'http'))) {
            return false;
        }

        if ($local) {
            return true;
        }

        $addresses = self::resolve(trim($host, '[]'));
        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /** Swap out DNS lookups, for tests. */
    public static function resolveHostsUsing(?Closure $resolver): void
    {
        self::$hostResolver = $resolver;
    }

    /** @return list<string> */
    protected static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        if (self::$hostResolver) {
            return array_values((self::$hostResolver)($host));
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }
}
