<?php

namespace App\Notifications;

use App\Models\SignatureSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Emailed to every signer once all have signed, pointing at the signed copy and its certificate. */
class SignatureCompleted extends Notification
{
    use Queueable;

    public function __construct(public SignatureSigner $signer) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->signer->request;

        return (new MailMessage)
            ->subject('Signed by everyone: '.$request->title.' · '.$request->workspace->name)
            ->greeting('Hello '.$this->signer->name.',')
            ->line('Everyone has signed "'.$request->title.'".')
            ->line('You can download the document and its signing certificate for your records.')
            ->action('Download your copy', $this->signer->signingUrl());
    }
}
