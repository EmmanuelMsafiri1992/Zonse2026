<?php

namespace App\Listeners;

use App\Models\Comment;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Notifier;
use Illuminate\Database\Eloquent\Model;
use Modules\Appointments\Models\Appointment;
use Modules\Helpdesk\Models\Ticket;
use Modules\Invoicing\Models\Payment;
use Modules\Tasks\Models\Task;

/**
 * Turns model changes into in-app alerts: work assigned, notes added and payments received.
 * Method names avoid the "handle" prefix so event discovery does not register them twice.
 */
class SendWorkspaceNotifications
{
    /** Models whose assignee_id hands work to a person. */
    public const ASSIGNABLE = [Task::class => 'Task', Ticket::class => 'Ticket', Record::class => null];

    /** @return array<string, string> */
    public function subscribe(): array
    {
        $events = [];
        foreach (array_keys(self::ASSIGNABLE) as $model) {
            $events['eloquent.saved: '.$model] = 'onAssignableSaved';
        }

        return $events + [
            'eloquent.saved: '.Appointment::class => 'onAppointmentSaved',
            'eloquent.created: '.Comment::class => 'onCommentCreated',
            'eloquent.created: '.Payment::class => 'onPaymentCreated',
        ];
    }

    public function onAssignableSaved(Model $model): void
    {
        if (! $model->assignee_id || ! ($model->wasRecentlyCreated || $model->wasChanged('assignee_id'))) {
            return;
        }

        $noun = self::ASSIGNABLE[$model::class] ?? ($model instanceof Record ? rescue(fn () => $model->definition()->label, null, false) : null) ?? 'Item';
        Notifier::send(
            User::find($model->assignee_id), 'assigned', $noun.' assigned to you: '.$this->name($model),
            $this->dueText($model), $model->activityUrl(), 'user-check', $this->workspace($model),
        );
    }

    public function onAppointmentSaved(Appointment $appointment): void
    {
        if (! $appointment->staff_id || ! ($appointment->wasRecentlyCreated || $appointment->wasChanged('staff_id'))) {
            return;
        }

        Notifier::send(
            User::find($appointment->staff_id), 'assigned', 'Appointment booked with you: '.$appointment->displayTitle(),
            $appointment->starts_at?->format('D d M Y, H:i'), $appointment->activityUrl(), 'calendar-check', $this->workspace($appointment),
        );
    }

    public function onCommentCreated(Comment $comment): void
    {
        $subject = $comment->commentable;
        if (! $subject || ! method_exists($subject, 'activityUrl')) {
            return;
        }

        $people = collect([$subject->assignee_id ?? null, $subject->created_by ?? null])->filter()->unique();
        $author = $comment->user?->name ?? $comment->author_name ?? 'Someone';

        Notifier::send(
            User::whereIn('id', $people)->get(), 'comments', $author.' added a note on '.$this->name($subject),
            str($comment->body)->squish()->limit(140)->toString(), $subject->activityUrl(), 'message-square',
            $this->workspace($subject), $comment->user,
        );
    }

    public function onPaymentCreated(Payment $payment): void
    {
        $workspace = $this->workspace($payment);
        if (! $workspace) {
            return;
        }

        $invoice = $payment->invoice;
        Notifier::send(
            Notifier::admins($workspace), 'payments', 'Payment received: '.$payment->money(),
            trim(($invoice ? 'Invoice '.$invoice->number : '').($payment->method === 'online' ? ' · paid online by the customer' : '')) ?: null,
            $payment->activityUrl(), 'banknote', $workspace,
        );
    }

    /** The item's own title ("Count the stock"), falling back to its log label. */
    protected function name(Model $model): string
    {
        return (string) ($model->title ?? $model->subject ?? null ?: $model->activityLabel());
    }

    protected function dueText(Model $model): ?string
    {
        $due = $model->due_date ?? $model->due_on ?? null;

        return $due ? 'Due '.$due->format('D d M Y') : null;
    }

    protected function workspace(Model $model): ?Workspace
    {
        return $model->workspace_id ? Workspace::find($model->workspace_id) : null;
    }
}
