<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Hospital: a ward counts its occupied beds from the patients admitted to it, and a patient is only
 * admitted to an open ward with a free bed that nobody else is lying in. Patients move between
 * wards with a transfer, leave with a discharge summary, and their length of stay is kept. Theatre
 * is booked only for admitted patients, and a surgeon is never booked twice at the same time.
 */
class HospitalLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'wards') {
            if ($existing && (int) ($data['beds'] ?? 0) < $this->inWard($existing)->count()) {
                $errors['data.beds'] = $this->inWard($existing)->count().' patients are in this ward.';
            }
            if ($existing && $payload['status'] === 'closed' && $this->inWard($existing)->isNotEmpty()) {
                $errors['status'] = 'Move the patients out before closing the ward.';
            }

            return $errors;
        }

        if ($entity->key === 'admissions') {
            $ward = filled($data['ward'] ?? null) ? $this->records('wards')->find($data['ward']) : null;
            $moving = ! $existing || (int) $existing->value('ward') !== (int) ($data['ward'] ?? 0) || $existing->status !== 'admitted';
            if ($ward && $payload['status'] === 'admitted' && $moving) {
                $errors = [...$errors, ...$this->bedErrors($ward, $data['bed'] ?? null, $existing?->id)];
            } elseif ($ward && $payload['status'] === 'admitted' && filled($data['bed'] ?? null) && $this->bedTaken($ward, (string) $data['bed'], $existing?->id)) {
                $errors['data.bed'] = 'Bed '.$data['bed'].' is taken by '.$this->bedTaken($ward, (string) $data['bed'], $existing?->id)->title.'.';
            }
            if ($payload['status'] === 'discharged' && blank($data['discharge_summary'] ?? null)) {
                $errors['data.discharge_summary'] = 'Write the discharge summary.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The expected discharge cannot be before admission.';
            }

            return $errors;
        }

        $admission = filled($data['admission'] ?? null) ? $this->records('admissions')->find($data['admission']) : null;
        if ($admission && $admission->status !== 'admitted' && in_array($payload['status'], ['scheduled', 'in_theatre'], true)) {
            $errors['data.admission'] = $admission->title.' is not in hospital.';
        }
        if ($admission && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->lt($admission->occurs_on)) {
            $errors['occurs_on'] = 'The operation cannot be before the admission.';
        }
        if ($payload['status'] === 'scheduled' && filled($payload['occurs_on'] ?? null) && filled($data['start_time'] ?? null) && filled($data['surgeon'] ?? null)) {
            $clash = $this->records('theatre')->whereDate('occurs_on', $payload['occurs_on'])->whereIn('status', ['scheduled', 'in_theatre'])
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $booking) => (int) $booking->value('surgeon') === (int) $data['surgeon'] && substr((string) $booking->value('start_time'), 0, 5) === substr((string) $data['start_time'], 0, 5));
            if ($clash) {
                $errors['data.start_time'] = 'The surgeon is already operating ('.$clash->title.') at '.substr((string) $data['start_time'], 0, 5).'.';
            }
        }

        return $errors;
    }

    /**
     * Why a patient cannot go into this ward and bed, if they cannot.
     *
     * @return array<string, string>
     */
    protected function bedErrors(Record $ward, ?string $bed, ?int $except = null): array
    {
        if ($ward->status !== 'open') {
            return ['data.ward' => $ward->title.' is closed.'];
        }
        if ($this->inWard($ward)->reject(fn (Record $admission) => $admission->id === $except)->count() >= (int) $ward->value('beds')) {
            return ['data.ward' => $ward->title.' is full.'];
        }
        if (filled($bed) && $taken = $this->bedTaken($ward, (string) $bed, $except)) {
            return ['data.bed' => 'Bed '.$bed.' is taken by '.$taken->title.'.'];
        }

        return [];
    }

    /**
     * The patient lying in a ward's bed, if any.
     */
    protected function bedTaken(Record $ward, string $bed, ?int $except = null): ?Record
    {
        return $this->inWard($ward)->first(fn (Record $admission) => $admission->id !== $except && strcasecmp(trim((string) $admission->value('bed')), trim($bed)) === 0);
    }

    /**
     * Patients currently admitted to a ward.
     *
     * @return Collection<int, Record>
     */
    protected function inWard(Record $ward): Collection
    {
        return $this->linked('admissions', 'ward', $ward)->where('status', 'admitted')->get();
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'wards') {
            $beds = (int) $record->value('beds');
            $occupied = $record->exists ? $this->inWard($record)->count() : 0;
            $this->put($record, ['beds' => $beds, 'occupied' => $occupied, '_free' => max(0, $beds - $occupied), '_occupancy' => $beds > 0 ? (int) round($occupied / $beds * 100) : 0]);

            return;
        }

        $record->occurs_on ??= today();
        if ($record->entity === 'admissions') {
            $left = $record->status === 'admitted' ? null : ($record->value('_left_on') ?? today()->toDateString());
            $this->put($record, [
                '_left_on' => $left,
                '_stay_days' => max(1, (int) $record->occurs_on->diffInDays($left ? Carbon::parse($left) : today())),
                '_overstay' => $record->status === 'admitted' && $record->due_on?->lt(today()),
            ]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'admissions') {
            $this->recount($this->parent($record, 'ward'));
            $this->recount($this->previousParent($record, 'ward'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'admissions') {
            $this->recount($this->parent($record, 'ward'));
        }
    }

    /**
     * Refresh a ward's occupied-bed count.
     */
    protected function recount(?Record $ward): void
    {
        if ($ward && (int) $ward->value('occupied') !== $this->inWard($ward)->count()) {
            $ward->save();
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'admissions' && $record->status === 'admitted') {
            $wards = $this->records('wards')->where('status', 'open')->orderBy('title')->pluck('title', 'id')->all();

            return [
                'transfer' => ['label' => 'Move ward', 'icon' => 'arrow-right-left', 'fields' => [
                    ['name' => 'ward', 'label' => 'Ward', 'type' => 'select', 'options' => $wards],
                    ['name' => 'bed', 'label' => 'Bed', 'type' => 'text'],
                ]],
                'discharge' => ['label' => 'Discharge', 'icon' => 'log-out', 'fields' => [['name' => 'discharge_summary', 'label' => 'Discharge summary', 'type' => 'textarea']]],
                'transfer_out' => ['label' => 'Transfer to another facility', 'icon' => 'ambulance', 'fields' => [['name' => 'facility', 'label' => 'Facility', 'type' => 'text']]],
                'deceased' => ['label' => 'Record death', 'icon' => 'heart-off'],
            ];
        }
        if ($record->entity === 'theatre') {
            return match ($record->status) {
                'scheduled' => ['start' => ['label' => 'Into theatre', 'icon' => 'scissors'], 'postpone' => ['label' => 'Postpone', 'icon' => 'calendar-x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text'], ['name' => 'date', 'label' => 'New date (optional)', 'type' => 'date']]]],
                'in_theatre' => ['complete' => ['label' => 'Completed', 'icon' => 'check']],
                default => [],
            };
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'theatre') {
            return $this->theatreAction($action, $record, $request);
        }

        switch ($action) {
            case 'transfer':
                $input = $request->validate(['ward' => ['required', 'integer'], 'bed' => ['nullable', 'string']]);
                $ward = $this->records('wards')->find($input['ward']);
                if (! $ward || (int) $input['ward'] === (int) $record->value('ward')) {
                    throw ValidationException::withMessages(['ward' => 'Choose a different ward.']);
                }
                $errors = $this->bedErrors($ward, $input['bed'] ?? null, $record->id);
                if ($errors) {
                    throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn (string $message, string $key) => [str_replace('data.', '', $key) => $message])->all());
                }
                $from = $this->parent($record, 'ward')?->title;
                $record->update(['data' => [...$record->data, 'ward' => $ward->id, 'bed' => $input['bed'] ?? null, '_transfers' => [...(array) ($record->value('_transfers') ?? []), ['on' => today()->toDateString(), 'from' => $from, 'to' => $ward->title]]]]);

                return $record->title.' moved from '.$from.' to '.$ward->title.'.';
            case 'discharge':
                $summary = $request->validate(['discharge_summary' => ['required', 'string']])['discharge_summary'];
                $record->update(['status' => 'discharged', 'data' => [...$record->data, 'discharge_summary' => $summary]]);

                $days = (int) $record->fresh()->value('_stay_days');

                return $record->title.' discharged after '.$days.' '.Str::plural('day', $days).'.';
            case 'transfer_out':
                $facility = $request->validate(['facility' => ['required', 'string']])['facility'];
                $record->update(['status' => 'transferred', 'data' => [...$record->data, '_transferred_to' => $facility]]);

                return $record->title.' transferred to '.$facility.'.';
        }

        $record->update(['status' => 'deceased']);
        $this->linked('theatre', 'admission', $record)->where('status', 'scheduled')->get()->each(fn (Record $booking) => $booking->update(['status' => 'postponed', 'data' => [...$booking->data, '_postponed_reason' => 'Patient deceased']]));

        return $record->title.' recorded as deceased.';
    }

    /**
     * Move a theatre booking along.
     */
    protected function theatreAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'postpone') {
            $input = $request->validate(['reason' => ['required', 'string'], 'date' => ['nullable', 'date', 'after_or_equal:today']]);
            if (filled($input['date'] ?? null)) {
                $record->update(['occurs_on' => $input['date'], 'data' => [...$record->data, '_postponements' => (int) $record->value('_postponements') + 1, '_postponed_reason' => $input['reason']]]);

                return $record->title.' moved to '.Carbon::parse($input['date'])->format('d M Y').'.';
            }
            $record->update(['status' => 'postponed', 'data' => [...$record->data, '_postponements' => (int) $record->value('_postponements') + 1, '_postponed_reason' => $input['reason']]]);

            return $record->title.' postponed.';
        }

        if ($action === 'start') {
            if ($this->parent($record, 'admission')?->status !== 'admitted') {
                throw ValidationException::withMessages(['status' => 'The patient is no longer in hospital.']);
            }
            if (! $record->occurs_on?->isToday()) {
                throw ValidationException::withMessages(['status' => 'This operation is booked for '.$record->occurs_on?->format('d M Y').'.']);
            }
            $record->update(['status' => 'in_theatre', 'data' => [...$record->data, '_in_at' => now()->toDateTimeString()]]);

            return $record->title.' started.';
        }

        $minutes = filled($record->value('_in_at')) ? (int) Carbon::parse($record->value('_in_at'))->diffInMinutes(now()) : null;
        $record->update(['status' => 'completed', 'data' => [...$record->data, '_out_at' => now()->toDateTimeString(), '_minutes' => $minutes]]);

        return $record->title.' completed.';
    }

    public function homeCards(): array
    {
        $wards = $this->records('wards')->orderBy('title')->get();
        $theatre = $this->records('theatre')->whereDate('occurs_on', today())->whereIn('status', ['scheduled', 'in_theatre'])->get()->sortBy(fn (Record $booking) => $booking->value('start_time'));
        $surgeons = User::query()->whereIn('id', $theatre->map(fn (Record $booking) => $booking->value('surgeon'))->filter()->unique())->pluck('name', 'id');
        $admitted = $this->records('admissions')->where('status', 'admitted')->get();
        $beds = $wards->where('status', 'open')->sum(fn (Record $ward) => (int) $ward->value('beds'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Hospital', 'icon' => 'hospital', 'stats' => [
                ['label' => 'Inpatients', 'value' => (string) $admitted->count()],
                ['label' => 'Free beds', 'value' => (string) max(0, $beds - $admitted->count())],
                ['label' => 'Occupancy', 'value' => $beds > 0 ? (int) round($admitted->count() / $beds * 100).'%' : '—'],
                ['label' => 'Admitted today', 'value' => (string) $this->records('admissions')->whereDate('occurs_on', today())->count()],
                ['label' => 'Past expected discharge', 'value' => (string) $admitted->filter(fn (Record $admission) => $admission->value('_overstay'))->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Beds by ward', 'icon' => 'bed-double', 'empty' => 'No wards yet.',
                'rows' => $wards->map(fn (Record $ward) => [
                    'label' => $ward->title, 'sub' => ucfirst((string) $ward->value('type')).($ward->status === 'closed' ? ' · closed' : ''), 'value' => $ward->value('occupied').' / '.$ward->value('beds'), 'href' => $ward->url(), 'tone' => $ward->status === 'open' && (int) $ward->value('_free') === 0 ? 'danger' : null,
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Theatre today', 'icon' => 'scissors', 'empty' => 'No operations today.',
                'rows' => $theatre->map(fn (Record $booking) => [
                    'label' => substr((string) $booking->value('start_time'), 0, 5).' · '.$booking->title, 'sub' => (string) ($surgeons[$booking->value('surgeon')] ?? ''), 'value' => $booking->status === 'in_theatre' ? 'In theatre' : (string) $booking->value('theatre_number'), 'href' => $booking->url(), 'tone' => $booking->status === 'in_theatre' ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $admissions = $this->dated('admissions', $from, $to)->get();
        $wards = $this->records('wards')->orderBy('title')->get();
        $byWard = $wards->map(function (Record $ward) use ($admissions) {
            $group = $admissions->filter(fn (Record $admission) => (int) $admission->value('ward') === $ward->id);
            $left = $group->where('status', '!=', 'admitted');

            return [$ward->title, (int) $ward->value('beds'), (int) $ward->value('occupied'), $group->count(), $left->isEmpty() ? '—' : round($left->avg(fn (Record $admission) => (int) $admission->value('_stay_days')), 1), $this->money($group->sum('amount'))];
        })->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($admissions) {
            $group = $admissions->filter(fn (Record $admission) => $admission->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->where('status', 'discharged')->count(), $group->where('status', 'transferred')->count(), $group->where('status', 'deceased')->count()];
        })->values()->all();

        $theatre = $this->dated('theatre', $from, $to)->get();
        $surgeons = User::query()->whereIn('id', $theatre->map(fn (Record $booking) => $booking->value('surgeon'))->filter()->unique())->pluck('name', 'id');
        $bySurgeon = $theatre->groupBy(fn (Record $booking) => $surgeons[$booking->value('surgeon')] ?? 'Unknown')->sortKeys()->map(function (Collection $group, string $surgeon) {
            $done = $group->where('status', 'completed')->filter(fn (Record $booking) => $booking->value('_minutes') !== null);

            return [$surgeon, $group->count(), $group->where('status', 'completed')->count(), $group->filter(fn (Record $booking) => (int) $booking->value('_postponements') > 0 || $booking->status === 'postponed')->count(), $done->isEmpty() ? '—' : (int) round($done->avg(fn (Record $booking) => (int) $booking->value('_minutes'))).' min'];
        })->values()->all();

        return [
            ['title' => 'Wards', 'columns' => ['Ward', 'Beds', 'Occupied now', 'Admissions', 'Average stay (days)', 'Billed'], 'rows' => $byWard],
            ['title' => 'Admissions by month', 'columns' => ['Month', 'Admitted', 'Discharged', 'Transferred out', 'Deaths'], 'rows' => $byMonth],
            ['title' => 'Theatre by surgeon', 'columns' => ['Surgeon', 'Booked', 'Completed', 'Postponed', 'Average time'], 'rows' => $bySurgeon],
        ];
    }
}
