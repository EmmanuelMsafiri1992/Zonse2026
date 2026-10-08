<?php

namespace App\Notifications;

use App\Models\PortalAccess;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Emailed to a portal contact with a one-time link that signs them in. */
class PortalSignInLink extends Notification
{
    use Queueable;

    public function __construct(public Workspace $workspace, public PortalAccess $access, public string $token, public bool $invitation = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        return route('portal.enter', [$this->workspace, $this->token]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->access->contact?->displayName() ?? 'there';

        $mail = (new MailMessage)
            ->subject(($this->invitation ? 'Your portal at ' : 'Sign in to ').$this->workspace->name)
            ->greeting('Hello '.$name.',');

        if ($this->invitation) {
            $mail->line($this->workspace->name.' has opened an online portal for you. You can see your invoices, bookings and requests in one place.');
        }

        return $mail->action('Open my portal', $this->url())
            ->line('This link works once and expires in '.PortalAccess::LINK_MINUTES.' minutes. You can ask for a new one at any time.')
            ->line('If you did not ask for this, you can ignore this email.');
    }
}
