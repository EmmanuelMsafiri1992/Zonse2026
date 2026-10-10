<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One in-app (and optionally emailed) alert for a person in a workspace: work assigned to them,
 * a note on their work, a payment received, a team change or a plan reminder.
 */
class WorkspaceAlert extends Notification
{
    use Queueable;

    public function __construct(
        public Workspace $workspace,
        public string $kind,
        public string $title,
        public ?string $body = null,
        public ?string $url = null,
        public string $icon = 'bell',
    ) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return array_values(array_filter([
            Notifier::wants($notifiable, $this->kind, 'app') ? 'database' : null,
            Notifier::wants($notifiable, $this->kind, 'email') ? 'mail' : null,
        ]));
    }

    public function databaseType(object $notifiable): string
    {
        return $this->kind;
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title.' · '.$this->workspace->name)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->title);

        if ($this->body) {
            $mail->line($this->body);
        }
        if ($this->url) {
            $mail->action('Open in Zonseob', $this->url);
        }

        return $mail->line('You can choose which emails you get under My profile › Notifications.');
    }

    /** @return array{workspace_id: int, title: string, body: ?string, url: ?string, icon: string} */
    public function toArray(object $notifiable): array
    {
        return [
            'workspace_id' => $this->workspace->id,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => $this->icon,
        ];
    }
}
