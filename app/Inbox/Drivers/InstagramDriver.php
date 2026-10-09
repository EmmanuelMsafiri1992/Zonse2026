<?php

namespace App\Inbox\Drivers;

/** Direct messages to an Instagram professional account linked to a Facebook page (same Messenger API). */
class InstagramDriver extends FacebookDriver
{
    public function key(): string
    {
        return 'instagram';
    }

    public function label(): string
    {
        return 'Instagram';
    }

    public function icon(): string
    {
        return 'instagram';
    }

    public function description(): string
    {
        return "Direct messages to your Instagram professional account. Replies are allowed within 24 hours of the customer's last message.";
    }

    public function addressLabel(): string
    {
        return 'Instagram username';
    }

    protected function webhookObject(): string
    {
        return 'instagram';
    }
}
