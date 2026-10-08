<?php

namespace App\Notifications;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** An email an automation sends to a customer, written by the workspace. */
class AutomationEmail extends Notification
{
    use Queueable;

    public function __construct(public Workspace $workspace, public string $subject, public string $body) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->greeting($this->subject);

        foreach (preg_split('/\R{2,}/', trim($this->body)) ?: [] as $paragraph) {
            if (trim($paragraph) !== '') {
                $mail->line(trim($paragraph));
            }
        }

        return $mail->salutation('Regards, '.$this->workspace->name);
    }
}
