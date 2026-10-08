<?php

namespace App\Listeners;

use App\Support\Webhooks;
use Illuminate\Database\Eloquent\Model;
use Modules\Appointments\Models\Appointment;
use Modules\Contacts\Models\Contact;
use Modules\Helpdesk\Models\Ticket;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Payment;
use Modules\Tasks\Models\Task;

/**
 * Turns model changes into webhook events ("task.created", "invoice.paid" ...).
 * Method names avoid the "handle" prefix so event discovery does not register them twice.
 */
class DispatchWebhooks
{
    /** @var array<class-string<Model>, string> */
    public const NAMES = [
        Contact::class => 'contact',
        Task::class => 'task',
        Ticket::class => 'ticket',
        Invoice::class => 'invoice',
        Appointment::class => 'appointment',
    ];

    /** @return array<string, string> */
    public function subscribe(): array
    {
        $events = ['eloquent.created: '.Payment::class => 'onPaymentCreated'];
        foreach (array_keys(self::NAMES) as $model) {
            $events['eloquent.created: '.$model] = 'onCreated';
            $events['eloquent.updated: '.$model] = 'onUpdated';
        }

        return $events;
    }

    public function onCreated(Model $model): void
    {
        Webhooks::dispatch(self::NAMES[$model::class].'.created', $model);
    }

    public function onUpdated(Model $model): void
    {
        $changed = array_diff(array_keys($model->getChanges()), [$model->getUpdatedAtColumn(), 'last_activity_at', 'position']);
        if ($changed === []) {
            return;
        }

        $name = self::NAMES[$model::class];
        if ($model instanceof Invoice) {
            if ($model->wasChanged('status') && $model->status === 'paid') {
                Webhooks::dispatch('invoice.paid', $model);
            }

            return;
        }

        Webhooks::dispatch($name.'.updated', $model);

        if ($model instanceof Task && $model->wasChanged('status') && $model->status === 'done') {
            Webhooks::dispatch('task.completed', $model);
        }
    }

    public function onPaymentCreated(Payment $payment): void
    {
        Webhooks::dispatch('payment.received', $payment);
    }
}
