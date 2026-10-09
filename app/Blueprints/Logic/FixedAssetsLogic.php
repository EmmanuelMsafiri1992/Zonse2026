<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Fixed assets: asset tags are unique and warranties end after purchase; each asset is
 * depreciated straight-line over its useful life from the month after purchase, so the register
 * shows accumulated depreciation and book value on any date, and the period's charge.
 */
class FixedAssetsLogic extends AppLogic
{
    public const WARRANTY_WARNING_DAYS = 30;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if (filled($data['asset_tag'] ?? null)
            && $this->records('assets')->where('data->asset_tag', $data['asset_tag'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
            $errors['data.asset_tag'] = 'Asset tag '.$data['asset_tag'].' is already on the register.';
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The warranty cannot end before the asset was bought.';
        }
        if (filled($data['useful_life_years'] ?? null) && (float) $data['useful_life_years'] <= 0) {
            $errors['data.useful_life_years'] = 'Useful life must be more than zero.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->isDirty('status') && $record->status === 'disposed') {
            $this->put($record, ['_disposed_on' => today()->toDateString()]);
        } elseif ($record->status !== 'disposed') {
            $this->put($record, ['_disposed_on' => null]);
        }
    }

    /** Whole months of depreciation charged by a date, from the month after purchase and stopping at disposal. */
    public function monthsCharged(Record $asset, Carbon $asOf): int
    {
        if (! $asset->occurs_on || $this->number($asset, 'useful_life_years') <= 0) {
            return 0;
        }
        if ($asset->value('_disposed_on') && Carbon::parse($asset->value('_disposed_on'))->lt($asOf)) {
            $asOf = Carbon::parse($asset->value('_disposed_on'));
        }

        $start = $asset->occurs_on->copy()->startOfMonth()->addMonth();
        $months = $asOf->gte($start) ? ($asOf->year - $start->year) * 12 + $asOf->month - $start->month + 1 : 0;

        return (int) min($months, round($this->number($asset, 'useful_life_years') * 12));
    }

    public function monthlyCharge(Record $asset): float
    {
        $life = $this->number($asset, 'useful_life_years') * 12;

        return $life > 0 ? (float) $asset->amount / $life : 0.0;
    }

    public function accumulated(Record $asset, Carbon $asOf): float
    {
        return round(min((float) $asset->amount, $this->monthlyCharge($asset) * $this->monthsCharged($asset, $asOf)), 2);
    }

    public function bookValue(Record $asset, Carbon $asOf): float
    {
        return round((float) $asset->amount - $this->accumulated($asset, $asOf), 2);
    }

    public function recordCards(Record $record): array
    {
        $cards = [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Depreciation', 'icon' => 'trending-down', 'stats' => [
            ['label' => 'Cost', 'value' => $this->money($record->amount)],
            ['label' => 'Per month', 'value' => $this->monthlyCharge($record) > 0 ? $this->money($this->monthlyCharge($record)) : 'No useful life set'],
            ['label' => 'Accumulated', 'value' => $this->money($this->accumulated($record, today()))],
            ['label' => 'Book value today', 'value' => $this->money($this->bookValue($record, today()))],
        ]]]];

        if ($record->due_on && $record->status !== 'disposed' && $record->due_on->gte(today()) && $record->due_on->lte(today()->addDays(self::WARRANTY_WARNING_DAYS))) {
            $cards[] = ['view' => 'apps.logic.alert-card', 'data' => ['tone' => 'warning', 'icon' => 'shield-alert', 'title' => 'Warranty ending',
                'body' => 'The warranty ends on '.$record->due_on->format('d M Y').'. Log any faults with the supplier before then.']];
        }

        return $cards;
    }

    public function homeCards(): array
    {
        $assets = $this->records('assets')->whereNot('status', 'disposed')->get();
        $warranties = $assets->filter(fn (Record $asset) => $asset->due_on && $asset->due_on->gte(today()) && $asset->due_on->lte(today()->addDays(self::WARRANTY_WARNING_DAYS)))->sortBy('due_on');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Asset register', 'icon' => 'archive', 'stats' => [
                ['label' => 'Assets', 'value' => (string) $assets->count()],
                ['label' => 'Cost', 'value' => $this->money($assets->sum('amount'))],
                ['label' => 'Book value', 'value' => $this->money($assets->sum(fn (Record $asset) => $this->bookValue($asset, today())))],
                ['label' => 'Under repair', 'value' => (string) $assets->where('status', 'under_repair')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Warranties ending', 'icon' => 'shield-alert', 'empty' => 'No warranties end in the next 30 days.',
                'rows' => $warranties->map(fn (Record $asset) => ['label' => $asset->title, 'sub' => $asset->value('asset_tag'), 'value' => $asset->due_on->format('d M Y'), 'href' => $asset->url(), 'tone' => 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $assets = $this->records('assets')->whereDate('occurs_on', '<=', $to)->orderBy('title')->get();
        $opening = $from->copy()->subDay();

        $schedule = $assets->map(fn (Record $asset) => [
            (string) ($asset->value('asset_tag') ?? '—'), $asset->title, $this->money($asset->amount),
            $this->money($this->accumulated($asset, $to) - $this->accumulated($asset, $opening)), $this->money($this->accumulated($asset, $to)), $this->money($this->bookValue($asset, $to)),
        ])->all();

        $categories = $assets->groupBy(fn (Record $asset) => (string) ($asset->value('category') ?: 'other'))->sortKeys()->map(fn ($group, $category) => [
            ucfirst($category), $group->count(), $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $asset) => $this->bookValue($asset, $to))),
        ])->values()->all();

        $people = User::query()->whereIn('id', $assets->map(fn (Record $asset) => $asset->value('custodian'))->filter()->unique())->pluck('name', 'id');
        $custodians = $assets->where('status', '!=', 'disposed')->groupBy(fn (Record $asset) => $people[$asset->value('custodian')] ?? 'Nobody')->sortKeys()
            ->map(fn ($group, $person) => [$person, $group->count(), $group->pluck('title')->implode(', ')])->values()->all();

        return [
            ['title' => 'Depreciation schedule', 'columns' => ['Tag', 'Asset', 'Cost', 'Charge for period', 'Accumulated', 'Book value'], 'rows' => $schedule],
            ['title' => 'By category', 'columns' => ['Category', 'Assets', 'Cost', 'Book value'], 'rows' => $categories],
            ['title' => 'Who holds what', 'columns' => ['Custodian', 'Assets', 'Items'], 'rows' => $custodians],
        ];
    }
}
