<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public Invitation $invitation) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspace = $this->invitation->workspace;
        $inviter = $this->invitation->inviter?->name ?? 'A colleague';

        return (new MailMessage)
            ->subject("You've been invited to {$workspace->name} on Zonseo")
            ->greeting('Hello!')
            ->line("{$inviter} has invited you to join the {$workspace->name} workspace as a {$this->invitation->role}.")
            ->action('Accept invitation', $this->invitation->url())
            ->line('This invitation expires on '.$this->invitation->expires_at->toFormattedDateString().'.')
            ->line('If you were not expecting this, you can ignore this email.');
    }
}
