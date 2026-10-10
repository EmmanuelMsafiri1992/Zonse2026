<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Parking lots & toll plazas: shifts are only opened at open sites, and a cashier can't have two shifts open.
 * Closing a shift adds up the cash and card takings and compares them with what was expected: the shift is
 * closed when they match, short when there is less and over when there is more, and the difference is kept.
 * Each site shows this month's takings, and the report shows takings by site and the difference by cashier.
 */
class TollPlazaLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key !== 'shifts') {
            return $errors;
        }
        if (! $existing && filled($data['site'] ?? null) && ($site = $this->records('sites')->find($data['site'])) && $site->status !== 'open') {
            $errors['data.site'] = $site->title.' is closed.';
        }
        if ($payload['status'] === 'open' && filled($payload['assignee_id'] ?? null)
            && ($open = $this->records('shifts')->where('status', 'open')->where('assignee_id', $payload['assignee_id'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first())) {
            $errors['assignee_id'] = 'This cashier already has '.$open->title.' open.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'shifts') {
            return;
        }
        $record->occurs_on ??= today();
        if ($record->status === 'open') {
            return;
        }
        $total = round($this->number($record, 'cash') + $this->number($record, 'card'), 2);
        $variance = round($total - $this->number($record, 'expected'), 2);
        $record->amount = $total;
        $record->status = match (true) {
            blank($record->value('expected')) || $variance == 0 => 'closed',
            $variance < 0 => 'short',
            default => 'over',
        };
        $this->put($record, ['_variance' => blank($record->value('expected')) ? 0 : $variance]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'sites') {
            return $record->status === 'open' ? ['close_site' => ['label' => 'Close site', 'icon' => 'lock']] : ['open_site' => ['label' => 'Reopen site', 'icon' => 'lock-open']];
        }

        return $record->status === 'open' ? ['close' => ['label' => 'Close shift', 'icon' => 'lock', 'fields' => [
            ['name' => 'vehicles', 'label' => 'Vehicles', 'type' => 'number', 'value' => $record->value('vehicles')],
            ['name' => 'cash', 'label' => 'Cash counted', 'type' => 'number', 'value' => $record->value('cash')],
            ['name' => 'card', 'label' => 'Card total', 'type' => 'number', 'value' => $record->value('card')],
        ]]] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'close_site':
                $record->update(['status' => 'closed']);

                return $record->title.' closed.';
            case 'open_site':
                $record->update(['status' => 'open']);

                return $record->title.' reopened.';
            default:
                $input = $request->validate(['vehicles' => ['nullable', 'integer', 'min:0'], 'cash' => ['required', 'numeric', 'min:0'], 'card' => ['nullable', 'numeric', 'min:0']]);
                $record->update(['status' => 'closed', 'data' => [...$record->data, 'vehicles' => $input['vehicles'] ?? $record->value('vehicles'), 'cash' => (float) $input['cash'], 'card' => (float) ($input['card'] ?? 0)]]);
                $variance = $this->number($record, '_variance');

                return $record->title.'\'s shift closed with '.$this->money($record->amount).match ($record->status) {
                    'short' => ', '.$this->money(abs($variance)).' short',
                    'over' => ', '.$this->money($variance).' over',
                    default => '',
                }.'.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'sites') {
            return [];
        }
        $month = $this->linked('shifts', 'site', $record)->where('status', '!=', 'open')->whereDate('occurs_on', '>=', today()->startOfMonth()->toDateString())->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'This month', 'icon' => 'coins', 'stats' => [
            ['label' => 'Takings', 'value' => $this->money($month->sum('amount'))],
            ['label' => 'Vehicles', 'value' => number_format($month->sum(fn (Record $shift) => (int) $shift->value('vehicles')))],
            ['label' => 'Short shifts', 'value' => $month->where('status', 'short')->count()],
        ]]]];
    }

    public function homeCards(): array
    {
        $month = $this->records('shifts')->where('status', '!=', 'open')->whereDate('occurs_on', '>=', today()->startOfMonth()->toDateString())->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Shifts open', 'icon' => 'coins', 'empty' => 'No shifts open.',
                'rows' => $this->records('shifts')->where('status', 'open')->orderBy('occurs_on')->get()
                    ->map(fn (Record $shift) => ['label' => $shift->title, 'sub' => $shift->occurs_on->format('d M'), 'value' => $this->money($shift->value('expected')), 'href' => $shift->url(), 'tone' => $shift->occurs_on->lt(today()) ? 'warning' : null])->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'This month', 'icon' => 'parking-meter', 'stats' => [
                ['label' => 'Takings', 'value' => $this->money($month->sum('amount'))],
                ['label' => 'Shortages', 'value' => $this->money(abs($month->where('status', 'short')->sum(fn (Record $shift) => $this->number($shift, '_variance'))))],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $shifts = $this->dated('shifts', $from, $to)->where('status', '!=', 'open')->get();
        $sites = $this->records('sites')->pluck('title', 'id');
        $names = User::query()->whereIn('id', $shifts->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');

        return [
            ['title' => 'Takings by site', 'columns' => ['Site', 'Shifts', 'Vehicles', 'Cash', 'Card', 'Total'], 'rows' => $shifts
                ->groupBy(fn (Record $shift) => $sites[(int) $shift->value('site')] ?? 'No site')->sortKeys()
                ->map(fn ($group, string $site) => [$site, $group->count(), number_format($group->sum(fn (Record $shift) => (int) $shift->value('vehicles'))), $this->money($group->sum(fn (Record $shift) => $this->number($shift, 'cash'))), $this->money($group->sum(fn (Record $shift) => $this->number($shift, 'card'))), $this->money($group->sum('amount'))])
                ->values()->all()],
            ['title' => 'Differences by cashier', 'columns' => ['Cashier', 'Shifts', 'Short', 'Over', 'Net difference'], 'rows' => $shifts
                ->groupBy(fn (Record $shift) => $names[$shift->assignee_id] ?? $shift->title)->sortKeys()
                ->map(fn ($group, string $cashier) => [$cashier, $group->count(), $group->where('status', 'short')->count(), $group->where('status', 'over')->count(), $this->money($group->sum(fn (Record $shift) => $this->number($shift, '_variance')))])
                ->values()->all()],
        ];
    }
}
