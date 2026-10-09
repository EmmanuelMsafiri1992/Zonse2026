<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Telemedicine: a doctor is never booked into two video consults at the same time, every consult
 * gets its own meeting link, and the call moves from the waiting room into the call and out with its
 * length kept. E-prescriptions are only written from a consult that actually took place, and they
 * travel to the pharmacy and back. Calls nobody joined become no-shows overnight.
 */
class TelemedicineLogic extends AppLogic
{
    /**
     * Consult statuses that hold the doctor's time.
     */
    protected const ACTIVE = ['booked', 'waiting_room', 'in_call'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'eprescriptions') {
            $consult = filled($data['consult'] ?? null) ? $this->records('consults')->find($data['consult']) : null;
            if ($consult && ! in_array($consult->status, ['in_call', 'completed'], true) && $payload['status'] !== 'cancelled') {
                $errors['data.consult'] = 'Prescribe during or after the call; '.$consult->title.' is '.str_replace('_', ' ', $consult->status).'.';
            }
            if ($payload['status'] === 'sent_to_pharmacy' && blank($data['pharmacy'] ?? null)) {
                $errors['data.pharmacy'] = 'Name the pharmacy.';
            }

            return $errors;
        }

        if (! $existing && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->lt(today())) {
            $errors['occurs_on'] = 'Book the consult for today or later.';
        }
        if (in_array($payload['status'], self::ACTIVE, true) && filled($payload['occurs_on'] ?? null) && filled($data['start_time'] ?? null) && filled($data['doctor'] ?? null)) {
            $clash = $this->records('consults')->whereDate('occurs_on', $payload['occurs_on'])->whereIn('status', self::ACTIVE)
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $consult) => (int) $consult->value('doctor') === (int) $data['doctor'] && substr((string) $consult->value('start_time'), 0, 5) === substr((string) $data['start_time'], 0, 5));
            if ($clash) {
                $errors['data.start_time'] = 'The doctor already has '.$clash->title.' at '.substr((string) $data['start_time'], 0, 5).'.';
            }
        }
        if ($payload['status'] === 'completed' && blank($data['notes'] ?? null)) {
            $errors['data.notes'] = 'Write the consultation notes.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'consults' && blank($record->value('meeting_link'))) {
            $this->put($record, ['meeting_link' => 'https://meet.zonseo.test/'.Str::lower(Str::random(4).'-'.Str::random(4).'-'.Str::random(4))]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'eprescriptions') {
            $this->tally($this->parent($record, 'consult'));
            $this->tally($this->previousParent($record, 'consult'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'eprescriptions') {
            $this->tally($this->parent($record, 'consult'));
        }
    }

    /**
     * Keep a consult's count of live prescriptions.
     */
    protected function tally(?Record $consult): void
    {
        if (! $consult) {
            return;
        }
        $count = $this->linked('eprescriptions', 'consult', $consult)->where('status', '!=', 'cancelled')->count();
        if ((int) $consult->value('_prescriptions') !== $count) {
            $consult->data = [...$consult->data, '_prescriptions' => $count];
            $consult->saveQuietly();
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'eprescriptions') {
            return match ($record->status) {
                'issued' => [
                    'send' => ['label' => 'Send to pharmacy', 'icon' => 'send', 'fields' => [['name' => 'pharmacy', 'label' => 'Pharmacy', 'type' => 'text']]],
                    'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]],
                ],
                'sent_to_pharmacy' => [
                    'dispense' => ['label' => 'Dispensed', 'icon' => 'pill'],
                    'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]],
                ],
                default => [],
            };
        }

        return match ($record->status) {
            'booked' => ['admit' => ['label' => 'Patient in waiting room', 'icon' => 'door-open'], 'no_show' => ['label' => 'No-show', 'icon' => 'user-x']],
            'waiting_room' => ['join' => ['label' => 'Start call', 'icon' => 'video'], 'no_show' => ['label' => 'No-show', 'icon' => 'user-x']],
            'in_call' => ['end' => ['label' => 'End call', 'icon' => 'phone-off', 'fields' => [['name' => 'notes', 'label' => 'Consultation notes', 'type' => 'textarea']]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'eprescriptions') {
            return $this->prescriptionAction($action, $record, $request);
        }

        switch ($action) {
            case 'admit':
                if (! $record->occurs_on?->isToday()) {
                    throw ValidationException::withMessages(['status' => 'This consult is booked for '.$record->occurs_on?->format('d M Y').'.']);
                }
                $record->update(['status' => 'waiting_room', 'data' => [...$record->data, '_joined_at' => now()->toDateTimeString()]]);

                return $record->title.' is in the waiting room.';
            case 'join':
                $waited = filled($record->value('_joined_at')) ? (int) Carbon::parse($record->value('_joined_at'))->diffInMinutes(now()) : 0;
                $record->update(['status' => 'in_call', 'data' => [...$record->data, '_call_started_at' => now()->toDateTimeString(), '_waited_minutes' => $waited]]);

                return 'Call with '.$record->title.' started: '.$record->value('meeting_link');
            case 'end':
                $notes = $request->validate(['notes' => ['required', 'string']])['notes'];
                $minutes = filled($record->value('_call_started_at')) ? max(1, (int) Carbon::parse($record->value('_call_started_at'))->diffInMinutes(now())) : null;
                $record->update(['status' => 'completed', 'data' => [...$record->data, 'notes' => $notes, '_call_ended_at' => now()->toDateTimeString(), '_call_minutes' => $minutes]]);

                return 'Call with '.$record->title.' ended'.($minutes ? ' after '.$minutes.' min' : '').'.';
        }

        $record->update(['status' => 'no_show']);

        return $record->title.' marked as a no-show.';
    }

    /**
     * Move an e-prescription along.
     */
    protected function prescriptionAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'send') {
            $pharmacy = $request->validate(['pharmacy' => [filled($record->value('pharmacy')) ? 'nullable' : 'required', 'string']])['pharmacy'] ?? $record->value('pharmacy');
            $record->update(['status' => 'sent_to_pharmacy', 'data' => [...$record->data, 'pharmacy' => $pharmacy, '_sent_at' => now()->toDateTimeString()]]);

            return $record->title.' sent to '.$pharmacy.'.';
        }
        if ($action === 'dispense') {
            $record->update(['status' => 'dispensed', 'data' => [...$record->data, '_dispensed_on' => today()->toDateString()]]);

            return $record->title.' dispensed by '.$record->value('pharmacy').'.';
        }

        $reason = $request->validate(['reason' => ['required', 'string']])['reason'];
        $record->update(['status' => 'cancelled', 'data' => [...$record->data, '_cancel_reason' => $reason]]);

        return $record->title.' cancelled.';
    }

    public function daily(Workspace $workspace): int
    {
        $missed = $this->records('consults')->whereIn('status', ['booked', 'waiting_room'])->whereDate('occurs_on', '<', today())->get();
        $missed->each(fn (Record $consult) => $consult->update(['status' => 'no_show']));

        return $missed->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'consults') {
            return [];
        }
        $prescriptions = $this->linked('eprescriptions', 'consult', $record)->orderBy('id')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Call', 'icon' => 'video', 'stats' => [
                ['label' => 'Link', 'value' => (string) $record->value('meeting_link')],
                ['label' => 'Waited', 'value' => $record->value('_waited_minutes') !== null ? $record->value('_waited_minutes').' min' : '—'],
                ['label' => 'Call length', 'value' => $record->value('_call_minutes') ? $record->value('_call_minutes').' min' : '—'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Prescriptions', 'icon' => 'file-heart', 'empty' => 'Nothing prescribed.',
                'rows' => $prescriptions->map(fn (Record $prescription) => [
                    'label' => $prescription->title, 'sub' => (string) $prescription->value('dosage'), 'value' => ucfirst(str_replace('_', ' ', $prescription->status)), 'href' => $prescription->url(), 'tone' => $prescription->status === 'cancelled' ? 'muted' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $today = $this->records('consults')->whereDate('occurs_on', today())->get()->sortBy(fn (Record $consult) => $consult->value('start_time'));
        $doctors = User::query()->whereIn('id', $today->map(fn (Record $consult) => $consult->value('doctor'))->filter()->unique())->pluck('name', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Telemedicine', 'icon' => 'video', 'stats' => [
                ['label' => 'Consults today', 'value' => (string) $today->count()],
                ['label' => 'In waiting room', 'value' => (string) $today->where('status', 'waiting_room')->count()],
                ['label' => 'In a call', 'value' => (string) $today->where('status', 'in_call')->count()],
                ['label' => 'Prescriptions to send', 'value' => (string) $this->records('eprescriptions')->where('status', 'issued')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Today\'s calls', 'icon' => 'calendar-clock', 'empty' => 'No consults today.',
                'rows' => $today->whereIn('status', self::ACTIVE)->map(fn (Record $consult) => [
                    'label' => substr((string) $consult->value('start_time'), 0, 5).' · '.$consult->title, 'sub' => (string) ($doctors[$consult->value('doctor')] ?? ''), 'value' => ucfirst(str_replace('_', ' ', $consult->status)), 'href' => $consult->url(), 'tone' => $consult->status === 'waiting_room' ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $consults = $this->dated('consults', $from, $to)->get();
        $doctors = User::query()->whereIn('id', $consults->map(fn (Record $consult) => $consult->value('doctor'))->filter()->unique())->pluck('name', 'id');
        $byDoctor = $consults->groupBy(fn (Record $consult) => $doctors[$consult->value('doctor')] ?? 'Unknown')->sortKeys()->map(function (Collection $group, string $doctor) {
            $done = $group->where('status', 'completed');

            return [$doctor, $group->count(), $done->count(), $group->where('status', 'no_show')->count(), $done->isEmpty() ? '—' : (int) round($done->avg(fn (Record $consult) => (int) $consult->value('_call_minutes'))).' min', $this->money($done->sum('amount'))];
        })->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($consults) {
            $group = $consults->filter(fn (Record $consult) => $consult->occurs_on?->format('Y-m') === $month);
            $waits = $group->filter(fn (Record $consult) => $consult->value('_waited_minutes') !== null);

            return [$label, $group->count(), $group->where('status', 'completed')->count(), $group->where('status', 'no_show')->count(), $waits->isEmpty() ? '—' : (int) round($waits->avg(fn (Record $consult) => (int) $consult->value('_waited_minutes'))).' min'];
        })->values()->all();

        $prescriptions = $this->dated('eprescriptions', $from, $to)->get();
        $byPharmacy = $prescriptions->groupBy(fn (Record $prescription) => filled($prescription->value('pharmacy')) ? (string) $prescription->value('pharmacy') : 'Not sent')->sortKeys()
            ->map(fn (Collection $group, string $pharmacy) => [$pharmacy, $group->count(), $group->where('status', 'dispensed')->count(), $group->where('status', 'cancelled')->count()])->values()->all();

        return [
            ['title' => 'Consults by doctor', 'columns' => ['Doctor', 'Booked', 'Completed', 'No-shows', 'Average call', 'Fees'], 'rows' => $byDoctor],
            ['title' => 'Consults by month', 'columns' => ['Month', 'Booked', 'Completed', 'No-shows', 'Average wait'], 'rows' => $byMonth],
            ['title' => 'Prescriptions by pharmacy', 'columns' => ['Pharmacy', 'Issued', 'Dispensed', 'Cancelled'], 'rows' => $byPharmacy],
        ];
    }
}
