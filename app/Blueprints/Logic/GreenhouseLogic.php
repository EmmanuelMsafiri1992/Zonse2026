<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Greenhouse irrigation: fallow blocks take no scheduled runs, runs on the same block cannot
 * overlap, a run's EC above the safe ceiling is flagged, runs left scheduled after their day are
 * marked skipped, and each block adds up the water it was given over the last week.
 */
class GreenhouseLogic extends AppLogic
{
    /** Electrical conductivity (mS/cm) above which the fertigation mix can scorch roots. */
    public const MAX_EC = 3.5;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'irrigations' || empty($payload['data']['block'])) {
            return [];
        }

        $block = $this->records('blocks')->find($payload['data']['block']);
        if (! $block) {
            return [];
        }
        if ($payload['status'] === 'scheduled' && $block->status === 'fallow') {
            return ['data.block' => $block->title.' is fallow.'];
        }

        $start = $this->minutesOf($payload['data']['start_time'] ?? null);
        if ($start === null || blank($payload['occurs_on'] ?? null) || $payload['status'] === 'skipped') {
            return [];
        }
        $end = $start + max(1, (int) ($payload['data']['minutes'] ?? 0));

        $clash = $this->linked('irrigations', 'block', $block)->whereDate('occurs_on', $payload['occurs_on'])->whereNot('status', 'skipped')
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
            ->first(function (Record $run) use ($start, $end) {
                $from = $this->minutesOf($run->value('start_time'));

                return $from !== null && $start < $from + max(1, (int) $this->number($run, 'minutes')) && $from < $end;
            });

        return $clash ? ['data.start_time' => 'Overlaps '.$clash->number.' at '.$clash->value('start_time').' on '.$block->title.'.'] : [];
    }

    protected function minutesOf(?string $time): ?int
    {
        if (! $time || ! preg_match('/^(\d{1,2}):(\d{2})/', $time, $parts)) {
            return null;
        }

        return (int) $parts[1] * 60 + (int) $parts[2];
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'irrigations') {
            $this->put($record, ['_high_ec' => $this->number($record, 'ec') > self::MAX_EC]);
        }

        if ($record->entity === 'blocks' && $record->exists) {
            $week = $this->linked('irrigations', 'block', $record)->where('status', 'done')->whereDate('occurs_on', '>', today()->subDays(7))->get();
            $water = $week->sum(fn (Record $run) => $this->number($run, 'water'));
            $area = $this->number($record, 'area');
            $last = $this->linked('irrigations', 'block', $record)->where('status', 'done')->orderByDesc('occurs_on')->first();
            $this->put($record, ['_water_7d' => round($water, 1), '_runs_7d' => $week->count(), '_litres_per_m2' => $area > 0 ? round($water / $area, 2) : null, '_last_run' => $last?->occurs_on?->toDateString()]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'irrigations') {
            $this->recalculate($this->parent($record, 'block'));
            $this->recalculate($this->previousParent($record, 'block'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'irrigations') {
            $this->recalculate($this->parent($record, 'block'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $missed = $this->records('irrigations')->where('status', 'scheduled')->whereDate('occurs_on', '<', today())->get();
        $missed->each(fn (Record $run) => $run->update(['status' => 'skipped', 'data' => array_merge((array) $run->data, ['_missed' => true])]));
        $this->records('blocks')->get()->each(fn (Record $block) => $block->save());

        return $missed->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'irrigations' && $record->value('_high_ec')) {
            return [['view' => 'apps.logic.alert-card', 'data' => ['tone' => 'danger', 'icon' => 'triangle-alert', 'title' => 'EC too high', 'body' => 'EC '.$record->value('ec').' is above '.self::MAX_EC.'. Dilute the mix before it scorches the roots.']]];
        }

        if ($record->entity !== 'blocks') {
            return [];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Last 7 days', 'icon' => 'droplets', 'stats' => [
            ['label' => 'Runs', 'value' => (string) (int) $record->value('_runs_7d')],
            ['label' => 'Water', 'value' => number_format($this->number($record, '_water_7d')).' L'],
            ['label' => 'Per m²', 'value' => $record->value('_litres_per_m2') !== null ? $record->value('_litres_per_m2').' L' : '—'],
            ['label' => 'Last watered', 'value' => $record->value('_last_run') ? Carbon::parse($record->value('_last_run'))->format('d M') : 'Never'],
        ]]]];
    }

    public function homeCards(): array
    {
        $names = $this->records('blocks')->pluck('title', 'id');
        $today = $this->records('irrigations')->whereDate('occurs_on', today())->get()->sortBy(fn (Record $run) => (string) $run->value('start_time'));

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Today\'s irrigation', 'icon' => 'droplets', 'empty' => 'No irrigation runs today.',
            'rows' => $today->map(fn (Record $run) => [
                'label' => ($run->value('start_time') ?? '—').' · '.($names[$run->value('block')] ?? '—'), 'sub' => $run->value('minutes') ? $run->value('minutes').' min'.($run->value('fertiliser') ? ' · '.$run->value('fertiliser') : '') : null,
                'value' => ucfirst($run->status), 'href' => $run->url(), 'tone' => $run->status === 'done' ? 'success' : ($run->value('_high_ec') ? 'danger' : null),
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $runs = $this->dated('irrigations', $from, $to)->get();
        $blocks = $this->records('blocks')->orderBy('title')->get()->map(function (Record $block) use ($runs) {
            $mine = $runs->where('data.block', $block->id);
            $water = $mine->where('status', 'done')->sum(fn (Record $run) => $this->number($run, 'water'));

            return [$block->title, (string) ($block->value('crop') ?? '—'), $mine->where('status', 'done')->count(), $mine->where('status', 'skipped')->count(), number_format($water),
                $this->number($block, 'area') > 0 ? round($water / $this->number($block, 'area'), 1) : '—'];
        })->all();

        return [['title' => 'Water by block', 'columns' => ['Block', 'Crop', 'Runs done', 'Skipped', 'Water (L)', 'L per m²'], 'rows' => $blocks]];
    }
}
