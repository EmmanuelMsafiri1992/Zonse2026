<?php

namespace App\Notifications;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Emailed to a visitor of a workspace's public page after they book, order or ask to pay. */
class PublicPageConfirmation extends Notification
{
    use Queueable;

    /** @param  list<string>  $lines */
    public function __construct(public Workspace $workspace, public string $subject, public array $lines, public ?string $actionText = null, public ?string $actionUrl = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->greeting('Hello,');

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        if ($this->actionText && $this->actionUrl) {
            $mail->action($this->actionText, $this->actionUrl);
        }

        return $mail->salutation('— '.$this->workspace->name);
    }
}
