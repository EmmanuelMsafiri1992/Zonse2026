<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * CRM pipeline: each stage carries a default win probability, won and lost deals are fixed at
 * 100% and 0%, a lost deal needs its reason, and every deal keeps a weighted value so the
 * home screen and reports can forecast what the pipeline is really worth.
 */
class CrmLogic extends AppLogic
{
    public const STAGE_PROBABILITY = ['lead' => 10, 'qualified' => 25, 'proposal' => 50, 'negotiation' => 75, 'won' => 100, 'lost' => 0];

    public const OPEN = ['lead', 'qualified', 'proposal', 'negotiation'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $errors = [];
        $probability = $payload['data']['probability'] ?? null;

        if (filled($probability) && ((float) $probability < 0 || (float) $probability > 100)) {
            $errors['data.probability'] = 'Probability is a percentage between 0 and 100.';
        }
        if ($payload['status'] === 'lost' && blank($payload['data']['lost_reason'] ?? null)) {
            $errors['data.lost_reason'] = 'Say why the deal was lost.';
        }
        if ($payload['status'] === 'won' && (float) ($payload['amount'] ?? 0) <= 0) {
            $errors['amount'] = 'A won deal needs its value.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $stageChanged = ! $record->exists || $record->isDirty('status');
        if (in_array($record->status, ['won', 'lost'], true) || ($stageChanged && $this->stageDefaultApplies($record))) {
            $this->put($record, ['probability' => self::STAGE_PROBABILITY[$record->status] ?? 0]);
        }

        $closed = in_array($record->status, ['won', 'lost'], true);
        $this->put($record, [
            '_weighted' => round((float) $record->amount * $this->number($record, 'probability') / 100, 2),
            '_closed_on' => $closed ? ($record->value('_closed_on') && ! $record->isDirty('status') ? $record->value('_closed_on') : today()->toDateString()) : null,
        ]);
    }

    /** A new deal, or one whose probability is blank or still the old stage's default, takes the new stage's default. */
    protected function stageDefaultApplies(Record $record): bool
    {
        $probability = $record->value('probability');
        if (blank($probability)) {
            return true;
        }

        return $record->exists && (float) $probability === (float) (self::STAGE_PROBABILITY[$record->getOriginal('status')] ?? -1);
    }

    public function actions(Record $record): array
    {
        if (! in_array($record->status, self::OPEN, true)) {
            return [];
        }

        return [
            'won' => ['label' => 'Mark won', 'icon' => 'trophy', 'confirm' => 'Mark '.$record->title.' as won?'],
            'lost' => ['label' => 'Mark lost', 'icon' => 'circle-x', 'fields' => [['name' => 'lost_reason', 'label' => 'Why was it lost?', 'type' => 'text']]],
        ];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'won') {
            if ((float) $record->amount <= 0) {
                return 'Enter the deal value before marking it won.';
            }
            $record->update(['status' => 'won']);

            return $record->title.' is won.';
        }

        $validated = $request->validate(['lost_reason' => ['required', 'string', 'max:255']]);
        $record->update(['status' => 'lost', 'data' => [...(array) $record->data, 'lost_reason' => $validated['lost_reason']]]);

        return $record->title.' is marked lost.';
    }

    public function homeCards(): array
    {
        $open = $this->records('deals')->whereIn('status', self::OPEN)->get();
        $closedThisMonth = $this->records('deals')->whereIn('status', ['won', 'lost'])->get()
            ->filter(fn (Record $deal) => $deal->value('_closed_on') && Carbon::parse($deal->value('_closed_on'))->isSameMonth(today()));
        $won = $closedThisMonth->where('status', 'won');
        $chase = $open->filter(fn (Record $deal) => $deal->due_on && $deal->due_on->lte(today()->addDays(7)))->sortBy('due_on');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Pipeline', 'icon' => 'target', 'stats' => [
                ['label' => 'Open deals', 'value' => $open->count().' · '.$this->money($open->sum('amount'))],
                ['label' => 'Weighted forecast', 'value' => $this->money($open->sum(fn (Record $deal) => $this->number($deal, '_weighted')))],
                ['label' => 'Won this month', 'value' => $this->money($won->sum('amount')), 'tone' => $won->isNotEmpty() ? 'success' : null],
                ['label' => 'Win rate this month', 'value' => $closedThisMonth->isNotEmpty() ? round($won->count() / $closedThisMonth->count() * 100).'%' : '—'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Deals to chase', 'icon' => 'phone-call', 'empty' => 'No deals due to close this week.',
                'rows' => $chase->take(15)->map(fn (Record $deal) => [
                    'label' => $deal->title, 'sub' => $deal->value('next_step') ?: ucfirst($deal->status), 'value' => $deal->due_on->format('d M'),
                    'href' => $deal->url(), 'tone' => $deal->due_on->lt(today()) ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $deals = $this->records('deals')->get();
        $stages = collect(self::STAGE_PROBABILITY)->keys()->map(function (string $stage) use ($deals) {
            $inStage = $deals->where('status', $stage);

            return [ucfirst($stage), $inStage->count(), $this->money($inStage->sum('amount')), $this->money($inStage->sum(fn (Record $deal) => $this->number($deal, '_weighted')))];
        })->all();

        $closed = $deals->whereIn('status', ['won', 'lost'])
            ->filter(fn (Record $deal) => $deal->value('_closed_on') && Carbon::parse($deal->value('_closed_on'))->betweenIncluded($from->copy()->startOfDay(), $to->copy()->endOfDay()));
        $sources = $closed->groupBy(fn (Record $deal) => (string) ($deal->value('source') ?: 'not given'))->sortKeys()->map(fn ($group, $source) => [
            ucfirst(str_replace('_', ' ', $source)), $group->where('status', 'won')->count(), $group->where('status', 'lost')->count(),
            round($group->where('status', 'won')->count() / $group->count() * 100).'%', $this->money($group->where('status', 'won')->sum('amount')),
        ])->values()->all();

        $reasons = $closed->where('status', 'lost')->groupBy(fn (Record $deal) => (string) $deal->value('lost_reason'))->map(fn ($group, $reason) => [$reason, $group->count(), $this->money($group->sum('amount'))])
            ->sortByDesc(fn ($row) => $row[1])->values()->all();

        return [
            ['title' => 'Pipeline by stage', 'columns' => ['Stage', 'Deals', 'Value', 'Weighted'], 'rows' => $stages],
            ['title' => 'Win rate by source', 'columns' => ['Source', 'Won', 'Lost', 'Win rate', 'Won value'], 'rows' => $sources],
            ['title' => 'Why deals were lost', 'columns' => ['Reason', 'Deals', 'Value'], 'rows' => $reasons],
        ];
    }
}
