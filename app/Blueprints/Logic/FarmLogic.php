<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Farm and crops: a planting cannot take more of a field than is free and fallow or leased-out
 * fields take no new crops; each planting adds up what its activities cost and what its harvests
 * weighed and fetched, so it shows yield per hectare against the expected yield and its margin.
 */
class FarmLogic extends AppLogic
{
    public const LIVE = ['planned', 'planted', 'growing'];

    /** Tonnes in one harvest unit; bags and crates are left out of weights. */
    public const TONNES = ['kg' => 0.001, 'tonnes' => 1.0];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'plantings' || empty($payload['data']['field'])) {
            return [];
        }

        $field = $this->records('fields')->find($payload['data']['field']);
        if (! $field) {
            return [];
        }

        $moving = ! $existing || (int) $existing->value('field') !== $field->id;
        if ($moving && $field->status !== 'active' && in_array($payload['status'], self::LIVE, true)) {
            return ['data.field' => $field->title.' is '.str_replace('_', ' ', $field->status).'.'];
        }

        $size = $this->number($field, 'size_ha');
        $area = (float) ($payload['data']['area_ha'] ?? 0);
        if ($size > 0 && $area > 0 && in_array($payload['status'], self::LIVE, true)) {
            $free = round($size - $this->areaInUse($field, $existing?->id), 2);
            if ($area - $free > 0.004) {
                return ['data.area_ha' => 'Only '.$free.' ha of '.$field->title.' is free.'];
            }
        }

        return [];
    }

    public function areaInUse(Record $field, ?int $except = null): float
    {
        return (float) $this->linked('plantings', 'field', $field)->whereIn('status', self::LIVE)
            ->when($except, fn ($query) => $query->whereKeyNot($except))->get()->sum(fn (Record $planting) => $this->number($planting, 'area_ha'));
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'plantings' || ! $record->exists) {
            return;
        }

        $cost = (float) $this->linked('activities', 'planting', $record)->where('status', 'done')->sum('amount');
        $harvests = $this->linked('harvests', 'planting', $record)->whereNot('status', 'spoiled')->get();
        $tonnes = round($harvests->sum(fn (Record $harvest) => $this->number($harvest, 'quantity') * (self::TONNES[$harvest->value('unit')] ?? 0)), 3);
        $value = (float) $harvests->sum('amount');
        $area = $this->number($record, 'area_ha');

        $this->put($record, [
            '_cost' => round($cost, 2), '_tonnes' => $tonnes, '_value' => round($value, 2), '_margin' => round($value - $cost, 2),
            '_yield_per_ha' => $area > 0 ? round($tonnes / $area, 2) : null,
        ]);

        if ($harvests->isNotEmpty() && in_array($record->status, ['planted', 'growing'], true)) {
            $record->status = 'harvested';
        }
    }

    public function saved(Record $record): void
    {
        if (in_array($record->entity, ['activities', 'harvests'], true)) {
            $this->recalculate($this->parent($record, 'planting'));
            $this->recalculate($this->previousParent($record, 'planting'));
        }
    }

    public function deleted(Record $record): void
    {
        if (in_array($record->entity, ['activities', 'harvests'], true)) {
            $this->recalculate($this->parent($record, 'planting'));
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'plantings') {
            $expected = $this->number($record, 'expected_yield');
            $tonnes = $this->number($record, '_tonnes');

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Crop result', 'icon' => 'wheat', 'stats' => [
                ['label' => 'Harvested', 'value' => $tonnes.' t'.($expected > 0 ? ' of '.$expected.' t expected' : ''), 'tone' => $expected > 0 && $tonnes >= $expected ? 'success' : null],
                ['label' => 'Yield', 'value' => $record->value('_yield_per_ha') !== null ? $record->value('_yield_per_ha').' t/ha' : '—'],
                ['label' => 'Costs', 'value' => $this->money($record->value('_cost'))],
                ['label' => 'Margin', 'value' => $this->money($record->value('_margin')), 'tone' => $this->number($record, '_margin') < 0 ? 'danger' : 'success'],
            ]]]];
        }

        if ($record->entity === 'fields') {
            $live = $this->linked('plantings', 'field', $record)->whereIn('status', self::LIVE)->get();

            return [
                ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Field use', 'icon' => 'map', 'stats' => [
                    ['label' => 'Size', 'value' => $this->number($record, 'size_ha').' ha'],
                    ['label' => 'Planted', 'value' => round($this->areaInUse($record), 2).' ha'],
                    ['label' => 'Free', 'value' => max(0, round($this->number($record, 'size_ha') - $this->areaInUse($record), 2)).' ha'],
                ]]],
                ['view' => 'apps.logic.list-card', 'data' => [
                    'title' => 'Crops in the ground', 'icon' => 'sprout', 'empty' => 'Nothing planted.',
                    'rows' => $live->map(fn (Record $planting) => ['label' => $planting->title, 'sub' => $planting->value('variety'), 'value' => ucfirst($planting->status), 'href' => $planting->url()])->values()->all(),
                ]],
            ];
        }

        return [];
    }

    public function homeCards(): array
    {
        $activities = $this->records('activities')->where('status', 'planned')->whereDate('occurs_on', '<=', today()->addDays(7))->orderBy('occurs_on')->get();
        $harvests = $this->records('plantings')->whereIn('status', ['planted', 'growing'])->whereDate('due_on', '<=', today()->addDays(14))->orderBy('due_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Work this week', 'icon' => 'shovel', 'empty' => 'No field work planned this week.',
                'rows' => $activities->take(10)->map(fn (Record $activity) => [
                    'label' => $activity->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $activity->value('type'))),
                    'value' => $activity->occurs_on?->format('d M') ?? '—', 'href' => $activity->url(), 'tone' => $activity->occurs_on?->lt(today()) ? 'danger' : null,
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Harvests coming up', 'icon' => 'wheat', 'empty' => 'No harvests in the next two weeks.',
                'rows' => $harvests->map(fn (Record $planting) => ['label' => $planting->title, 'sub' => $planting->value('variety'), 'value' => $planting->due_on->format('d M'), 'href' => $planting->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $plantings = $this->dated('plantings', $from, $to)->get();
        $rows = $plantings->groupBy(fn (Record $planting) => $planting->title)->sortKeys()->map(function ($group, $crop) {
            $area = $group->sum(fn (Record $planting) => $this->number($planting, 'area_ha'));
            $tonnes = $group->sum(fn (Record $planting) => $this->number($planting, '_tonnes'));

            return [$crop, round($area, 2), round($tonnes, 2), $area > 0 ? round($tonnes / $area, 2) : '—',
                $this->money($group->sum(fn (Record $planting) => $this->number($planting, '_cost'))), $this->money($group->sum(fn (Record $planting) => $this->number($planting, '_value'))),
                $this->money($group->sum(fn (Record $planting) => $this->number($planting, '_margin')))];
        })->values()->all();

        $activities = $this->dated('activities', $from, $to)->where('status', 'done')->get()->groupBy(fn (Record $activity) => (string) $activity->value('type'))
            ->map(fn ($group, $type) => [ucfirst(str_replace('_', ' ', $type)), $group->count(), $this->money($group->sum('amount'))])->values()->all();

        return [
            ['title' => 'Crop profitability', 'columns' => ['Crop', 'Hectares', 'Tonnes', 't/ha', 'Costs', 'Value', 'Margin'], 'rows' => $rows],
            ['title' => 'Field work', 'columns' => ['Activity', 'Done', 'Cost'], 'rows' => $activities],
        ];
    }
}
