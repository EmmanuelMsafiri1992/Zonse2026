<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Sms\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Patient appointments: a practitioner is never booked twice for the same date and time, and
 * appointments are not booked in the past. The evening before, patients who asked for an SMS
 * reminder are texted (through the workspace's SMS provider), and booked patients who never
 * arrived become no-shows the next morning. A cancelled slot is offered to the most urgent
 * patient on the waitlist, who can be booked straight into it.
 */
class PatientAppointmentsLogic extends AppLogic
{
    /** @var list<string> */
    public const ACTIVE = ['booked', 'confirmed', 'arrived', 'seen'];

    /** @var array<string, int> */
    public const URGENCY = ['urgent' => 0, 'soon' => 1, 'routine' => 2];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key !== 'appointments') {
            if ($payload['status'] === 'offered' && blank($data['_offered_date'] ?? $existing?->value('_offered_date'))) {
                $errors['status'] = 'Offer a slot with the Offer action.';
            }

            return $errors;
        }

        if (in_array($payload['status'], ['booked', 'confirmed'], true) && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->lt(today()) && ($existing?->occurs_on?->toDateString() !== Carbon::parse($payload['occurs_on'])->toDateString())) {
            $errors['occurs_on'] = 'Appointments cannot be booked in the past.';
        }
        if (in_array($payload['status'], self::ACTIVE, true) && filled($payload['occurs_on'] ?? null) && filled($data['start_time'] ?? null) && filled($data['practitioner'] ?? null)
            && $clash = $this->clash(Carbon::parse($payload['occurs_on']), (string) $data['start_time'], (int) $data['practitioner'], $existing?->id)) {
            $errors['data.start_time'] = (User::query()->whereKey($data['practitioner'])->value('name') ?? 'This practitioner').' already sees '.$clash->title.' at '.$data['start_time'].' that day.';
        }
        if (in_array($data['reminder'] ?? null, ['sms', 'whatsapp'], true) && blank($data['phone'] ?? null)) {
            $errors['data.phone'] = 'A reminder by '.strtoupper((string) $data['reminder']).' needs the patient\'s phone.';
        }

        return $errors;
    }

    /**
     * The live appointment already holding this practitioner's slot, if any.
     */
    protected function clash(Carbon $date, string $time, int $practitioner, ?int $except = null): ?Record
    {
        return $this->records('appointments')->whereDate('occurs_on', $date)->whereIn('status', self::ACTIVE)
            ->when($except, fn ($query) => $query->whereKeyNot($except))->get()
            ->first(fn (Record $appointment) => (int) $appointment->value('practitioner') === $practitioner && substr((string) $appointment->value('start_time'), 0, 5) === substr($time, 0, 5));
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'appointments') {
            $this->put($record, ['reminder' => $record->value('reminder') ?: 'none', 'reminder_sent' => (bool) $record->value('reminder_sent')]);
        } else {
            $this->put($record, ['urgency' => $record->value('urgency') ?: 'routine']);
        }
    }

    public function daily(Workspace $workspace): int
    {
        $done = 0;
        foreach ($this->records('appointments')->whereIn('status', ['booked', 'confirmed'])->whereDate('occurs_on', '<', today())->get() as $appointment) {
            $appointment->update(['status' => 'no_show']);
            $done++;
        }

        $sms = app(SmsService::class);
        if (! $sms->enabled($workspace)) {
            return $done;
        }
        $tomorrow = $this->records('appointments')->whereIn('status', ['booked', 'confirmed'])->whereDate('occurs_on', today()->addDay())->get()
            ->filter(fn (Record $appointment) => $appointment->value('reminder') === 'sms' && ! $appointment->value('reminder_sent') && filled($appointment->value('phone')));
        foreach ($tomorrow as $appointment) {
            $body = SmsService::prefix($workspace).'Reminder: '.($appointment->value('reason') ? $appointment->value('reason').' appointment' : 'your appointment').' tomorrow, '.$appointment->occurs_on->format('D j M').' at '.substr((string) $appointment->value('start_time'), 0, 5).'.'
                .($workspace->phone ? ' To change it call '.$workspace->phone.'.' : '');
            if ($sms->send($workspace, (string) $appointment->value('phone'), $body, ['purpose' => 'appointment', 'subject' => $appointment, 'sent_by' => null])) {
                $appointment->update(['data' => [...$appointment->data, 'reminder_sent' => true]]);
                $done++;
            }
        }

        return $done;
    }

    /**
     * Waiting patients, most urgent and longest waiting first.
     *
     * @return Collection<int, Record>
     */
    protected function waitlist(): Collection
    {
        return $this->records('waitlist')->where('status', 'waiting')->get()
            ->sortBy(fn (Record $entry) => sprintf('%d-%s-%010d', self::URGENCY[$entry->value('urgency')] ?? 2, $entry->occurs_on?->toDateString(), $entry->id))->values();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'waitlist') {
            return match ($record->status) {
                'offered' => ['book' => ['label' => 'Book the offered slot', 'icon' => 'calendar-check'], 'decline' => ['label' => 'Declined', 'icon' => 'x']],
                'waiting' => ['remove' => ['label' => 'Remove', 'icon' => 'trash']],
                default => [],
            };
        }

        return match ($record->status) {
            'booked' => ['confirm' => ['label' => 'Confirmed', 'icon' => 'check'], 'arrive' => ['label' => 'Arrived', 'icon' => 'door-open'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'confirmed' => ['arrive' => ['label' => 'Arrived', 'icon' => 'door-open'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'arrived' => ['seen' => ['label' => 'Seen', 'icon' => 'stethoscope']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'waitlist') {
            return $this->waitlistAction($action, $record);
        }

        if ($action !== 'cancel') {
            $status = ['confirm' => 'confirmed', 'arrive' => 'arrived', 'seen' => 'seen'][$action];
            if ($status === 'arrived' && ! $record->occurs_on?->isToday()) {
                throw ValidationException::withMessages(['status' => 'This appointment is on '.$record->occurs_on?->format('d M Y').', not today.']);
            }
            $record->update(['status' => $status]);

            return $record->title.' '.($status === 'seen' ? 'seen' : $status).'.';
        }

        $record->update(['status' => 'cancelled']);
        if ($record->occurs_on?->lt(today()) || ! $entry = $this->waitlist()->first()) {
            return $record->title.'\'s appointment cancelled.';
        }
        $entry->update(['status' => 'offered', 'data' => [...$entry->data,
            '_offered_date' => $record->occurs_on->toDateString(),
            '_offered_time' => substr((string) $record->value('start_time'), 0, 5),
            '_offered_practitioner' => $record->value('practitioner'),
            '_offered_reason' => $record->value('reason'),
        ]]);

        return $record->title.'\'s appointment cancelled; the slot is offered to '.$entry->title.' ('.$entry->value('phone').').';
    }

    /**
     * Book, decline or remove a waitlist entry.
     */
    protected function waitlistAction(string $action, Record $entry): string
    {
        if ($action === 'remove') {
            $entry->update(['status' => 'removed']);

            return $entry->title.' removed from the waitlist.';
        }
        $clean = collect($entry->data)->reject(fn ($value, string $key) => str_starts_with($key, '_offered_'))->all();
        if ($action === 'decline') {
            $entry->update(['status' => 'waiting', 'data' => [...$clean, '_declined' => (int) $entry->value('_declined') + 1]]);

            return $entry->title.' declined the slot and stays on the waitlist.';
        }

        $date = Carbon::parse($entry->value('_offered_date'));
        $time = (string) $entry->value('_offered_time');
        if ($date->lt(today()) || $this->clash($date, $time, (int) $entry->value('_offered_practitioner'))) {
            $entry->update(['status' => 'waiting', 'data' => $clean]);
            throw ValidationException::withMessages(['status' => 'That slot is no longer free; '.$entry->title.' is back on the waitlist.']);
        }

        $appointment = Record::create([
            'workspace_id' => $entry->workspace_id,
            'blueprint' => $entry->blueprint,
            'entity' => 'appointments',
            'title' => $entry->title,
            'status' => 'booked',
            'occurs_on' => $date,
            'data' => ['start_time' => $time, 'practitioner' => $entry->value('_offered_practitioner'), 'reason' => $entry->value('_offered_reason'), 'phone' => $entry->value('phone'), 'reminder' => 'sms'],
        ]);
        $entry->update(['status' => 'booked', 'data' => [...$entry->data, '_appointment' => $appointment->id]]);

        return $entry->title.' booked for '.$date->format('d M Y').' at '.$time.'.';
    }

    public function homeCards(): array
    {
        $today = $this->records('appointments')->whereDate('occurs_on', today())->get()->sortBy(fn (Record $appointment) => $appointment->value('start_time'));
        $names = User::query()->whereIn('id', $today->map(fn (Record $appointment) => $appointment->value('practitioner'))->filter()->unique())->pluck('name', 'id');
        $manual = $this->records('appointments')->whereIn('status', ['booked', 'confirmed'])->whereDate('occurs_on', today()->addDay())->get()
            ->filter(fn (Record $appointment) => in_array($appointment->value('reminder'), ['sms', 'whatsapp', 'email'], true) && ! $appointment->value('reminder_sent'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today', 'icon' => 'calendar-clock', 'stats' => [
                ['label' => 'Booked', 'value' => (string) $today->whereIn('status', self::ACTIVE)->count()],
                ['label' => 'Waiting room', 'value' => (string) $today->where('status', 'arrived')->count()],
                ['label' => 'Seen', 'value' => (string) $today->where('status', 'seen')->count()],
                ['label' => 'Cancelled', 'value' => (string) $today->where('status', 'cancelled')->count()],
                ['label' => 'On the waitlist', 'value' => (string) $this->waitlist()->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Day list', 'icon' => 'list', 'empty' => 'No appointments today.',
                'rows' => $today->whereIn('status', self::ACTIVE)->map(fn (Record $appointment) => [
                    'label' => substr((string) $appointment->value('start_time'), 0, 5).' · '.$appointment->title, 'sub' => (string) ($names[$appointment->value('practitioner')] ?? ''), 'value' => ucfirst($appointment->status), 'href' => $appointment->url(), 'tone' => $appointment->status === 'arrived' ? 'warning' : null,
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Reminders for tomorrow', 'icon' => 'bell', 'empty' => 'Every reminder for tomorrow has gone out.',
                'rows' => $manual->take(10)->map(fn (Record $appointment) => [
                    'label' => $appointment->title, 'sub' => strtoupper((string) $appointment->value('reminder')).' · '.$appointment->value('phone'), 'value' => substr((string) $appointment->value('start_time'), 0, 5), 'href' => $appointment->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $appointments = $this->dated('appointments', $from, $to)->get();
        $names = User::query()->whereIn('id', $appointments->map(fn (Record $appointment) => $appointment->value('practitioner'))->filter()->unique())->pluck('name', 'id');
        $rate = fn (Collection $group) => $group->isEmpty() ? '—' : (int) round($group->where('status', 'no_show')->count() / $group->count() * 100).'%';

        $byPractitioner = $appointments->groupBy(fn (Record $appointment) => $names[$appointment->value('practitioner')] ?? 'Unassigned')->sortKeys()->map(fn (Collection $group, string $name) => [
            $name, $group->count(), $group->where('status', 'seen')->count(), $group->where('status', 'no_show')->count(), $group->where('status', 'cancelled')->count(), $rate($group),
        ])->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($appointments, $rate) {
            $group = $appointments->filter(fn (Record $appointment) => $appointment->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->where('status', 'seen')->count(), $group->where('status', 'no_show')->count(), $group->where('status', 'cancelled')->count(), $rate($group)];
        })->values()->all();

        $reminders = $appointments->groupBy(fn (Record $appointment) => $appointment->value('reminder') ?: 'none')->sortKeys()->map(fn (Collection $group, string $reminder) => [
            $reminder === 'sms' ? 'SMS' : ucfirst($reminder), $group->count(), $group->filter(fn (Record $appointment) => $appointment->value('reminder_sent'))->count(), $rate($group),
        ])->values()->all();

        return [
            ['title' => 'Appointments by practitioner', 'columns' => ['Practitioner', 'Booked', 'Seen', 'No-shows', 'Cancelled', 'No-show rate'], 'rows' => $byPractitioner],
            ['title' => 'Appointments by month', 'columns' => ['Month', 'Booked', 'Seen', 'No-shows', 'Cancelled', 'No-show rate'], 'rows' => $byMonth],
            ['title' => 'Reminders and no-shows', 'columns' => ['Reminder', 'Appointments', 'Reminders sent', 'No-show rate'], 'rows' => $reminders],
        ];
    }
}
