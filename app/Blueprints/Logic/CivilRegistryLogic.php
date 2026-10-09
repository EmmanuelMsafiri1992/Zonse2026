<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Civil registry: births, deaths, marriages and divorces are registered with a number generated
 * from the type and year, the event cannot be after the registration, and a registration more than
 * a year after the event is flagged as late. Certificates are issued and counted, an amendment
 * needs a reason, and a cancelled registration cannot issue certificates.
 */
class CivilRegistryLogic extends AppLogic
{
    /** @var array<string, string> */
    public const PREFIXES = ['birth' => 'B', 'death' => 'D', 'marriage' => 'M', 'divorce' => 'V'];

    public const LATE_DAYS = 365;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        $registered = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today();
        if (filled($data['event_date'] ?? null) && Carbon::parse($data['event_date'])->gt($registered)) {
            $errors['data.event_date'] = 'The event cannot be after its registration.';
        }
        if ($registered->gt(today())) {
            $errors['occurs_on'] = 'A registration cannot be dated in the future.';
        }
        $number = strtoupper(trim((string) ($data['registration_number'] ?? '')));
        if ($number !== '' && $this->records('registrations')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $registration) => $registration->value('registration_number') === $number)) {
            $errors['data.registration_number'] = 'Registration '.$number.' already exists.';
        }
        if ((float) ($payload['amount'] ?? 0) < 0) {
            $errors['amount'] = 'The fee cannot be negative.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        $number = strtoupper(trim((string) $record->value('registration_number')));
        if ($number === '') {
            $prefix = (self::PREFIXES[$record->value('type')] ?? 'R').$record->occurs_on->format('Y').'-';
            $taken = $this->records('registrations')->when($record->exists, fn ($query) => $query->whereKeyNot($record->id))->get()
                ->map(fn (Record $registration) => (string) $registration->value('registration_number'))
                ->filter(fn (string $existing) => str_starts_with($existing, $prefix))
                ->map(fn (string $existing) => (int) substr($existing, strlen($prefix)))
                ->max() ?? 0;
            $number = $prefix.str_pad((string) ($taken + 1), 5, '0', STR_PAD_LEFT);
        }
        $eventDate = filled($record->value('event_date')) ? Carbon::parse($record->value('event_date')) : null;
        $delay = $eventDate ? (int) $eventDate->diffInDays($record->occurs_on) : null;
        $this->put($record, [
            'registration_number' => $number,
            '_days_to_register' => $delay,
            '_late' => $delay !== null && $delay > self::LATE_DAYS,
            '_certificates' => (int) $record->value('_certificates'),
            '_amendments' => (int) $record->value('_amendments'),
        ]);
    }

    public function actions(Record $record): array
    {
        if ($record->status === 'cancelled') {
            return [];
        }

        return [
            'issue_certificate' => ['label' => 'Issue certificate', 'icon' => 'scroll-text', 'fields' => [['name' => 'copies', 'label' => 'Copies', 'type' => 'number', 'value' => 1]]],
            'amend' => ['label' => 'Amend', 'icon' => 'pencil', 'fields' => [
                ['name' => 'title', 'label' => 'Name(s)', 'type' => 'text', 'value' => $record->title],
                ['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea'],
            ]],
            'cancel' => ['label' => 'Cancel registration', 'icon' => 'x', 'confirm' => 'Cancel registration '.$record->value('registration_number').'?'],
        ];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'issue_certificate':
                $copies = (int) $request->validate(['copies' => ['required', 'integer', 'min:1', 'max:10']])['copies'];
                $record->update(['status' => $record->status === 'registered' ? 'certificate_issued' : $record->status, 'data' => [...$record->data, '_certificates' => (int) $record->value('_certificates') + $copies, '_last_certificate_on' => today()->toDateString()]]);

                return $copies.' '.($copies === 1 ? 'certificate' : 'certificates').' issued for '.$record->value('registration_number').'.';
            case 'amend':
                $input = $request->validate(['title' => ['required', 'string'], 'reason' => ['required', 'string']]);
                if ($input['title'] === $record->title) {
                    throw ValidationException::withMessages(['title' => 'Nothing has changed.']);
                }
                $history = $record->value('_amendment_log') ?: [];
                $history[] = ['on' => today()->toDateString(), 'from' => $record->title, 'to' => $input['title'], 'reason' => $input['reason']];
                $record->update(['title' => $input['title'], 'status' => 'amended', 'data' => [...$record->data, '_amendments' => (int) $record->value('_amendments') + 1, '_amendment_log' => $history]]);

                return $record->value('registration_number').' amended.';
        }

        $record->update(['status' => 'cancelled']);

        return 'Registration '.$record->value('registration_number').' cancelled.';
    }

    public function recordCards(Record $record): array
    {
        $types = $this->app->entities['registrations']->field('type')?->options ?? [];

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Registration', 'icon' => 'scroll-text', 'stats' => [
                ['label' => 'Number', 'value' => (string) $record->value('registration_number')],
                ['label' => 'Type', 'value' => $types[$record->value('type')] ?? ucfirst((string) $record->value('type'))],
                ['label' => 'Event date', 'value' => filled($record->value('event_date')) ? Carbon::parse($record->value('event_date'))->format('d M Y') : '—'],
                ['label' => 'Registered', 'value' => $record->occurs_on?->format('d M Y').($record->value('_late') ? ' · late' : ''), 'tone' => $record->value('_late') ? 'warning' : null],
                ['label' => 'Certificates', 'value' => (string) (int) $record->value('_certificates')],
                ['label' => 'Amendments', 'value' => (string) (int) $record->value('_amendments')],
            ]]],
        ];
    }

    public function homeCards(): array
    {
        $registrations = $this->records('registrations')->get();
        $thisMonth = $registrations->filter(fn (Record $registration) => $registration->occurs_on?->isCurrentMonth());

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Civil registry', 'icon' => 'scroll-text', 'stats' => [
                ['label' => 'Births this month', 'value' => (string) $thisMonth->where('data.type', 'birth')->count()],
                ['label' => 'Deaths this month', 'value' => (string) $thisMonth->where('data.type', 'death')->count()],
                ['label' => 'Marriages this month', 'value' => (string) $thisMonth->where('data.type', 'marriage')->count()],
                ['label' => 'Late registrations', 'value' => (string) $thisMonth->filter(fn (Record $registration) => $registration->value('_late'))->count(), 'tone' => 'warning'],
                ['label' => 'Certificates this month', 'value' => (string) $registrations->filter(fn (Record $registration) => filled($registration->value('_last_certificate_on')) && Carbon::parse($registration->value('_last_certificate_on'))->isCurrentMonth())->sum(fn (Record $registration) => (int) $registration->value('_certificates'))],
                ['label' => 'Fees this month', 'value' => $this->money($thisMonth->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Latest registrations', 'icon' => 'list', 'empty' => 'Nothing registered yet.',
                'rows' => $registrations->sortByDesc('id')->take(10)->map(fn (Record $registration) => [
                    'label' => $registration->title, 'sub' => ucfirst((string) $registration->value('type')).' · '.$registration->occurs_on?->format('d M Y'), 'value' => (string) $registration->value('registration_number'), 'href' => $registration->url(), 'tone' => $registration->value('_late') ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $registrations = $this->dated('registrations', $from, $to)->get();
        $types = $this->app->entities['registrations']->field('type')?->options ?? [];
        $byType = collect($types)->map(function (string $label, string $type) use ($registrations) {
            $group = $registrations->where('data.type', $type);

            return [$label, $group->count(), $group->filter(fn (Record $registration) => $registration->value('_late'))->count(), $group->sum(fn (Record $registration) => (int) $registration->value('_certificates')), $this->money($group->sum('amount'))];
        })->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($registrations) {
            $group = $registrations->filter(fn (Record $registration) => $registration->occurs_on?->format('Y-m') === $month);

            return [$label, $group->where('data.type', 'birth')->count(), $group->where('data.type', 'death')->count(), $group->where('data.type', 'marriage')->count(), $group->where('data.type', 'divorce')->count()];
        })->values()->all();

        $byPlace = $registrations->groupBy(fn (Record $registration) => $registration->value('place') ?: 'Unknown')->sortKeys()->map(fn ($group, $place) => [$place, $group->where('data.type', 'birth')->count(), $group->where('data.type', 'death')->count(), $group->where('data.type', 'marriage')->count()])->values()->all();

        return [
            ['title' => 'Registrations by type', 'columns' => ['Type', 'Registered', 'Late', 'Certificates', 'Fees'], 'rows' => $byType],
            ['title' => 'Vital statistics by month', 'columns' => ['Month', 'Births', 'Deaths', 'Marriages', 'Divorces'], 'rows' => $byMonth],
            ['title' => 'Registrations by place', 'columns' => ['Place', 'Births', 'Deaths', 'Marriages'], 'rows' => $byPlace],
        ];
    }
}
