<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Nursery & daycare: children are placed in a room by age, allergies are always in view, and an
 * incident is closed only after the parent has been told what happened and what was done.
 */
class DaycareLogic extends AppLogic
{
    /** Room => the age in months a child must be under to be placed there. */
    public const ROOMS = ['babies' => 18, 'toddlers' => 36, 'preschool' => 72];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'children') {
            $dob = filled($data['date_of_birth'] ?? null) ? Carbon::parse($data['date_of_birth']) : null;
            if ($dob?->isFuture()) {
                $errors['data.date_of_birth'] = 'The date of birth cannot be in the future.';
            } elseif ($dob && $dob->diffInMonths(today()) >= self::ROOMS['preschool']) {
                $errors['data.date_of_birth'] = 'The nursery takes children under six.';
            }
            if ($payload['status'] === 'enrolled' && blank($data['guardian_phone'] ?? null)) {
                $errors['data.guardian_phone'] = 'An enrolled child needs a guardian phone number.';
            }

            return $errors;
        }

        $child = ! empty($data['child']) ? $this->records('children')->find($data['child']) : null;
        if ($child && $child->status !== 'enrolled' && (! $existing || (int) $existing->value('child') !== $child->id)) {
            $errors['data.child'] = $child->title.' is not enrolled.';
        }
        if (in_array($payload['status'], ['parent_informed', 'closed'], true) && blank($data['action_taken'] ?? null)) {
            $errors['data.action_taken'] = 'Say what was done before informing the parent.';
        }
        if ($payload['status'] === 'closed' && ($existing?->status ?? 'open') === 'open') {
            $errors['status'] = 'Inform the parent before closing the incident.';
        }

        return $errors;
    }

    public static function roomFor(?Carbon $dateOfBirth): ?string
    {
        if (! $dateOfBirth) {
            return null;
        }
        $months = (int) $dateOfBirth->diffInMonths(today());
        foreach (self::ROOMS as $room => $under) {
            if ($months < $under) {
                return $room;
            }
        }

        return 'preschool';
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'incidents') {
            $record->occurs_on ??= today();
            $this->put($record, ['_informed_on' => in_array($record->status, ['parent_informed', 'closed'], true) ? ($record->value('_informed_on') ?? today()->toDateString()) : null]);

            return;
        }

        $dob = $record->value('date_of_birth') ? Carbon::parse($record->value('date_of_birth')) : null;
        $this->put($record, [
            'room' => $record->value('room') ?: self::roomFor($dob),
            '_age_months' => $dob ? (int) $dob->diffInMonths(today()) : null,
            '_has_allergies' => filled($record->value('allergies')),
        ]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'children') {
            return $record->status === 'waitlisted' ? ['enrol' => ['label' => 'Enrol', 'icon' => 'user-check']] : [];
        }

        return match ($record->status) {
            'open' => ['inform_parent' => ['label' => 'Parent informed', 'icon' => 'phone', 'fields' => [
                ['name' => 'action_taken', 'label' => 'Action taken', 'type' => 'textarea', 'value' => $record->value('action_taken')],
            ]]],
            'parent_informed' => ['close' => ['label' => 'Close', 'icon' => 'check']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'enrol':
                $record->update(['status' => 'enrolled']);

                return $record->title.' is enrolled in the '.$record->fresh()->value('room').' room.';
            case 'inform_parent':
                $taken = $request->validate(['action_taken' => ['required', 'string']])['action_taken'];
                $record->update(['status' => 'parent_informed', 'data' => [...(array) $record->data, 'action_taken' => $taken]]);

                return 'Parent informed about '.$record->title.'.';
        }
        $record->update(['status' => 'closed']);

        return 'Incident closed.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'children') {
            return [];
        }

        $incidents = $this->linked('incidents', 'child', $record)->orderByDesc('occurs_on')->get();
        $months = $record->value('_age_months');
        $rooms = $this->app->entities['children']->field('room')?->options ?? [];

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Child', 'icon' => 'baby', 'stats' => [
                ['label' => 'Age', 'value' => $months === null ? '—' : (intdiv((int) $months, 12).'y '.((int) $months % 12).'m')],
                ['label' => 'Room', 'value' => $rooms[$record->value('room')] ?? ucfirst((string) $record->value('room')), 'tone' => $record->value('room') !== self::roomFor($record->value('date_of_birth') ? Carbon::parse($record->value('date_of_birth')) : null) ? 'warning' : null],
                ['label' => 'Allergies', 'value' => $record->value('allergies') ?: 'None', 'tone' => $record->value('_has_allergies') ? 'danger' : null],
                ['label' => 'Open incidents', 'value' => (string) $incidents->where('status', '!=', 'closed')->count(), 'tone' => $incidents->where('status', '!=', 'closed')->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Incidents', 'icon' => 'alert-triangle', 'empty' => 'No incidents.',
                'rows' => $incidents->take(10)->map(fn (Record $incident) => [
                    'label' => $incident->title, 'sub' => $incident->occurs_on?->format('d M Y'), 'value' => ucfirst(str_replace('_', ' ', $incident->status)), 'href' => $incident->url(),
                    'tone' => $incident->status === 'open' ? 'danger' : ($incident->status === 'parent_informed' ? 'warning' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $children = $this->records('children')->get();
        $enrolled = $children->where('status', 'enrolled');
        $open = $this->records('incidents')->where('status', '!=', 'closed')->orderBy('occurs_on')->get();
        $names = $children->pluck('title', 'id');
        $misplaced = $enrolled->filter(fn (Record $child) => $child->value('date_of_birth') && $child->value('room') !== self::roomFor(Carbon::parse($child->value('date_of_birth'))));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Nursery', 'icon' => 'baby', 'stats' => [
                ['label' => 'Enrolled', 'value' => (string) $enrolled->count()],
                ['label' => 'Waitlisted', 'value' => (string) $children->where('status', 'waitlisted')->count()],
                ['label' => 'With allergies', 'value' => (string) $enrolled->filter(fn (Record $child) => $child->value('_has_allergies'))->count(), 'tone' => 'warning'],
                ['label' => 'Ready to move room', 'value' => (string) $misplaced->count(), 'tone' => $misplaced->isNotEmpty() ? 'warning' : null],
                ['label' => 'Open incidents', 'value' => (string) $open->count(), 'tone' => $open->isNotEmpty() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Open incidents', 'icon' => 'alert-triangle', 'empty' => 'No open incidents.',
                'rows' => $open->take(10)->map(fn (Record $incident) => [
                    'label' => $incident->title, 'sub' => ($names[(int) $incident->value('child')] ?? '').' · '.$incident->occurs_on?->format('d M'), 'value' => ucfirst(str_replace('_', ' ', $incident->status)), 'href' => $incident->url(),
                    'tone' => $incident->status === 'open' ? 'danger' : 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $children = $this->records('children')->get();
        $rooms = $this->app->entities['children']->field('room')?->options ?? [];
        $byRoom = collect($rooms)->map(fn (string $label, string $key) => [
            $label, $children->where('status', 'enrolled')->where('data.room', $key)->count(), $children->where('status', 'waitlisted')->where('data.room', $key)->count(),
            $children->where('status', 'enrolled')->where('data.room', $key)->filter(fn (Record $child) => $child->value('_has_allergies'))->count(),
        ])->values()->all();

        $incidents = $this->dated('incidents', $from, $to)->get();
        $byMonth = collect($this->months($from, $to))->map(fn (string $label, string $month) => [
            $label, $incidents->filter(fn (Record $incident) => $incident->occurs_on?->format('Y-m') === $month)->count(),
            $incidents->filter(fn (Record $incident) => $incident->occurs_on?->format('Y-m') === $month && $incident->status === 'closed')->count(),
        ])->values()->all();

        return [
            ['title' => 'Children by room', 'columns' => ['Room', 'Enrolled', 'Waitlisted', 'With allergies'], 'rows' => $byRoom],
            ['title' => 'Incidents by month', 'columns' => ['Month', 'Incidents', 'Closed'], 'rows' => $byMonth],
        ];
    }
}
