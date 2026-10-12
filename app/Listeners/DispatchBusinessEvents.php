<?php

namespace App\Listeners;

use App\Models\Record;
use App\Support\Automations;
use App\Support\Webhooks;
use Illuminate\Database\Eloquent\Model;
use Modules\Appointments\Models\Appointment;
use Modules\Contacts\Models\Contact;
use Modules\Helpdesk\Models\Ticket;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Payment;
use Modules\Tasks\Models\Task;

/**
 * Turns model changes into business events ("task.created", "invoice.paid" ...) and hands each one to
 * webhooks and automations.
 * Method names avoid the "handle" prefix so event discovery does not register them twice.
 */
class DispatchBusinessEvents
{
    /** @var array<class-string<Model>, string> */
    public const NAMES = [
        Contact::class => 'contact',
        Task::class => 'task',
        Ticket::class => 'ticket',
        Invoice::class => 'invoice',
        Appointment::class => 'appointment',
        Record::class => 'record',
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
        $this->fire(self::NAMES[$model::class].'.created', $model);
    }

    public function onUpdated(Model $model): void
    {
        $changed = array_diff(array_keys($model->getChanges()), [$model->getUpdatedAtColumn(), 'last_activity_at', 'position']);
        if ($model instanceof Record) {
            $changed = $this->recordChanges($model, $changed);
        }
        if ($changed === []) {
            return;
        }

        $name = self::NAMES[$model::class];
        if ($model instanceof Invoice) {
            if ($model->wasChanged('status') && $model->status === 'paid') {
                $this->fire('invoice.paid', $model, $changed);
            }

            return;
        }

        $this->fire($name.'.updated', $model, $changed);

        if ($model instanceof Task && $model->wasChanged('status') && $model->status === 'done') {
            $this->fire('task.completed', $model, $changed);
        }
    }

    public function onPaymentCreated(Payment $payment): void
    {
        $this->fire('payment.received', $payment);
    }

    /**
     * An app record's changes, with its "data" column opened up into the fields that changed. Keys
     * starting with "_" and the search index are the app's own bookkeeping, so changing only those is no event.
     *
     * @param  array<int, string>  $changed
     * @return list<string>
     */
    protected function recordChanges(Record $record, array $changed): array
    {
        $changed = array_diff($changed, ['search_text']);
        if (! in_array('data', $changed, true)) {
            return array_values($changed);
        }

        $before = (array) json_decode((string) $record->getRawOriginal('data'), true);
        $after = (array) $record->data;
        $fields = collect(array_keys($before + $after))
            ->reject(fn ($key) => str_starts_with((string) $key, '_'))
            ->filter(fn ($key) => ($before[$key] ?? null) != ($after[$key] ?? null))
            ->values()->all();

        return array_values(array_merge(array_diff($changed, ['data']), $fields));
    }

    /** @param list<string> $changed */
    protected function fire(string $event, Model $model, array $changed = []): void
    {
        Webhooks::dispatch($event, $model);
        Automations::trigger($event, $model, array_values($changed));
    }
}
