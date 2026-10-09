<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Event registration: attendees register for open events with a free place, once per email, pay
 * the fee before they count as paid, and are checked in only once the event has begun. Registration
 * closes by itself when the event starts, completing an event marks everyone who never came as a
 * no-show, and the home page lists badges still to print.
 */
class EventRegistrationLogic extends AppLogic
{
    public const COUNTED = ['registered', 'paid', 'attended', 'no_show'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'events') {
            $registered = $existing ? $this->linked('registrations', 'event', $existing)->whereIn('status', self::COUNTED)->count() : 0;
            if (filled($data['capacity'] ?? null) && (int) $data['capacity'] < 0) {
                $errors['data.capacity'] = 'Capacity cannot be negative.';
            } elseif (filled($data['capacity'] ?? null) && (int) $data['capacity'] > 0 && (int) $data['capacity'] < $registered) {
                $errors['data.capacity'] = $registered.' people are already registered.';
            }
            if (filled($data['fee'] ?? null) && (float) $data['fee'] < 0) {
                $errors['data.fee'] = 'The fee cannot be negative.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The event ends before it starts.';
            }
            if ($payload['status'] === 'completed' && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
                $errors['status'] = 'The event has not started yet.';
            }

            return $errors;
        }

        $event = ! empty($data['event']) ? $this->records('events')->find($data['event']) : null;
        $joining = $event && in_array($payload['status'], self::COUNTED, true) && (! $existing || (int) $existing->value('event') !== $event->id || ! in_array($existing->status, self::COUNTED, true));
        if ($joining && $event->status !== 'open') {
            $errors['data.event'] = 'Registration for '.$event->title.' is '.$event->status.'.';
        } elseif ($joining && (int) $event->value('capacity') > 0 && $this->linked('registrations', 'event', $event)->whereIn('status', self::COUNTED)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->count() >= (int) $event->value('capacity')) {
            $errors['data.event'] = $event->title.' is full.';
        }
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        if ($event && $email !== '' && $this->linked('registrations', 'event', $event)->whereIn('status', self::COUNTED)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $registration) => mb_strtolower(trim((string) $registration->value('email'))) === $email)) {
            $errors['data.email'] = $email.' is already registered for '.$event->title.'.';
        }
        $fee = (float) ($event?->value('fee') ?? 0);
        $paid = (float) ($payload['amount'] ?? 0);
        if ($paid < 0) {
            $errors['amount'] = 'The fee paid cannot be negative.';
        } elseif ($payload['status'] === 'paid' && $fee > 0 && $paid < $fee && $existing?->status !== 'paid') {
            $errors['amount'] = 'The fee for '.$event->title.' is '.$this->money($fee).'.';
        }
        if (in_array($payload['status'], ['attended', 'no_show'], true) && $event?->occurs_on?->gt(today())) {
            $errors['status'] = $event->title.' only starts on '.$event->occurs_on->format('d M Y').'.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'registrations') {
            $record->occurs_on ??= today();
            $event = $this->parent($record, 'event');
            $fee = (float) ($event?->value('fee') ?? 0);
            if (in_array($record->status, ['paid', 'attended'], true) && $fee > 0 && (float) $record->amount <= 0) {
                $record->amount = $fee;
            }
            $this->put($record, [
                'email' => mb_strtolower(trim((string) $record->value('email'))),
                'badge_printed' => (bool) $record->value('badge_printed'),
                '_fee' => $fee,
                '_balance' => in_array($record->status, ['cancelled'], true) ? 0 : round(max(0, $fee - (float) $record->amount), 2),
                '_attended_on' => $record->status === 'attended' ? ($record->value('_attended_on') ?? today()->toDateString()) : null,
            ]);

            return;
        }

        $registrations = $record->exists ? $this->linked('registrations', 'event', $record)->get() : collect();
        $counted = $registrations->whereIn('status', self::COUNTED);
        $this->put($record, [
            '_registered' => $counted->count(),
            '_paid' => $registrations->whereIn('status', ['paid', 'attended'])->count(),
            '_attended' => $registrations->where('status', 'attended')->count(),
            '_no_show' => $registrations->where('status', 'no_show')->count(),
            '_remaining' => (int) $record->value('capacity') > 0 ? max(0, (int) $record->value('capacity') - $counted->count()) : null,
            '_fees' => round($counted->sum('amount'), 2),
            '_outstanding' => round($counted->sum(fn (Record $registration) => (float) $registration->value('_balance')), 2),
            '_badges_to_print' => $counted->filter(fn (Record $registration) => ! $registration->value('badge_printed') && $registration->status !== 'no_show')->count(),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'registrations') {
            $this->recalculate($this->parent($record, 'event'));
            $this->recalculate($this->previousParent($record, 'event'));
        } else {
            foreach ($this->linked('registrations', 'event', $record)->get() as $registration) {
                if ((float) $registration->value('_fee') !== (float) $record->value('fee')) {
                    $this->recalculate($registration);
                }
            }
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'registrations') {
            $this->recalculate($this->parent($record, 'event'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('events')->where('status', 'open')->whereNotNull('occurs_on')->whereDate('occurs_on', '<', today())->update(['status' => 'closed']);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'events') {
            return match ($record->status) {
                'open' => ['close' => ['label' => 'Close registration', 'icon' => 'lock'], 'complete' => ['label' => 'Complete', 'icon' => 'check-circle', 'confirm' => 'Complete '.$record->title.'? Anyone not checked in becomes a no-show.'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel '.$record->title.'?']],
                'closed' => ['reopen' => ['label' => 'Reopen registration', 'icon' => 'unlock'], 'complete' => ['label' => 'Complete', 'icon' => 'check-circle', 'confirm' => 'Complete '.$record->title.'? Anyone not checked in becomes a no-show.'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel '.$record->title.'?']],
                default => [],
            };
        }

        $badge = ['label' => 'Badge printed', 'icon' => 'id-card'];
        $pay = ['label' => 'Record payment', 'icon' => 'banknote', 'fields' => [
            ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'value' => $this->number($record, '_balance') ?: $record->value('_fee')],
        ]];
        $actions = match ($record->status) {
            'registered' => ['record_payment' => $pay, 'check_in' => ['label' => 'Check in', 'icon' => 'door-open'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this registration?']],
            'paid' => ['check_in' => ['label' => 'Check in', 'icon' => 'door-open'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this registration?']],
            default => [],
        };
        if (in_array($record->status, ['registered', 'paid', 'attended'], true) && ! $record->value('badge_printed')) {
            $actions['print_badge'] = $badge;
        }

        return $actions;
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'close':
                $record->update(['status' => 'closed']);

                return 'Registration for '.$record->title.' is closed.';
            case 'reopen':
                if ($record->occurs_on?->lt(today())) {
                    throw ValidationException::withMessages(['status' => $record->title.' has already started.']);
                }
                $record->update(['status' => 'open']);

                return 'Registration for '.$record->title.' is open.';
            case 'complete':
                if ($record->occurs_on?->gt(today())) {
                    throw ValidationException::withMessages(['status' => 'The event has not started yet.']);
                }
                $noShows = 0;
                foreach ($this->linked('registrations', 'event', $record)->whereIn('status', ['registered', 'paid'])->get() as $registration) {
                    $registration->update(['status' => 'no_show']);
                    $noShows++;
                }
                $record->update(['status' => 'completed']);

                return $record->title.' completed; '.$noShows.' marked as no-shows.';
            case 'cancel':
                if ($record->entity === 'events') {
                    $record->update(['status' => 'cancelled']);

                    return $record->title.' cancelled; '.(int) $record->value('_registered').' attendees to tell.';
                }
                $record->update(['status' => 'cancelled']);

                return $record->title.'\'s registration is cancelled.';
            case 'record_payment':
                $amount = (float) $request->validate(['amount' => ['required', 'numeric', 'gt:0']])['amount'];
                $paid = (float) $record->amount + $amount;
                $fee = (float) $record->value('_fee');
                $record->update(['amount' => $paid, 'status' => $fee <= 0 || $paid >= $fee ? 'paid' : $record->status]);

                return $record->title.($fee <= 0 || $paid >= $fee ? ' has paid in full.' : ' still owes '.$this->money($fee - $paid).'.');
            case 'print_badge':
                $record->update(['data' => [...$record->data, 'badge_printed' => true]]);

                return 'Badge printed for '.$record->title.'.';
        }

        $event = $this->parent($record, 'event');
        if ($event?->occurs_on?->gt(today())) {
            throw ValidationException::withMessages(['status' => $event->title.' only starts on '.$event->occurs_on->format('d M Y').'.']);
        }
        if ($this->number($record, '_balance') > 0) {
            throw ValidationException::withMessages(['amount' => $record->title.' still owes '.$this->money($this->number($record, '_balance')).'.']);
        }
        $record->update(['status' => 'attended']);

        return $record->title.' checked in.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'events') {
            return [];
        }

        $registrations = $this->linked('registrations', 'event', $record)->whereIn('status', self::COUNTED)->orderBy('title')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Registrations', 'icon' => 'clipboard-pen', 'stats' => [
                ['label' => 'Registered', 'value' => (int) $record->value('_registered').((int) $record->value('capacity') > 0 ? ' of '.(int) $record->value('capacity') : ''), 'tone' => $record->value('_remaining') === 0 ? 'warning' : null],
                ['label' => 'Paid', 'value' => (string) (int) $record->value('_paid')],
                ['label' => 'Attended', 'value' => (string) (int) $record->value('_attended'), 'tone' => (int) $record->value('_attended') > 0 ? 'success' : null],
                ['label' => 'No-shows', 'value' => (string) (int) $record->value('_no_show')],
                ['label' => 'Fees', 'value' => $this->money($this->number($record, '_fees'))],
                ['label' => 'Outstanding', 'value' => $this->money($this->number($record, '_outstanding')), 'tone' => $this->number($record, '_outstanding') > 0 ? 'warning' : null],
                ['label' => 'Badges to print', 'value' => (string) (int) $record->value('_badges_to_print'), 'tone' => (int) $record->value('_badges_to_print') > 0 ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Attendees', 'icon' => 'id-card', 'empty' => 'Nobody registered yet.',
                'rows' => $registrations->take(15)->map(fn (Record $registration) => [
                    'label' => $registration->title, 'sub' => implode(' · ', array_filter([$registration->value('organisation'), $registration->value('dietary') ? 'Diet: '.$registration->value('dietary') : null])), 'value' => ucfirst(str_replace('_', ' ', $registration->status)).($this->number($registration, '_balance') > 0 ? ' · owes '.$this->money($this->number($registration, '_balance')) : ''), 'href' => $registration->url(),
                    'tone' => $registration->status === 'attended' ? 'success' : ($registration->status === 'no_show' ? 'danger' : ($this->number($registration, '_balance') > 0 ? 'warning' : null)),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $events = $this->records('events')->get();
        $registrations = $this->records('registrations')->get();
        $upcoming = $events->filter(fn (Record $event) => in_array($event->status, ['open', 'closed'], true) && $event->occurs_on?->gte(today()))->sortBy('occurs_on');
        $badges = $registrations->filter(fn (Record $registration) => in_array($registration->status, ['registered', 'paid'], true) && ! $registration->value('badge_printed'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Events', 'icon' => 'presentation', 'stats' => [
                ['label' => 'Open for registration', 'value' => (string) $events->where('status', 'open')->count()],
                ['label' => 'Upcoming', 'value' => (string) $upcoming->count()],
                ['label' => 'Registered', 'value' => (string) $registrations->whereIn('status', ['registered', 'paid'])->count()],
                ['label' => 'Fees outstanding', 'value' => $this->money($registrations->whereIn('status', self::COUNTED)->sum(fn (Record $registration) => (float) $registration->value('_balance')))],
                ['label' => 'Badges to print', 'value' => (string) $badges->count(), 'tone' => $badges->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Upcoming events', 'icon' => 'calendar-days', 'empty' => 'No events coming up.',
                'rows' => $upcoming->take(10)->map(fn (Record $event) => [
                    'label' => $event->title, 'sub' => ($event->value('venue') ?: 'No venue').' · '.$event->occurs_on->format('d M Y'), 'value' => (int) $event->value('_registered').((int) $event->value('capacity') > 0 ? ' / '.(int) $event->value('capacity') : '').' registered', 'href' => $event->url(), 'tone' => $event->status === 'closed' ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $events = $this->dated('events', $from, $to)->get();
        $eventRows = $events->sortBy('occurs_on')->map(fn (Record $event) => [
            $event->title, $event->value('venue') ?: '—', $event->occurs_on?->format('d M Y') ?? '—', ucfirst($event->status), (int) $event->value('_registered'), (int) $event->value('_attended'), (int) $event->value('_no_show'), $this->money((float) $event->value('_fees')), $this->money((float) $event->value('_outstanding')),
        ])->values()->all();

        $registrations = $this->dated('registrations', $from, $to)->get()->whereIn('status', self::COUNTED);
        $byOrganisation = $registrations->groupBy(fn (Record $registration) => $registration->value('organisation') ?: 'No organisation')->sortKeys()->map(fn ($group, $organisation) => [
            $organisation, $group->count(), $group->where('status', 'attended')->count(), $this->money($group->sum('amount')),
        ])->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($registrations) {
            $group = $registrations->filter(fn (Record $registration) => $registration->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->whereIn('status', ['paid', 'attended'])->count(), $this->money($group->sum('amount'))];
        })->values()->all();

        return [
            ['title' => 'Events', 'columns' => ['Event', 'Venue', 'Starts', 'Status', 'Registered', 'Attended', 'No-shows', 'Fees', 'Outstanding'], 'rows' => $eventRows],
            ['title' => 'Registrations by organisation', 'columns' => ['Organisation', 'Registered', 'Attended', 'Fees'], 'rows' => $byOrganisation],
            ['title' => 'Registrations by month', 'columns' => ['Month', 'Registered', 'Paid', 'Fees'], 'rows' => $byMonth],
        ];
    }
}
