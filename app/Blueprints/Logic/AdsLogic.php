<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Ads manager: a campaign needs a budget to go active and an end date no earlier than its start.
 * Its results are running totals that only go up, with clicks no more than impressions and
 * conversions no more than clicks, and every update works out the click-through rate, cost per
 * click, cost per thousand impressions and cost per conversion. Each morning campaigns past their
 * end date end, and active campaigns that have spent their budget are paused.
 */
class AdsLogic extends AppLogic
{
    /**
     * Running totals a campaign reports.
     */
    protected const TOTALS = ['impressions', 'clicks', 'conversions', 'spend'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($existing?->status === 'ended' && $payload['status'] !== 'ended') {
            return ['status' => 'This campaign has ended; start a new one.'];
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The campaign cannot end before it starts.';
        }
        if ($payload['status'] === 'active' && (float) ($payload['amount'] ?? 0) <= 0) {
            $errors['amount'] = 'Set a budget before going live.';
        }
        if ((float) ($data['clicks'] ?? 0) > (float) ($data['impressions'] ?? 0)) {
            $errors['data.clicks'] = 'Clicks cannot be more than impressions.';
        }
        if ((float) ($data['conversions'] ?? 0) > (float) ($data['clicks'] ?? 0)) {
            $errors['data.conversions'] = 'Conversions cannot be more than clicks.';
        }
        foreach (self::TOTALS as $total) {
            if ($existing && (float) ($data[$total] ?? 0) < $this->number($existing, $total)) {
                $errors['data.'.$total] = ucfirst($total).' are running totals and cannot go down.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $this->put($record, $this->metrics($this->number($record, 'impressions'), $this->number($record, 'clicks'), $this->number($record, 'conversions'), $this->number($record, 'spend')));
    }

    /**
     * Click-through rate and costs from a campaign's totals; null where there is nothing to divide by.
     *
     * @return array{_ctr: ?float, _cpc: ?float, _cpm: ?float, _cpa: ?float}
     */
    public function metrics(float $impressions, float $clicks, float $conversions, float $spend): array
    {
        return [
            '_ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : null,
            '_cpc' => $clicks > 0 ? round($spend / $clicks, 2) : null,
            '_cpm' => $impressions > 0 ? round($spend / $impressions * 1000, 2) : null,
            '_cpa' => $conversions > 0 ? round($spend / $conversions, 2) : null,
        ];
    }

    public function actions(Record $record): array
    {
        $stats = ['stats' => ['label' => 'Update results', 'icon' => 'bar-chart-3', 'fields' => array_map(fn (string $total) => ['name' => $total, 'label' => ucfirst($total).' to date', 'type' => 'number', 'value' => $record->value($total) ?? 0], self::TOTALS)]];

        return match ($record->status) {
            'draft' => ['launch' => ['label' => 'Go live', 'icon' => 'rocket']],
            'active' => [...$stats, 'pause' => ['label' => 'Pause', 'icon' => 'pause'], 'end' => ['label' => 'End', 'icon' => 'square']],
            'paused' => [...$stats, 'resume' => ['label' => 'Resume', 'icon' => 'play'], 'end' => ['label' => 'End', 'icon' => 'square']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'stats':
                $rules = [];
                foreach (self::TOTALS as $total) {
                    $rules[$total] = ['required', 'numeric', 'min:'.$this->number($record, $total)];
                }
                $rules['clicks'][] = 'lte:impressions';
                $rules['conversions'][] = 'lte:clicks';
                $values = array_map('floatval', $request->validate($rules));
                $record->update(['data' => [...$record->data, ...$values]]);
                $parts = array_filter([
                    $record->value('_ctr') !== null ? number_format((float) $record->value('_ctr'), 2).'% click-through' : null,
                    $record->value('_cpc') !== null ? $this->money($record->value('_cpc')).' a click' : null,
                    $record->value('_cpa') !== null ? $this->money($record->value('_cpa')).' a conversion' : null,
                ]);

                return $record->title.': '.($parts ? implode(', ', $parts) : 'no clicks yet').'.';
            case 'launch':
            case 'resume':
                if ((float) $record->amount <= 0) {
                    throw ValidationException::withMessages(['amount' => 'Set a budget before going live.']);
                }
                if ($this->number($record, 'spend') >= (float) $record->amount) {
                    throw ValidationException::withMessages(['amount' => 'The budget is spent; raise it to run again.']);
                }
                $record->update(['status' => 'active', 'occurs_on' => $record->occurs_on ?? today(), 'data' => [...$record->data, '_paused_reason' => null]]);

                return $record->title.' is live.';
            case 'pause':
                $record->update(['status' => 'paused']);

                return $record->title.' paused.';
            default:
                $record->update(['status' => 'ended', 'due_on' => $record->due_on && $record->due_on->lt(today()) ? $record->due_on : today()]);

                return $record->title.' ended: '.$this->money($this->number($record, 'spend')).' spent of '.$this->money($record->amount).'.';
        }
    }

    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        foreach ($this->records('campaigns')->whereIn('status', ['active', 'paused'])->whereNotNull('due_on')->whereDate('due_on', '<', today()->toDateString())->get() as $campaign) {
            $campaign->update(['status' => 'ended']);
            $changed++;
        }
        foreach ($this->records('campaigns')->where('status', 'active')->get() as $campaign) {
            if ((float) $campaign->amount > 0 && $this->number($campaign, 'spend') >= (float) $campaign->amount) {
                $campaign->update(['status' => 'paused', 'data' => [...$campaign->data, '_paused_reason' => 'Budget spent']]);
                $changed++;
            }
        }

        return $changed;
    }

    public function recordCards(Record $record): array
    {
        $budget = (float) $record->amount;
        $spend = $this->number($record, 'spend');

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Performance', 'icon' => 'gauge', 'stats' => [
            ['label' => 'Budget used', 'value' => $budget > 0 ? round($spend / $budget * 100).'%' : '—', 'tone' => $budget > 0 && $spend >= $budget ? 'danger' : null],
            ['label' => 'Click-through', 'value' => $record->value('_ctr') !== null ? number_format((float) $record->value('_ctr'), 2).'%' : '—'],
            ['label' => 'Cost per click', 'value' => $record->value('_cpc') !== null ? $this->money($record->value('_cpc')) : '—'],
            ['label' => 'Cost per conversion', 'value' => $record->value('_cpa') !== null ? $this->money($record->value('_cpa')) : '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        $running = $this->records('campaigns')->whereIn('status', ['active', 'paused'])->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Budget used', 'icon' => 'wallet', 'empty' => 'No campaigns running.',
            'rows' => $running->sortByDesc(fn (Record $campaign) => (float) $campaign->amount > 0 ? $this->number($campaign, 'spend') / (float) $campaign->amount : 0)
                ->map(fn (Record $campaign) => [
                    'label' => $campaign->title, 'sub' => ucfirst((string) $campaign->value('platform')).' · '.($campaign->value('_paused_reason') ?: $campaign->status),
                    'value' => $this->money($this->number($campaign, 'spend')).' of '.$this->money($campaign->amount), 'href' => $campaign->url(),
                    'tone' => (float) $campaign->amount > 0 && $this->number($campaign, 'spend') >= 0.9 * (float) $campaign->amount ? 'warning' : null,
                ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $campaigns = $this->dated('campaigns', $from, $to)->get();
        $row = function (Collection $group, string $label) {
            $totals = array_map(fn (string $total) => $group->sum(fn (Record $campaign) => $this->number($campaign, $total)), array_combine(self::TOTALS, self::TOTALS));
            $metrics = $this->metrics($totals['impressions'], $totals['clicks'], $totals['conversions'], $totals['spend']);

            return [$label, $group->count(), $this->money($totals['spend']), number_format($totals['impressions']), number_format($totals['clicks']),
                $metrics['_ctr'] !== null ? number_format($metrics['_ctr'], 2).'%' : '—', $metrics['_cpc'] !== null ? $this->money($metrics['_cpc']) : '—',
                number_format($totals['conversions']), $metrics['_cpa'] !== null ? $this->money($metrics['_cpa']) : '—'];
        };
        $columns = ['Campaigns', 'Spend', 'Impressions', 'Clicks', 'Click-through', 'Cost per click', 'Conversions', 'Cost per conversion'];

        return [
            ['title' => 'Results by platform', 'columns' => ['Platform', ...$columns], 'rows' => $campaigns->groupBy(fn (Record $campaign) => ucfirst((string) $campaign->value('platform')))->sortKeys()->map($row)->values()->all()],
            ['title' => 'Results by objective', 'columns' => ['Objective', ...$columns], 'rows' => $campaigns->groupBy(fn (Record $campaign) => ucfirst(str_replace('_', ' ', (string) ($campaign->value('objective') ?: 'none'))))->sortKeys()->map($row)->values()->all()],
        ];
    }
}
