<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Mosque & madrasa: contributions carry an amount and a type, are receipted once with a numbered
 * receipt, and zakat is kept apart from sadaqah and the other funds. Madrasa students carry a
 * monthly fee and their hifz progress, and the home page shows this month's giving, this year's
 * zakat and the madrasa roll.
 */
class MosqueLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'contributions') {
            if ((float) ($payload['amount'] ?? 0) <= 0) {
                $errors['amount'] = 'Enter the amount contributed.';
            }
            if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
                $errors['occurs_on'] = 'A contribution is recorded on or after the day it was received.';
            }
        } elseif ($entity->key === 'students') {
            if ((float) ($payload['amount'] ?? 0) < 0) {
                $errors['amount'] = 'The monthly fee cannot be negative.';
            }
            if ($payload['status'] === 'completed' && blank($data['hifz_progress'] ?? null)) {
                $errors['data.hifz_progress'] = 'Record what the student completed.';
            }
        } elseif (filled($data['household_size'] ?? null) && (int) $data['household_size'] < 1) {
            $errors['data.household_size'] = 'A household has at least one person.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'contributions') {
            $record->occurs_on ??= today();
            $this->put($record, [
                '_receipt_number' => $record->status === 'receipted' ? ($record->value('_receipt_number') ?? $this->nextReceiptNumber()) : null,
                '_receipted_on' => $record->status === 'receipted' ? ($record->value('_receipted_on') ?? today()->toDateString()) : null,
            ]);
        } elseif ($record->entity === 'students') {
            $this->put($record, [
                '_annual_fee' => round((float) $record->amount * 12, 2),
                '_left_on' => in_array($record->status, ['completed', 'left'], true) ? ($record->value('_left_on') ?? today()->toDateString()) : null,
            ]);
        }
    }

    protected function nextReceiptNumber(): string
    {
        $last = $this->records('contributions')->where('status', 'receipted')->get()->max(fn (Record $contribution) => (int) substr((string) $contribution->value('_receipt_number'), 4));

        return 'RCT-'.str_pad((string) ($last + 1), 6, '0', STR_PAD_LEFT);
    }

    public function actions(Record $record): array
    {
        return match ($record->entity) {
            'contributions' => $record->status === 'received' ? ['receipt' => ['label' => 'Issue receipt', 'icon' => 'receipt']] : [],
            'students' => $record->status === 'enrolled'
                ? [
                    'update_progress' => ['label' => 'Update hifz progress', 'icon' => 'book-open', 'fields' => [['name' => 'hifz_progress', 'label' => 'Progress', 'type' => 'text', 'value' => $record->value('hifz_progress')]]],
                    'complete' => ['label' => 'Completed', 'icon' => 'graduation-cap', 'fields' => [['name' => 'hifz_progress', 'label' => 'Completed', 'type' => 'text', 'value' => $record->value('hifz_progress')]]],
                    'leave' => ['label' => 'Left', 'icon' => 'log-out', 'confirm' => 'Mark '.$record->title.' as left?'],
                ]
                : ['re_enrol' => ['label' => 'Re-enrol', 'icon' => 'user-plus']],
            default => $record->status === 'active'
                ? ['moved' => ['label' => 'Moved away', 'icon' => 'map-pin-off'], 'deceased' => ['label' => 'Deceased', 'icon' => 'moon-star', 'confirm' => 'Record '.$record->title.' as deceased?']]
                : ['reactivate' => ['label' => 'Active again', 'icon' => 'user-check']],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'receipt':
                $record->update(['status' => 'receipted']);

                return 'Receipt '.$record->fresh()->value('_receipt_number').' issued to '.$record->title.'.';
            case 'update_progress':
                $progress = $request->validate(['hifz_progress' => ['required', 'string']])['hifz_progress'];
                $record->update(['data' => [...$record->data, 'hifz_progress' => $progress]]);

                return $record->title.': '.$progress.'.';
            case 'complete':
                $progress = $request->validate(['hifz_progress' => ['required', 'string']])['hifz_progress'];
                $record->update(['status' => 'completed', 'data' => [...$record->data, 'hifz_progress' => $progress]]);

                return $record->title.' completed the madrasa ('.$progress.').';
            case 'leave':
                $record->update(['status' => 'left']);

                return $record->title.' has left the madrasa.';
            case 're_enrol':
                $record->update(['status' => 'enrolled']);

                return $record->title.' is enrolled again.';
            case 'moved':
                $record->update(['status' => 'moved']);

                return $record->title.' has moved away.';
            case 'deceased':
                $record->update(['status' => 'deceased']);

                return $record->title.' recorded as deceased.';
        }

        $record->update(['status' => 'active']);

        return $record->title.' is active again.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'contributions') {
            return [];
        }

        if ($record->entity === 'students') {
            return [
                ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Madrasa', 'icon' => 'book-open', 'stats' => [
                    ['label' => 'Class', 'value' => $record->value('class') ?: '—'],
                    ['label' => 'Guardian', 'value' => $record->value('guardian') ?: '—'],
                    ['label' => 'Monthly fee', 'value' => $this->money($record->amount)],
                    ['label' => 'A year', 'value' => $this->money($this->number($record, '_annual_fee'))],
                    ['label' => 'Hifz progress', 'value' => $record->value('hifz_progress') ?: 'Not recorded'],
                ]]],
            ];
        }

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Congregant', 'icon' => 'users', 'stats' => [
                ['label' => 'Household', 'value' => (int) ($record->value('household_size') ?: 1).' people'],
                ['label' => 'Phone', 'value' => $record->value('phone') ?: '—'],
                ['label' => 'Status', 'value' => ucfirst($record->status)],
            ]]],
        ];
    }

    public function homeCards(): array
    {
        $contributions = $this->records('contributions')->get();
        $students = $this->records('students')->get();
        $members = $this->records('members')->get();
        $month = $contributions->filter(fn (Record $contribution) => $contribution->occurs_on?->isCurrentMonth());
        $year = $contributions->filter(fn (Record $contribution) => $contribution->occurs_on?->isCurrentYear());
        $types = $this->app->entities['contributions']->field('type')?->options ?? [];
        $unreceipted = $contributions->where('status', 'received');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Mosque', 'icon' => 'moon-star', 'stats' => [
                ['label' => 'Congregants', 'value' => (string) $members->where('status', 'active')->count()],
                ['label' => 'Households', 'value' => (string) $members->where('status', 'active')->sum(fn (Record $member) => (int) ($member->value('household_size') ?: 1))],
                ['label' => 'This month', 'value' => $this->money($month->sum('amount'))],
                ['label' => 'Zakat this year', 'value' => $this->money($year->where('data.type', 'zakat')->sum('amount'))],
                ['label' => 'Madrasa students', 'value' => (string) $students->where('status', 'enrolled')->count()],
                ['label' => 'Madrasa fees a month', 'value' => $this->money($students->where('status', 'enrolled')->sum('amount'))],
                ['label' => 'Receipts to issue', 'value' => (string) $unreceipted->count(), 'tone' => $unreceipted->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Giving this month', 'icon' => 'hand-coins', 'empty' => 'Nothing received this month yet.',
                'rows' => collect($types)->map(fn (string $label, string $type) => [
                    'label' => $label, 'sub' => $month->where('data.type', $type)->count().' contributions', 'value' => $this->money($month->where('data.type', $type)->sum('amount')),
                ])->filter(fn (array $row) => ! str_starts_with($row['sub'], '0 '))->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $contributions = $this->dated('contributions', $from, $to)->get();
        $total = $contributions->sum('amount');
        $types = $this->app->entities['contributions']->field('type')?->options ?? [];
        $methods = $this->app->entities['contributions']->field('method')?->options ?? [];
        $byType = collect($types)->map(fn (string $label, string $type) => [
            $label, $contributions->where('data.type', $type)->count(), $this->money($contributions->where('data.type', $type)->sum('amount')), $total > 0 ? round($contributions->where('data.type', $type)->sum('amount') / $total * 100).'%' : '0%',
        ])->values()->all();
        $byMethod = collect($methods)->map(fn (string $label, string $method) => [
            $label, $contributions->where('data.method', $method)->count(), $this->money($contributions->where('data.method', $method)->sum('amount')),
        ])->values()->all();
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($contributions) {
            $group = $contributions->filter(fn (Record $contribution) => $contribution->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $this->money($group->where('data.type', 'zakat')->sum('amount')), $this->money($group->sum('amount'))];
        })->values()->all();

        $students = $this->records('students')->get();
        $byClass = $students->groupBy(fn (Record $student) => $student->value('class') ?: 'No class')->sortKeys()->map(fn ($group, $class) => [
            $class, $group->where('status', 'enrolled')->count(), $group->where('status', 'completed')->count(), $group->where('status', 'left')->count(), $this->money($group->where('status', 'enrolled')->sum('amount')),
        ])->values()->all();

        return [
            ['title' => 'Contributions by type', 'columns' => ['Type', 'Contributions', 'Amount', 'Share'], 'rows' => $byType],
            ['title' => 'Contributions by method', 'columns' => ['Method', 'Contributions', 'Amount'], 'rows' => $byMethod],
            ['title' => 'Contributions by month', 'columns' => ['Month', 'Contributions', 'Zakat', 'Total'], 'rows' => $byMonth],
            ['title' => 'Madrasa by class', 'columns' => ['Class', 'Enrolled', 'Completed', 'Left', 'Fees a month'], 'rows' => $byClass],
        ];
    }
}
