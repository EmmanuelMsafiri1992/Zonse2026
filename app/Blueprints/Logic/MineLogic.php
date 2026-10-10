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
 * Mine & quarry operations: one shift report per pit and shift each day, with downtime no longer than
 * the 12-hour shift; approved reports are locked. Tonnes per load come from the haul figures. A blast
 * is fired by a licensed blaster with its holes and explosives recorded, and no blast is fired while a
 * misfire is still to be cleared. Ground vibration above 12.5 mm/s is flagged. Stockpiles are surveyed
 * and drawn down, never below zero, and go depleted when empty.
 */
class MineLogic extends AppLogic
{
    /**
     * Minutes in a shift.
     */
    protected const SHIFT_MINUTES = 720;

    /**
     * Peak particle velocity (mm/s) above which a blast is flagged.
     */
    protected const VIBRATION_LIMIT = 12.5;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'shifts') {
            if ($existing && $existing->status === 'approved' && $payload['status'] !== 'approved') {
                $errors['status'] = 'This shift report is approved and locked.';
            }
            foreach (['tonnes_mined', 'tonnes_hauled', 'loads', 'downtime_minutes'] as $field) {
                if ((float) ($data[$field] ?? 0) < 0) {
                    $errors['data.'.$field] = 'This cannot be negative.';
                }
            }
            if ((int) ($data['downtime_minutes'] ?? 0) > self::SHIFT_MINUTES) {
                $errors['data.downtime_minutes'] = 'Downtime cannot be longer than the '.(self::SHIFT_MINUTES / 60).'-hour shift.';
            }
            $date = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today();
            $pit = mb_strtolower(trim((string) ($data['pit'] ?? '')));
            $duplicate = $this->records('shifts')->whereDate('occurs_on', $date)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $shift) => mb_strtolower(trim((string) $shift->value('pit'))) === $pit && $shift->value('shift') === ($data['shift'] ?? null));
            if ($pit !== '' && $duplicate) {
                $errors['data.shift'] = 'There is already a '.$duplicate->value('shift').' shift report for '.$duplicate->value('pit').' on '.$date->format('d M Y').' ('.$duplicate->number.').';
            }

            return $errors;
        }

        if ($entity->key === 'blasts') {
            if ($existing && in_array($existing->status, ['fired', 'cleared'], true) && $payload['status'] === 'planned') {
                $errors['status'] = 'A blast that has been fired cannot be planned again.';
            }
            if ($payload['status'] === 'fired' && (! $existing || $existing->status !== 'fired')) {
                if ((int) ($data['holes'] ?? 0) < 1 || (float) ($data['explosives_kg'] ?? 0) <= 0) {
                    $errors['data.holes'] = 'Record the holes charged and the explosives used.';
                }
                if ($misfire = $this->openMisfire($existing?->id)) {
                    $errors['status'] = 'The misfire at '.$misfire->title.' must be cleared before another blast.';
                }
            }

            return $errors;
        }

        if ((float) ($data['tonnes'] ?? 0) < 0) {
            $errors['data.tonnes'] = 'Tonnes on hand cannot be negative.';
        }

        return $errors;
    }

    /**
     * A misfire still waiting to be cleared.
     */
    protected function openMisfire(?int $except = null): ?Record
    {
        return $this->records('blasts')->where('status', 'misfire')->when($except, fn ($query) => $query->whereKeyNot($except))->first();
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'shifts') {
            $loads = (int) $record->value('loads');
            $this->put($record, [
                '_tonnes_per_load' => $loads > 0 ? round($this->number($record, 'tonnes_hauled') / $loads, 1) : null,
                '_availability' => round((self::SHIFT_MINUTES - min(self::SHIFT_MINUTES, (int) $record->value('downtime_minutes'))) / self::SHIFT_MINUTES * 100, 1),
            ]);

            return;
        }
        if ($record->entity === 'blasts') {
            $holes = (int) $record->value('holes');
            $this->put($record, [
                '_kg_per_hole' => $holes > 0 ? round($this->number($record, 'explosives_kg') / $holes, 1) : null,
                '_over_limit' => $record->value('vibration') !== null && $this->number($record, 'vibration') > self::VIBRATION_LIMIT,
            ]);

            return;
        }
        if ($record->value('tonnes') !== null) {
            $record->status = $this->number($record, 'tonnes') <= 0 ? 'depleted' : 'active';
        }
    }

    public function actions(Record $record): array
    {
        return match ($record->entity) {
            'shifts' => match ($record->status) {
                'open' => ['submit' => ['label' => 'Submit', 'icon' => 'send']],
                'submitted' => ['approve' => ['label' => 'Approve', 'icon' => 'check']],
                default => [],
            },
            'blasts' => match ($record->status) {
                'planned' => ['fire' => ['label' => 'Fired', 'icon' => 'flame', 'fields' => [
                    ['name' => 'fire_time', 'label' => 'Fire time', 'type' => 'time', 'value' => now()->format('H:i')],
                    ['name' => 'vibration', 'label' => 'Vibration (mm/s)', 'type' => 'number'],
                ]], 'misfire' => ['label' => 'Misfire', 'icon' => 'triangle-alert']],
                'fired' => ['misfire' => ['label' => 'Misfire found', 'icon' => 'triangle-alert']],
                'misfire' => ['clear' => ['label' => 'Misfire cleared', 'icon' => 'shield-check']],
                default => [],
            },
            default => $record->status === 'active' ? [
                'draw' => ['label' => 'Load out', 'icon' => 'truck', 'fields' => [['name' => 'tonnes', 'label' => 'Tonnes taken', 'type' => 'number']]],
                'survey' => ['label' => 'Survey', 'icon' => 'ruler', 'fields' => [['name' => 'tonnes', 'label' => 'Tonnes on hand', 'type' => 'number', 'value' => $record->value('tonnes')]]],
            ] : ['survey' => ['label' => 'Survey', 'icon' => 'ruler', 'fields' => [['name' => 'tonnes', 'label' => 'Tonnes on hand', 'type' => 'number']]]],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'submit':
                $record->update(['status' => 'submitted']);

                return $record->title.' submitted: '.number_format($this->number($record, 'tonnes_mined')).' t mined.';
            case 'approve':
                $record->update(['status' => 'approved']);

                return $record->title.' approved.';
            case 'fire':
                $values = $request->validate(['fire_time' => ['nullable', 'date_format:H:i'], 'vibration' => ['nullable', 'numeric', 'min:0']]);
                if ((int) $record->value('holes') < 1 || $this->number($record, 'explosives_kg') <= 0) {
                    throw ValidationException::withMessages(['holes' => 'Record the holes charged and the explosives used.']);
                }
                if ($misfire = $this->openMisfire($record->id)) {
                    throw ValidationException::withMessages(['status' => 'The misfire at '.$misfire->title.' must be cleared before another blast.']);
                }
                $record->update(['status' => 'fired', 'data' => [...$record->data, 'fire_time' => $values['fire_time'] ?? now()->format('H:i'), 'vibration' => $values['vibration'] ?? $record->value('vibration')]]);

                return $record->title.' fired'.($record->value('_over_limit') ? '; vibration of '.$record->value('vibration').' mm/s is over the '.self::VIBRATION_LIMIT.' mm/s limit.' : '.');
            case 'misfire':
                $record->update(['status' => 'misfire', 'data' => [...$record->data, '_misfire_at' => now()->toDateTimeString()]]);

                return 'Misfire at '.$record->title.': no blasting until it is cleared.';
            case 'clear':
                $record->update(['status' => 'cleared', 'data' => [...$record->data, '_cleared_at' => now()->toDateTimeString()]]);

                return 'Misfire at '.$record->title.' cleared.';
            case 'draw':
                $tonnes = (float) $request->validate(['tonnes' => ['required', 'numeric', 'gt:0']])['tonnes'];
                if ($tonnes > $this->number($record, 'tonnes')) {
                    throw ValidationException::withMessages(['tonnes' => 'Only '.number_format($this->number($record, 'tonnes'), 1).' t on hand.']);
                }
                $record->update(['data' => [...$record->data, 'tonnes' => round($this->number($record, 'tonnes') - $tonnes, 2), '_drawn' => round($this->number($record, '_drawn') + $tonnes, 2)]]);

                return number_format($tonnes, 1).' t loaded out of '.$record->title.'; '.number_format($this->number($record, 'tonnes'), 1).' t left.';
            default:
                $tonnes = (float) $request->validate(['tonnes' => ['required', 'numeric', 'min:0']])['tonnes'];
                $record->update(['occurs_on' => today(), 'data' => [...$record->data, 'tonnes' => $tonnes]]);

                return $record->title.' surveyed at '.number_format($tonnes, 1).' t.';
        }
    }

    public function homeCards(): array
    {
        $today = $this->records('shifts')->whereDate('occurs_on', today())->get();
        $misfires = $this->records('blasts')->where('status', 'misfire')->get();

        $cards = $misfires->isEmpty() ? [] : [['view' => 'apps.logic.alert-card', 'data' => [
            'title' => 'Misfire to clear: no blasting', 'body' => $misfires->map(fn (Record $blast) => $blast->title.' ('.$blast->occurs_on?->format('d M').', blaster '.$blast->value('blaster').')')->implode('; '),
        ]]];

        return [
            ...$cards,
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today', 'icon' => 'pickaxe', 'stats' => [
                ['label' => 'Tonnes mined', 'value' => number_format($today->sum(fn (Record $shift) => $this->number($shift, 'tonnes_mined')))],
                ['label' => 'Tonnes hauled', 'value' => number_format($today->sum(fn (Record $shift) => $this->number($shift, 'tonnes_hauled')))],
                ['label' => 'Downtime', 'value' => $today->sum(fn (Record $shift) => (int) $shift->value('downtime_minutes')).' min'],
                ['label' => 'On stockpiles', 'value' => number_format($this->records('stockpiles')->where('status', 'active')->get()->sum(fn (Record $pile) => $this->number($pile, 'tonnes'))).' t'],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $shifts = $this->dated('shifts', $from, $to)->whereIn('status', ['submitted', 'approved'])->get();
        $blasts = $this->dated('blasts', $from, $to)->whereIn('status', ['fired', 'misfire', 'cleared'])->get();

        $byPit = $shifts->groupBy(fn (Record $shift) => trim((string) $shift->value('pit')) ?: '—')->sortKeys()
            ->map(fn (Collection $group, string $pit) => [
                $pit, $group->count(),
                number_format($group->sum(fn (Record $shift) => $this->number($shift, 'tonnes_mined'))),
                number_format($group->sum(fn (Record $shift) => $this->number($shift, 'tonnes_hauled'))),
                $group->sum(fn (Record $shift) => (int) $shift->value('downtime_minutes')).' min',
                round($group->avg(fn (Record $shift) => (float) $shift->value('_availability')), 1).'%',
            ])->values()->all();

        $byMonth = collect($this->months($from, $to))->map(fn (string $label, string $month) => [$label, number_format($shifts->filter(fn (Record $shift) => $shift->occurs_on?->format('Y-m') === $month)->sum(fn (Record $shift) => $this->number($shift, 'tonnes_mined')))])->values()->all();

        $blastRows = $blasts->sortBy('occurs_on')->map(fn (Record $blast) => [
            $blast->occurs_on?->format('d M Y'), $blast->title, (int) $blast->value('holes'), number_format($this->number($blast, 'explosives_kg'), 1),
            $blast->value('vibration') !== null ? $blast->value('vibration').' mm/s'.($blast->value('_over_limit') ? ' (over limit)' : '') : '—',
            ucfirst($blast->status),
        ])->values()->all();

        return [
            ['title' => 'Production by pit', 'columns' => ['Pit / section', 'Shifts', 'Tonnes mined', 'Tonnes hauled', 'Downtime', 'Availability'], 'rows' => $byPit],
            ['title' => 'Tonnes mined by month', 'columns' => ['Month', 'Tonnes'], 'rows' => $byMonth],
            ['title' => 'Blasting register', 'columns' => ['Date', 'Location', 'Holes', 'Explosives (kg)', 'Vibration', 'Outcome'], 'rows' => $blastRows],
        ];
    }
}
