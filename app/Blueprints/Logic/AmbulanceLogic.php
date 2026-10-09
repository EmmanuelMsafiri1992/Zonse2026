<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Ambulance dispatch: an emergency call is dispatched to one ambulance at a time, and an ambulance
 * already on a call cannot be sent to another. Every stage keeps its time, so the response time from
 * call to scene is measured against the target for its priority. The receiving hospital is named on
 * arrival and the call only closes with a patient care report.
 */
class AmbulanceLogic extends AppLogic
{
    /**
     * Minutes from call to scene allowed for each priority.
     */
    protected const TARGETS = ['p1_critical' => 15, 'p2_urgent' => 30, 'p3_routine' => 60];

    /**
     * Each stage in order with the time it stamps.
     */
    protected const STAGES = ['received' => '_received_at', 'dispatched' => '_dispatched_at', 'on_scene' => '_on_scene_at', 'transporting' => '_transporting_at', 'at_hospital' => '_at_hospital_at', 'closed' => '_closed_at'];

    /**
     * Statuses in which the ambulance is busy with the call.
     */
    protected const ACTIVE = ['dispatched', 'on_scene', 'transporting', 'at_hospital'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if (in_array($payload['status'], self::ACTIVE, true) && blank($data['ambulance'] ?? null)) {
            $errors['data.ambulance'] = 'Choose the ambulance.';
        }
        if (in_array($payload['status'], self::ACTIVE, true) && filled($data['ambulance'] ?? null) && $busy = $this->busy((string) $data['ambulance'], $existing?->id)) {
            $errors['data.ambulance'] = strtoupper(trim((string) $data['ambulance'])).' is on '.$busy->title.'.';
        }
        $transported = $payload['status'] === 'at_hospital' || $payload['status'] === 'closed' && filled($existing?->value('_transporting_at'));
        if ($transported && blank($data['hospital'] ?? null)) {
            $errors['data.hospital'] = 'Name the hospital the patient was taken to.';
        }
        if ($payload['status'] === 'closed' && blank($data['patient_report'] ?? null)) {
            $errors['data.patient_report'] = 'Complete the patient care report.';
        }
        if ($existing && $this->stage($payload['status']) < $this->stage($existing->status)) {
            $errors['status'] = 'A call cannot go back a stage.';
        }

        return $errors;
    }

    /**
     * Where a status sits in the call's progress.
     */
    protected function stage(string $status): int
    {
        return (int) array_search($status, array_keys(self::STAGES), true);
    }

    /**
     * The open call an ambulance is already on, if any.
     */
    protected function busy(string $ambulance, ?int $except = null): ?Record
    {
        return $this->records('incidents')->whereIn('status', self::ACTIVE)->when($except, fn ($query) => $query->whereKeyNot($except))->get()
            ->first(fn (Record $incident) => strcasecmp(trim((string) $incident->value('ambulance')), trim($ambulance)) === 0);
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        $now = now()->toDateTimeString();
        $values = ['ambulance' => filled($record->value('ambulance')) ? strtoupper(trim((string) $record->value('ambulance'))) : null];
        foreach (self::STAGES as $status => $key) {
            if ($this->stage($status) <= $this->stage($record->status)) {
                $values[$key] = $record->value($key) ?? $now;
            }
        }
        if (isset($values['_on_scene_at'])) {
            $minutes = (int) Carbon::parse($values['_received_at'])->diffInMinutes(Carbon::parse($values['_on_scene_at']));
            $values['_response_minutes'] = $minutes;
            $values['_within_target'] = $minutes <= (self::TARGETS[$record->value('priority')] ?? 60);
        }
        if (isset($values['_closed_at'])) {
            $values['_call_minutes'] = (int) Carbon::parse($values['_received_at'])->diffInMinutes(Carbon::parse($values['_closed_at']));
        }
        $this->put($record, $values);
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'received' => ['dispatch' => ['label' => 'Dispatch', 'icon' => 'siren', 'fields' => [['name' => 'ambulance', 'label' => 'Ambulance', 'type' => 'text']]]],
            'dispatched' => ['arrive' => ['label' => 'On scene', 'icon' => 'map-pin']],
            'on_scene' => [
                'transport' => ['label' => 'Transporting', 'icon' => 'ambulance', 'fields' => [['name' => 'hospital', 'label' => 'Taking to', 'type' => 'text']]],
                'close' => ['label' => 'Treated on scene — close', 'icon' => 'check', 'fields' => [['name' => 'patient_report', 'label' => 'Patient care report', 'type' => 'textarea']]],
            ],
            'transporting' => ['handover' => ['label' => 'At hospital', 'icon' => 'hospital']],
            'at_hospital' => ['close' => ['label' => 'Close call', 'icon' => 'check', 'fields' => [['name' => 'patient_report', 'label' => 'Patient care report', 'type' => 'textarea']]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'dispatch':
                $ambulance = strtoupper(trim($request->validate(['ambulance' => ['required', 'string']])['ambulance']));
                if ($busy = $this->busy($ambulance, $record->id)) {
                    throw ValidationException::withMessages(['ambulance' => $ambulance.' is on '.$busy->title.'.']);
                }
                $record->update(['status' => 'dispatched', 'data' => [...$record->data, 'ambulance' => $ambulance]]);

                return $ambulance.' dispatched to '.$record->title.'.';
            case 'arrive':
                $record->update(['status' => 'on_scene']);
                $record->refresh();

                return $record->value('ambulance').' on scene after '.$record->value('_response_minutes').' min'.($record->value('_within_target') ? '.' : ' — over the '.(self::TARGETS[$record->value('priority')] ?? 60).'-minute target.');
            case 'transport':
                $hospital = $request->validate(['hospital' => ['required', 'string']])['hospital'];
                $record->update(['status' => 'transporting', 'data' => [...$record->data, 'hospital' => $hospital]]);

                return 'Patient on the way to '.$hospital.'.';
            case 'handover':
                $record->update(['status' => 'at_hospital']);

                return 'Patient handed over at '.$record->value('hospital').'; '.$record->value('ambulance').' is free once the call is closed.';
        }

        $report = $request->validate(['patient_report' => ['required', 'string']])['patient_report'];
        $record->update(['status' => 'closed', 'data' => [...$record->data, 'patient_report' => $report]]);

        return $record->title.' closed.';
    }

    public function recordCards(Record $record): array
    {
        $at = fn (string $key) => filled($record->value($key)) ? Carbon::parse($record->value($key))->format('H:i') : '—';

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Timeline', 'icon' => 'clock', 'stats' => [
                ['label' => 'Call', 'value' => $at('_received_at')],
                ['label' => 'Dispatched', 'value' => $at('_dispatched_at')],
                ['label' => 'On scene', 'value' => $at('_on_scene_at')],
                ['label' => 'At hospital', 'value' => $at('_at_hospital_at')],
                ['label' => 'Response', 'value' => $record->value('_response_minutes') !== null ? $record->value('_response_minutes').' min' : '—', 'tone' => $record->value('_response_minutes') !== null && ! $record->value('_within_target') ? 'danger' : null],
            ]]],
        ];
    }

    public function homeCards(): array
    {
        $open = $this->records('incidents')->where('status', '!=', 'closed')->get()
            ->sortBy(fn (Record $incident) => array_search($incident->value('priority'), array_keys(self::TARGETS), true).$incident->value('_received_at'));
        $today = $this->records('incidents')->whereDate('occurs_on', today())->get()->filter(fn (Record $incident) => $incident->value('_response_minutes') !== null);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Dispatch', 'icon' => 'siren', 'stats' => [
                ['label' => 'Waiting for an ambulance', 'value' => (string) $open->where('status', 'received')->count()],
                ['label' => 'Ambulances out', 'value' => (string) $open->whereIn('status', self::ACTIVE)->count()],
                ['label' => 'Calls today', 'value' => (string) $this->records('incidents')->whereDate('occurs_on', today())->count()],
                ['label' => 'Within target today', 'value' => $today->isEmpty() ? '—' : (int) round($today->filter(fn (Record $incident) => $incident->value('_within_target'))->count() / $today->count() * 100).'%'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Open calls', 'icon' => 'radio', 'empty' => 'No open calls.',
                'rows' => $open->map(fn (Record $incident) => [
                    'label' => strtoupper(substr((string) $incident->value('priority'), 0, 2)).' · '.$incident->title, 'sub' => trim((string) $incident->value('ambulance').' '.str($incident->value('location'))->limit(40)), 'value' => ucfirst(str_replace('_', ' ', $incident->status)), 'href' => $incident->url(), 'tone' => $incident->status === 'received' ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $incidents = $this->dated('incidents', $from, $to)->get();
        $byPriority = collect(self::TARGETS)->map(function (int $target, string $priority) use ($incidents) {
            $group = $incidents->filter(fn (Record $incident) => $incident->value('priority') === $priority);
            $reached = $group->filter(fn (Record $incident) => $incident->value('_response_minutes') !== null);

            return [ucfirst(str_replace('_', ' ', $priority)), $target.' min', $group->count(), $reached->isEmpty() ? '—' : round($reached->avg(fn (Record $incident) => (int) $incident->value('_response_minutes')), 1).' min', $reached->isEmpty() ? '—' : (int) round($reached->filter(fn (Record $incident) => $incident->value('_within_target'))->count() / $reached->count() * 100).'%'];
        })->values()->all();

        $byAmbulance = $incidents->filter(fn (Record $incident) => filled($incident->value('ambulance')))->groupBy(fn (Record $incident) => (string) $incident->value('ambulance'))->sortKeys()
            ->map(fn (Collection $group, string $ambulance) => [$ambulance, $group->count(), $group->filter(fn (Record $incident) => $incident->value('_call_minutes') !== null)->avg(fn (Record $incident) => (int) $incident->value('_call_minutes')) !== null ? (int) round($group->filter(fn (Record $incident) => $incident->value('_call_minutes') !== null)->avg(fn (Record $incident) => (int) $incident->value('_call_minutes'))).' min' : '—'])->values()->all();

        $byHospital = $incidents->filter(fn (Record $incident) => filled($incident->value('hospital')))->groupBy(fn (Record $incident) => (string) $incident->value('hospital'))->sortKeys()
            ->map(fn (Collection $group, string $hospital) => [$hospital, $group->count()])->values()->all();

        return [
            ['title' => 'Response times by priority', 'columns' => ['Priority', 'Target', 'Calls', 'Average response', 'Within target'], 'rows' => $byPriority],
            ['title' => 'Calls by ambulance', 'columns' => ['Ambulance', 'Calls', 'Average call length'], 'rows' => $byAmbulance],
            ['title' => 'Patients by hospital', 'columns' => ['Hospital', 'Patients'], 'rows' => $byHospital],
        ];
    }
}
