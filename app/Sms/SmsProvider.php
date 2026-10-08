<?php

namespace App\Sms;

/**
 * A service that delivers text messages. Drivers talk to the provider over plain HTTP and keep
 * their credentials in the workspace's settings (secret fields encrypted).
 */
interface SmsProvider
{
    public function key(): string;

    public function label(): string;

    /** One line on where the provider works best, shown in settings. */
    public function description(): string;

    /**
     * The settings fields this provider needs, keyed by field name.
     *
     * @return array<string, array{label: string, secret: bool, required: bool, help?: string}>
     */
    public function fields(): array;

    /**
     * Send one message and return the provider's id for it.
     *
     * @param  string  $to  +E.164 number
     * @param  array<string, string>  $credentials
     *
     * @throws SmsException when the provider refuses the message or cannot be reached
     */
    public function send(string $to, string $body, array $credentials): string;
}
