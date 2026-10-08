<?php

namespace App\Notifications;

use App\Models\SignatureSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Emailed to a signer (who need not have an account) with their personal signing link. */
class SignatureRequested extends Notification
{
    use Queueable;

    public function __construct(public SignatureSigner $signer, public bool $reminder = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->signer->request;
        $workspace = $request->workspace;
        $sender = $request->creator?->name ?? $workspace->name;

        $mail = (new MailMessage)
            ->subject(($this->reminder ? 'Reminder: please sign ' : 'Please sign ').$request->title.' · '.$workspace->name)
            ->greeting('Hello '.$this->signer->name.',')
            ->line($sender.' of '.$workspace->name.' has asked you to sign "'.$request->title.'".');

        if ($request->message) {
            $mail->line('"'.$request->message.'"');
        }
        if ($request->expires_at) {
            $mail->line('Please sign by '.$request->expires_at->format('d M Y').'.');
        }

        return $mail->action('Review and sign', $this->signer->signingUrl())
            ->line('This link is personal to you. Do not forward it.');
    }
}
