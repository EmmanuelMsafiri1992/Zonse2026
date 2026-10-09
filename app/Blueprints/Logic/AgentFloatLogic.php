<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Mobile-money agent float: every movement shifts the outlet's e-float and cash drawer
 * (a cash-in takes e-float and adds cash, a cash-out the reverse), so the outlet always
 * knows both balances; a movement cannot spend more than is there or push the float over
 * its limit, and is only reconciled when the counted balances match.
 */
class AgentFloatLogic extends AppLogic
{
    /** How each movement type changes [e-float, cash]. */
    public const EFFECTS = [
        'top_up' => [1, 0],
        'rebalance' => [0, 1],
        'cash_in' => [-1, 1],
        'cash_out' => [1, -1],
        'commission' => [1, 0],
    ];

    /** Below this share of the float limit an outlet shows as running low. */
    public const LOW_SHARE = 0.2;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'floats' || empty($payload['data']['outlet'])) {
            return [];
        }

        $outlet = $this->records('outlets')->find($payload['data']['outlet']);
        $type = $payload['data']['type'] ?? null;
        if (! $outlet || ! isset(self::EFFECTS[$type])) {
            return [];
        }

        $amount = (float) ($payload['amount'] ?? 0);
        $before = $this->balances($outlet, $existing?->id);
        $after = $this->apply($before, $type, $amount);

        if ($type === 'cash_in' && $after['float'] < -0.004) {
            return ['amount' => 'Only '.$this->money($before['float']).' e-float is left at '.$outlet->title.'. Top up first.'];
        }
        if ($type === 'cash_out' && $after['cash'] < -0.004) {
            return ['amount' => 'Only '.$this->money($before['cash']).' cash is in the drawer at '.$outlet->title.'.'];
        }
        $limit = $this->number($outlet, 'float_limit');
        if ($type === 'top_up' && $limit > 0 && $after['float'] - $limit > 0.004) {
            return ['amount' => 'That would take the e-float to '.$this->money($after['float']).', over the '.$this->money($limit).' limit.'];
        }

        if ($payload['status'] === 'reconciled') {
            $data = $payload['data'];
            if (blank($data['closing_float'] ?? null) || blank($data['closing_cash'] ?? null)) {
                return ['status' => 'Count the closing e-float and cash before reconciling.'];
            }
            if (abs((float) $data['closing_float'] - $after['float']) > 0.004 || abs((float) $data['closing_cash'] - $after['cash']) > 0.004) {
                return ['status' => 'The counted balances do not match: expected '.$this->money($after['float']).' e-float and '.$this->money($after['cash']).' cash.'];
            }
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'outlets' && $record->exists) {
            $balances = $this->balances($record);
            $this->put($record, ['_float' => $balances['float'], '_cash' => $balances['cash']]);
        }

        if ($record->entity === 'floats' && ($outlet = $this->parent($record, 'outlet'))) {
            $after = $this->apply($this->balances($outlet, $record->id), (string) $record->value('type'), (float) $record->amount);
            $variance = [];
            foreach (['float' => 'closing_float', 'cash' => 'closing_cash'] as $balance => $field) {
                $variance['_'.$balance.'_variance'] = filled($record->value($field)) ? round($this->number($record, $field) - $after[$balance], 2) : null;
            }
            $this->put($record, ['_expected_float' => $after['float'], '_expected_cash' => $after['cash'], ...$variance]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'floats') {
            $this->recalculate($this->parent($record, 'outlet'));
            $this->recalculate($this->previousParent($record, 'outlet'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'floats') {
            $this->recalculate($this->parent($record, 'outlet'));
        }
    }

    /** E-float and cash at an outlet from all its movements, optionally leaving one out. @return array{float: float, cash: float} */
    public function balances(Record $outlet, ?int $except = null): array
    {
        $balances = ['float' => 0.0, 'cash' => 0.0];
        $movements = $this->linked('floats', 'outlet', $outlet)->when($except, fn ($query) => $query->whereKeyNot($except))->get();
        foreach ($movements as $movement) {
            $balances = $this->apply($balances, (string) $movement->value('type'), (float) $movement->amount);
        }

        return $balances;
    }

    /** @param  array{float: float, cash: float}  $balances  @return array{float: float, cash: float} */
    protected function apply(array $balances, string $type, float $amount): array
    {
        [$float, $cash] = self::EFFECTS[$type] ?? [0, 0];

        return ['float' => round($balances['float'] + $float * $amount, 2), 'cash' => round($balances['cash'] + $cash * $amount, 2)];
    }

    public function isLow(Record $outlet): bool
    {
        $limit = $this->number($outlet, 'float_limit');

        return $limit > 0 && $this->number($outlet, '_float') < $limit * self::LOW_SHARE;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'outlets') {
            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Balances', 'icon' => 'wallet', 'stats' => [
                ['label' => 'E-float', 'value' => $this->money($record->value('_float')), 'tone' => $this->isLow($record) ? 'danger' : null],
                ['label' => 'Cash in drawer', 'value' => $this->money($record->value('_cash'))],
                ['label' => 'Float limit', 'value' => $record->value('float_limit') ? $this->money($record->value('float_limit')) : 'None'],
            ], 'note' => $this->isLow($record) ? 'E-float is below '.(self::LOW_SHARE * 100).'% of the limit. Rebalance or top up.' : null]]];
        }

        if ($record->entity === 'floats' && ($record->value('_float_variance') !== null || $record->value('_cash_variance') !== null)) {
            $tone = fn ($variance) => $variance === null ? null : (abs((float) $variance) < 0.005 ? 'success' : 'danger');

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Count', 'icon' => 'scale', 'stats' => [
                ['label' => 'Expected e-float', 'value' => $this->money($record->value('_expected_float'))],
                ['label' => 'E-float variance', 'value' => $this->money($record->value('_float_variance')), 'tone' => $tone($record->value('_float_variance'))],
                ['label' => 'Expected cash', 'value' => $this->money($record->value('_expected_cash'))],
                ['label' => 'Cash variance', 'value' => $this->money($record->value('_cash_variance')), 'tone' => $tone($record->value('_cash_variance'))],
            ]]]];
        }

        return [];
    }

    public function homeCards(): array
    {
        $outlets = $this->records('outlets')->where('status', 'active')->orderBy('title')->get()->sortByDesc(fn (Record $outlet) => $this->isLow($outlet));

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Outlet balances', 'icon' => 'store', 'empty' => 'No active outlets.',
            'rows' => $outlets->map(fn (Record $outlet) => [
                'label' => $outlet->title, 'sub' => 'Cash '.$this->money($outlet->value('_cash')),
                'value' => $this->money($outlet->value('_float')), 'href' => $outlet->url(), 'tone' => $this->isLow($outlet) ? 'danger' : null,
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $movements = $this->dated('floats', $from, $to)->get();
        $names = $this->records('outlets')->pluck('title', 'id');
        $rows = $movements->groupBy(fn (Record $movement) => (int) $movement->value('outlet'))->map(function ($group, $outlet) use ($names) {
            $sum = fn (string $type) => $this->money($group->where('data.type', $type)->sum('amount'));

            return [$names[$outlet] ?? '—', $sum('cash_in'), $sum('cash_out'), $sum('top_up'), $sum('rebalance'), $sum('commission')];
        })->sortBy(0)->values()->all();

        $commission = $this->sumByMonth($movements->where('data.type', 'commission'));

        return [
            ['title' => 'Movements by outlet', 'columns' => ['Outlet', 'Cash in', 'Cash out', 'Top-ups', 'Rebalances', 'Commission'], 'rows' => $rows],
            ['title' => 'Commission by month', 'columns' => ['Month', 'Commission'], 'rows' => collect($this->months($from, $to))->map(fn ($label, $month) => [$label, $this->money($commission[$month] ?? 0)])->values()->all()],
        ];
    }
}
