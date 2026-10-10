<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * SEO & analytics dashboard: each keyword is tracked once, however it was typed. Recording a new
 * position moves the old one into "previous", dates the check and keeps the last twelve readings, so
 * movers stand out. Estimated visits come from the monthly searches and the share of clicks each
 * position usually gets. Short codes are lower-case and unique, and click counts only go up.
 */
class SeoLogic extends AppLogic
{
    /**
     * Typical share of searchers who click each position on the first page.
     */
    public const CLICK_SHARE = [1 => 0.28, 2 => 0.15, 3 => 0.11, 4 => 0.08, 5 => 0.07, 6 => 0.05, 7 => 0.04, 8 => 0.03, 9 => 0.03, 10 => 0.025];

    /**
     * Share of clicks for positions on the second page.
     */
    protected const SECOND_PAGE_SHARE = 0.01;

    /**
     * Readings of a keyword's position kept for its history.
     */
    protected const HISTORY = 12;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'keywords') {
            $keyword = $this->keyword((string) $payload['title']);
            if ($this->records('keywords')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $other) => $this->keyword((string) $other->title) === $keyword)) {
                $errors['title'] = '"'.$keyword.'" is already tracked.';
            }
            foreach (['position', 'previous_position'] as $field) {
                if (filled($data[$field] ?? null) && ((float) $data[$field] < 1 || (float) $data[$field] != (int) $data[$field])) {
                    $errors['data.'.$field] = 'A position is a whole number from 1; leave it blank when the page isn\'t ranking.';
                }
            }

            return $errors;
        }

        $code = $this->shortCode((string) ($data['short_code'] ?? ''));
        if (strlen($code) < 3) {
            $errors['data.short_code'] = 'Use at least 3 letters, numbers or dashes.';
        } elseif ($this->records('links')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $other) => $other->value('short_code') === $code)) {
            $errors['data.short_code'] = $code.' is already taken.';
        }
        if ($existing && (float) ($data['clicks'] ?? 0) < $this->number($existing, 'clicks')) {
            $errors['data.clicks'] = 'Clicks only go up; it already has '.number_format($this->number($existing, 'clicks')).'.';
        }

        return $errors;
    }

    /**
     * A keyword in one form: lower case, single spaces.
     */
    protected function keyword(string $keyword): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $keyword)));
    }

    /**
     * A short code in one form: lower case letters, numbers and dashes.
     */
    protected function shortCode(string $code): string
    {
        return trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower(trim($code))), '-');
    }

    /**
     * Estimated monthly visits from a keyword at this position.
     */
    public function visits(?int $position, float $searches): int
    {
        $share = $position === null ? 0 : (self::CLICK_SHARE[$position] ?? ($position <= 20 ? self::SECOND_PAGE_SHARE : 0));

        return (int) round($searches * $share);
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'links') {
            $this->put($record, ['short_code' => $this->shortCode((string) $record->value('short_code'))]);

            return;
        }
        $record->title = $this->keyword((string) $record->title);
        $position = $this->position($record->value('position'));
        $before = $this->position(((array) $record->getOriginal('data'))['position'] ?? null);
        if (! $record->exists || $position !== $before) {
            $history = (array) ($record->value('_history') ?? []);
            $history[] = ['on' => today()->toDateString(), 'position' => $position];
            $this->put($record, ['_history' => array_slice($history, -self::HISTORY)]);
            if ($record->exists) {
                $this->put($record, ['previous_position' => $before]);
                $record->occurs_on = today();
            }
        }
        $record->occurs_on ??= today();
    }

    /**
     * A position as a number, or null when the page isn't ranking.
     */
    protected function position(mixed $value): ?int
    {
        return filled($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'links') {
            return $record->status === 'active' ? ['disable' => ['label' => 'Disable', 'icon' => 'link-2-off']] : ['enable' => ['label' => 'Enable', 'icon' => 'link']];
        }

        return $record->status === 'tracking'
            ? ['check' => ['label' => 'Record position', 'icon' => 'search', 'fields' => [['name' => 'position', 'label' => 'Position (blank if not ranking)', 'type' => 'number']]], 'pause' => ['label' => 'Pause', 'icon' => 'pause']]
            : ['resume' => ['label' => 'Track again', 'icon' => 'play']];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'check':
                $position = $this->position($request->validate(['position' => ['nullable', 'integer', 'min:1']])['position'] ?? null);
                $before = $this->position($record->value('position'));
                $record->update(['occurs_on' => today(), 'data' => [...$record->data, 'position' => $position]]);

                return '"'.$record->title.'" '.$this->movement($before, $position).'.';
            case 'pause':
            case 'resume':
                $record->update(['status' => $action === 'pause' ? 'paused' : 'tracking']);

                return '"'.$record->title.'" '.($action === 'pause' ? 'paused' : 'is tracked again').'.';
            default:
                $record->update(['status' => $action === 'disable' ? 'disabled' : 'active']);

                return $record->value('short_code').' '.($action === 'disable' ? 'disabled' : 'enabled').'.';
        }
    }

    /**
     * How a keyword moved, in words: "up 3 to #4".
     */
    protected function movement(?int $before, ?int $after): string
    {
        return match (true) {
            $after === null && $before === null => 'is still not ranking',
            $after === null => 'dropped out of the rankings from #'.$before,
            $before === null => 'is now ranking at #'.$after,
            $after < $before => 'up '.($before - $after).' to #'.$after,
            $after > $before => 'down '.($after - $before).' to #'.$after,
            default => 'unchanged at #'.$after,
        };
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'keywords') {
            return [];
        }
        $history = array_reverse((array) ($record->value('_history') ?? []));

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Position history', 'icon' => 'history', 'empty' => 'No readings yet.',
            'rows' => array_map(fn (array $reading) => ['label' => Carbon::parse($reading['on'])->format('d M Y'), 'value' => $reading['position'] ? '#'.$reading['position'] : 'Not ranking'], $history),
        ]]];
    }

    public function homeCards(): array
    {
        $keywords = $this->records('keywords')->where('status', 'tracking')->get();
        $ranked = $keywords->map(fn (Record $keyword) => $this->position($keyword->value('position')))->filter();
        $movers = $keywords->filter(fn (Record $keyword) => $this->position($keyword->value('position')) && $this->position($keyword->value('previous_position')))
            ->sortByDesc(fn (Record $keyword) => abs((int) $keyword->value('previous_position') - (int) $keyword->value('position')))
            ->filter(fn (Record $keyword) => (int) $keyword->value('previous_position') !== (int) $keyword->value('position'))->take(5);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Rankings', 'icon' => 'line-chart', 'stats' => [
                ['label' => 'Keywords tracked', 'value' => $keywords->count()],
                ['label' => 'In the top 3', 'value' => $ranked->filter(fn (int $position) => $position <= 3)->count(), 'tone' => 'success'],
                ['label' => 'On page one', 'value' => $ranked->filter(fn (int $position) => $position <= 10)->count()],
                ['label' => 'Estimated visits / month', 'value' => number_format($keywords->sum(fn (Record $keyword) => $this->visits($this->position($keyword->value('position')), $this->number($keyword, 'monthly_searches'))))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Biggest movers', 'icon' => 'arrow-up-down', 'empty' => 'No movement since the last check.',
                'rows' => $movers->map(fn (Record $keyword) => [
                    'label' => $keyword->title, 'value' => $this->movement($this->position($keyword->value('previous_position')), $this->position($keyword->value('position'))), 'href' => $keyword->url(),
                    'tone' => (int) $keyword->value('position') < (int) $keyword->value('previous_position') ? 'success' : 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $keywords = $this->records('keywords')->where('status', 'tracking')->get()
            ->sortBy(fn (Record $keyword) => $this->position($keyword->value('position')) ?? PHP_INT_MAX);
        $bands = ['Top 3' => [1, 3], '4–10' => [4, 10], '11–20' => [11, 20], '21–100' => [21, 100]];
        $spread = collect($bands)->map(fn (array $band, string $label) => [$label, $keywords->filter(fn (Record $keyword) => ($position = $this->position($keyword->value('position'))) && $position >= $band[0] && $position <= $band[1])->count()])->values()
            ->push(['Not ranking', $keywords->filter(fn (Record $keyword) => ! $this->position($keyword->value('position')) || $this->position($keyword->value('position')) > 100)->count()])->all();

        return [
            ['title' => 'Rankings', 'columns' => ['Keyword', 'Position', 'Change', 'Monthly searches', 'Estimated visits'], 'rows' => $keywords->map(function (Record $keyword) {
                $position = $this->position($keyword->value('position'));
                $before = $this->position($keyword->value('previous_position'));

                return [$keyword->title, $position ? '#'.$position : '—', $position && $before ? ($before - $position > 0 ? '+' : '').($before - $position) : '—', number_format($this->number($keyword, 'monthly_searches')), number_format($this->visits($position, $this->number($keyword, 'monthly_searches')))];
            })->values()->all()],
            ['title' => 'Ranking spread', 'columns' => ['Positions', 'Keywords'], 'rows' => $spread],
            ['title' => 'Short links & QR codes', 'columns' => ['Link', 'Short code', 'Clicks', 'Status'], 'rows' => $this->records('links')->get()->sortByDesc(fn (Record $link) => $this->number($link, 'clicks'))
                ->map(fn (Record $link) => [$link->title, (string) $link->value('short_code'), number_format($this->number($link, 'clicks')), ucfirst($link->status)])->values()->all()],
        ];
    }
}
