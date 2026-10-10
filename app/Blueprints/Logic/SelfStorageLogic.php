<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Self-storage units: a unit is rented to one customer at a time, and each live rental has its own gate
 * code (one is made up when none is given). The rent defaults to the unit's monthly rate and is paid
 * a month ahead from move-in. A rental falls into arrears once its paid-until date passes (checked each
 * night), and the unit is then overlocked. Paying clears it, and moving out frees the unit and its code.
 */
class SelfStorageLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key !== 'rentals' || $payload['status'] === 'ended') {
            return $errors;
        }
        if (filled($data['unit'] ?? null) && ($other = $this->live()->linkedTo('unit', (int) $data['unit'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first())) {
            $errors['data.unit'] = 'This unit is rented to '.$other->title.'.';
        }
        if (filled($data['gate_code'] ?? null) && $this->live()->where('data->gate_code', (string) $data['gate_code'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
            $errors['data.gate_code'] = 'Another customer already uses gate code '.$data['gate_code'].'.';
        }

        return $errors;
    }

    /**
     * Rentals that are still running.
     */
    protected function live()
    {
        return $this->records('rentals')->whereIn('status', ['active', 'in_arrears']);
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'rentals') {
            return;
        }
        $record->occurs_on ??= today();
        $record->due_on ??= $record->occurs_on->copy()->addMonthNoOverflow();
        if ((float) $record->amount <= 0 && ($unit = $this->parent($record, 'unit'))) {
            $record->amount = $this->number($unit, 'monthly_rate');
        }
        if ($record->status === 'ended') {
            return;
        }
        if (blank($record->value('gate_code'))) {
            do {
                $code = (string) random_int(100000, 999999);
            } while ($this->live()->where('data->gate_code', $code)->exists());
            $this->put($record, ['gate_code' => $code]);
        }
        $record->status = $record->due_on->lt(today()) ? 'in_arrears' : 'active';
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'rentals') {
            return;
        }
        if ($unit = $this->parent($record, 'unit')) {
            $status = ['active' => 'occupied', 'in_arrears' => 'overlocked', 'ended' => 'vacant'][$record->status];
            if ($unit->status !== $status) {
                $unit->update(['status' => $status]);
            }
        }
        if (($previous = $this->previousParent($record, 'unit')) && $previous->status !== 'vacant') {
            $previous->update(['status' => 'vacant']);
        }
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('rentals')->where('status', 'active')->whereDate('due_on', '<', today()->toDateString())->get()
            ->each(fn (Record $rental) => $rental->save())->count();
    }

    public function actions(Record $record): array
    {
        return $record->entity === 'rentals' && $record->status !== 'ended' ? [
            'pay' => ['label' => 'Rent paid', 'icon' => 'banknote', 'fields' => [['name' => 'months', 'label' => 'Months paid', 'type' => 'number', 'value' => 1]]],
            'end' => ['label' => 'Moved out', 'icon' => 'log-out'],
        ] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'end') {
            $code = $record->value('gate_code');
            $record->update(['status' => 'ended', 'data' => [...$record->data, 'gate_code' => null]]);

            return $record->title.' moved out'.($code ? '; gate code '.$code.' no longer works' : '').'.';
        }
        $months = (int) $request->validate(['months' => ['required', 'integer', 'min:1', 'max:24']])['months'];
        $was = $record->status;
        $record->update(['due_on' => $record->due_on->copy()->addMonthsNoOverflow($months)]);

        return $record->title.' paid '.$this->money((float) $record->amount * $months).' for '.$months.' '.str('month')->plural($months).'; paid until '.$record->due_on->format('d M Y')
            .($was === 'in_arrears' ? ($record->status === 'active' ? ' and the overlock can come off' : ' but still in arrears') : '').'.';
    }

    public function homeCards(): array
    {
        $units = $this->records('units')->get();
        $taken = $units->whereIn('status', ['occupied', 'overlocked', 'reserved'])->count();
        $arrears = $this->records('rentals')->where('status', 'in_arrears')->orderBy('due_on')->get();
        $names = $units->pluck('title', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Units', 'icon' => 'warehouse', 'stats' => [
                ['label' => 'Occupancy', 'value' => $units->isNotEmpty() ? round($taken / $units->count() * 100).'%' : '—'],
                ['label' => 'Vacant', 'value' => $units->where('status', 'vacant')->count()],
                ['label' => 'Monthly rent roll', 'value' => $this->money($this->live()->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Overlocked', 'icon' => 'lock', 'empty' => 'Nobody is behind on rent.',
                'rows' => $arrears->map(fn (Record $rental) => ['label' => $rental->title, 'sub' => $names[(int) $rental->value('unit')] ?? null, 'value' => 'since '.$rental->due_on->format('d M'), 'href' => $rental->url(), 'tone' => 'danger'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        return [['title' => 'Occupancy by unit size', 'columns' => ['Size', 'Units', 'Let', 'Occupancy', 'Monthly rates'], 'rows' => $this->records('units')->get()
            ->groupBy(fn (Record $unit) => ucfirst((string) $unit->value('size')))->sortKeys()
            ->map(function ($group, string $size) {
                $let = $group->whereIn('status', ['occupied', 'overlocked'])->count();

                return [$size, $group->count(), $let, round($let / $group->count() * 100).'%', $this->money($group->sum(fn (Record $unit) => $this->number($unit, 'monthly_rate')))];
            })->values()->all()]];
    }
}
