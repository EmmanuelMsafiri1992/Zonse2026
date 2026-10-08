<?php

namespace App\Sms;

use App\Jobs\SendSmsMessage;
use App\Models\SmsMessage;
use App\Models\Workspace;
use App\Sms\Providers\AfricasTalkingProvider;
use App\Sms\Providers\InfobipProvider;
use App\Sms\Providers\TestProvider;
use App\Sms\Providers\TwilioProvider;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Modules\Contacts\Models\Contact;

/**
 * Text messages for a workspace. The workspace picks one provider (settings key sms.provider) and
 * switches automations on under sms.notify.*. Every message is logged, queued, and sent by a job,
 * so a slow provider never holds up a page and a brief outage is retried.
 */
class SmsService
{
    /** Longest message accepted: three GSM parts. Stops one slip turning into a large bill. */
    public const MAX_LENGTH = 459;

    /** Automations a workspace can switch on. */
    public const AUTOMATIONS = [
        'receipts' => ['label' => 'Payment receipts', 'help' => 'Text the customer when a payment is recorded against their invoice.'],
        'overdue' => ['label' => 'Overdue invoice reminders', 'help' => 'Weekly reminder, at most three per invoice, with the link to view and pay.'],
        'appointments' => ['label' => 'Appointment reminders', 'help' => 'The day before each booked appointment.'],
    ];

    /** @var array<string, SmsProvider> */
    protected array $providers = [];

    public function __construct()
    {
        foreach ([new TwilioProvider, new AfricasTalkingProvider, new InfobipProvider, new TestProvider] as $provider) {
            $this->providers[$provider->key()] = $provider;
        }
    }

    /** @return array<string, SmsProvider> */
    public function providers(): array
    {
        return $this->providers;
    }

    public function find(?string $key): ?SmsProvider
    {
        return $key ? ($this->providers[$key] ?? null) : null;
    }

    /** The workspace's chosen provider, when it is fully set up. */
    public function provider(Workspace $workspace): ?SmsProvider
    {
        $provider = $this->find($workspace->setting('sms.provider'));

        return $provider && $this->isConfigured($workspace, $provider) ? $provider : null;
    }

    public function enabled(Workspace $workspace): bool
    {
        return $this->provider($workspace) !== null;
    }

    /** SMS is set up and the workspace has switched this automation on. */
    public function wants(Workspace $workspace, string $automation): bool
    {
        return $this->enabled($workspace) && (bool) $workspace->setting('sms.notify.'.$automation);
    }

    public function isConfigured(Workspace $workspace, SmsProvider $provider): bool
    {
        $credentials = $this->credentials($workspace, $provider);
        foreach ($provider->fields() as $field => $meta) {
            if ($meta['required'] && ($credentials[$field] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    public function credentials(Workspace $workspace, SmsProvider $provider): array
    {
        $credentials = [];
        foreach ($provider->fields() as $field => $meta) {
            $value = (string) $workspace->setting('sms.'.$provider->key().'.'.$field, '');
            if ($meta['secret'] && $value !== '') {
                try {
                    $value = Crypt::decryptString($value);
                } catch (DecryptException) {
                    $value = '';
                }
            }
            $credentials[$field] = $value;
        }

        return $credentials;
    }

    /**
     * Choose the provider, store its credentials (blank secrets keep the saved value) and the automations.
     *
     * @param  array<string, array<string, string|null>>  $values  per provider key
     * @param  array<string, bool>  $automations
     */
    public function configure(Workspace $workspace, ?string $providerKey, array $values, array $automations): void
    {
        $settings = $workspace->settings ?? [];
        data_set($settings, 'sms.provider', $this->find($providerKey)?->key());

        foreach ($this->providers as $key => $provider) {
            foreach ($provider->fields() as $field => $meta) {
                $value = trim((string) ($values[$key][$field] ?? ''));
                if ($meta['secret'] && $value === '') {
                    continue;
                }
                data_set($settings, 'sms.'.$key.'.'.$field, $meta['secret'] ? Crypt::encryptString($value) : $value);
            }
        }
        foreach (array_keys(self::AUTOMATIONS) as $automation) {
            data_set($settings, 'sms.notify.'.$automation, (bool) ($automations[$automation] ?? false));
        }

        $workspace->settings = $settings;
        $workspace->save();
    }

    /** The number to text a contact on: mobile first, then phone, read in the contact's (or workspace's) country. */
    public function numberFor(Contact $contact, ?Workspace $workspace = null): ?string
    {
        $country = $contact->country_code ?: ($workspace ?? $contact->workspace)?->country_code;

        return PhoneNumber::normalize($contact->mobile, $country) ?? PhoneNumber::normalize($contact->phone, $country);
    }

    /**
     * Log a message and queue it for sending. Returns null when SMS is off or the number is unusable.
     *
     * @param  array{purpose?: string, contact?: Contact|null, subject?: Model|null, batch?: string|null, sent_by?: int|null, country?: string|null}  $options
     */
    public function send(Workspace $workspace, string $to, string $body, array $options = []): ?SmsMessage
    {
        $provider = $this->provider($workspace);
        $number = PhoneNumber::normalize($to, $options['country'] ?? $workspace->country_code);
        $body = trim($body);
        if (! $provider || ! $number || $body === '') {
            return null;
        }

        $subject = $options['subject'] ?? null;
        $message = SmsMessage::create([
            'workspace_id' => $workspace->id,
            'contact_id' => ($options['contact'] ?? null)?->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'to' => $number,
            'body' => mb_substr($body, 0, self::MAX_LENGTH),
            'segments' => SmsMessage::segmentsFor(mb_substr($body, 0, self::MAX_LENGTH)),
            'purpose' => $options['purpose'] ?? 'manual',
            'provider' => $provider->key(),
            'batch' => $options['batch'] ?? null,
            'sent_by' => array_key_exists('sent_by', $options) ? $options['sent_by'] : auth()->id(),
        ]);

        SendSmsMessage::dispatch($message->id)->afterCommit();

        return $message;
    }

    /**
     * Text a contact on their best number.
     *
     * @param  array{purpose?: string, subject?: Model|null, batch?: string|null, sent_by?: int|null}  $options
     */
    public function sendToContact(Contact $contact, string $body, array $options = []): ?SmsMessage
    {
        $workspace = $contact->workspace;
        $number = $workspace ? $this->numberFor($contact, $workspace) : null;

        return $number ? $this->send($workspace, $number, $body, $options + ['contact' => $contact]) : null;
    }

    /**
     * Hand a queued message to its provider. Permanent refusals fail the message; brief outages are
     * rethrown so the queue retries them.
     *
     * @throws SmsException when the provider is briefly unavailable
     */
    public function deliver(SmsMessage $message): void
    {
        $workspace = Workspace::query()->find($message->workspace_id);
        $provider = $this->find($message->provider);
        if (! $workspace || ! $provider || ! $this->isConfigured($workspace, $provider)) {
            $message->forceFill(['status' => 'failed', 'error' => 'SMS is no longer set up for this workspace.'])->save();

            return;
        }

        try {
            $id = $provider->send($message->to, $message->body, $this->credentials($workspace, $provider));
        } catch (SmsException $e) {
            if (! $e->permanent) {
                throw $e;
            }
            $message->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return;
        }

        $message->forceFill(['status' => 'sent', 'sent_at' => now(), 'provider_message_id' => $id ?: null, 'error' => null])->save();
    }

    /** "Sunrise Clinic: " so the customer knows who is texting, whatever sender ID the provider shows. */
    public static function prefix(Workspace $workspace): string
    {
        return $workspace->name.': ';
    }
}
