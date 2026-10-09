<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Investment clubs and crowdfunding: an investor's total invested and a venture's amount
 * raised are added up from received contributions, a raising venture becomes funded when it
 * reaches its target and takes nothing past it, and a paid distribution is shared among the
 * venture's investors in proportion to what each put in.
 */
class InvestmentClubsLogic extends AppLogic
{
    public const OPEN = ['proposed', 'raising'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];

        if ($entity->key === 'contributions' && $payload['status'] === 'received') {
            $investor = ! empty($data['investor']) ? $this->records('members')->find($data['investor']) : null;
            if ($investor && $investor->status !== 'active' && (! $existing || (int) $existing->value('investor') !== $investor->id)) {
                return ['data.investor' => $investor->title.' has exited the club.'];
            }

            $venture = ! empty($data['venture']) ? $this->records('ventures')->find($data['venture']) : null;
            if (! $venture) {
                return [];
            }
            $counted = $existing && $existing->status === 'received' && (int) $existing->value('venture') === $venture->id ? (float) $existing->amount : 0;
            if (! $counted && ! in_array($venture->status, self::OPEN, true)) {
                return ['data.venture' => $venture->title.' is '.$venture->status.' and is not taking money.'];
            }
            $target = $this->number($venture, 'target');
            $room = round($target - $this->raised($venture) + $counted, 2);
            if ($target > 0 && (float) ($payload['amount'] ?? 0) - $room > 0.004) {
                return ['amount' => 'Only '.$this->money(max(0, $room)).' is still needed to reach the target.'];
            }
        }

        if ($entity->key === 'distributions' && $payload['status'] === 'paid' && ! empty($data['venture']) && ! $this->linked('contributions', 'venture', (int) $data['venture'])->where('status', 'received')->exists()) {
            return ['data.venture' => 'Nobody has invested in this venture, so there is no one to pay.'];
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'members' && $record->exists) {
            $record->amount = round((float) $this->linked('contributions', 'investor', $record)->where('status', 'received')->sum('amount'), 2);
        }

        if ($record->entity === 'ventures') {
            if ($record->exists) {
                $this->put($record, ['raised' => $this->raised($record)]);
            }
            $target = $this->number($record, 'target');
            if ($record->status === 'raising' && $target > 0 && $this->number($record, 'raised') >= $target - 0.004) {
                $record->status = 'funded';
            }
        }

        if ($record->entity === 'distributions' && $record->status === 'paid' && ! $record->value('_allocations')) {
            $this->put($record, ['_allocations' => $this->allocate($record)]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'contributions') {
            $this->recalculate($this->parent($record, 'investor'));
            $this->recalculate($this->previousParent($record, 'investor'));
            $this->recalculate($this->parent($record, 'venture'));
            $this->recalculate($this->previousParent($record, 'venture'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'contributions') {
            $this->recalculate($this->parent($record, 'investor'));
            $this->recalculate($this->parent($record, 'venture'));
        }
    }

    public function raised(Record $venture): float
    {
        return round((float) $this->linked('contributions', 'venture', $venture)->where('status', 'received')->sum('amount'), 2);
    }

    /** Share a distribution among the venture's investors pro rata; the last takes the rounding. @return array<int, float> investor id => amount */
    public function allocate(Record $distribution): array
    {
        $stakes = $this->linked('contributions', 'venture', (int) $distribution->value('venture'))->where('status', 'received')->get()
            ->groupBy(fn (Record $contribution) => (int) $contribution->value('investor'))->map(fn ($group) => (float) $group->sum('amount'))->sortKeys();
        $total = $stakes->sum();
        if ($total <= 0) {
            return [];
        }

        $allocations = [];
        $left = (float) $distribution->amount;
        foreach ($stakes as $investor => $stake) {
            $allocations[$investor] = $investor === $stakes->keys()->last() ? round($left, 2) : round((float) $distribution->amount * $stake / $total, 2);
            $left -= $allocations[$investor];
        }

        return $allocations;
    }

    /** What an investor has received from paid distributions. */
    public function returnsFor(Record $investor): float
    {
        return round($this->records('distributions')->where('status', 'paid')->get()
            ->sum(fn (Record $distribution) => (float) (((array) $distribution->value('_allocations'))[$investor->id] ?? 0)), 2);
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'members') {
            $returns = $this->returnsFor($record);
            $ventures = $this->records('ventures')->pluck('title', 'id');
            $stakes = $this->linked('contributions', 'investor', $record)->where('status', 'received')->get()->groupBy(fn (Record $contribution) => (int) $contribution->value('venture'));

            return [
                ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Investor', 'icon' => 'hand-coins', 'stats' => [
                    ['label' => 'Invested', 'value' => $this->money($record->amount)],
                    ['label' => 'Returns received', 'value' => $this->money($returns), 'tone' => 'success'],
                    ['label' => 'Return on investment', 'value' => (float) $record->amount > 0 ? round($returns / (float) $record->amount * 100, 1).'%' : '—'],
                ]]],
                ['view' => 'apps.logic.list-card', 'data' => [
                    'title' => 'Holdings', 'icon' => 'rocket', 'empty' => 'No contributions yet.',
                    'rows' => $stakes->map(fn ($group, $venture) => ['label' => $ventures[$venture] ?? 'Club pool', 'value' => $this->money($group->sum('amount'))])->values()->all(),
                ]],
            ];
        }

        if ($record->entity === 'ventures') {
            $target = $this->number($record, 'target');

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Funding', 'icon' => 'rocket', 'stats' => [
                ['label' => 'Raised', 'value' => $this->money($record->value('raised'))],
                ['label' => 'Target', 'value' => $target > 0 ? $this->money($target) : 'Not set'],
                ['label' => 'Funded', 'value' => $target > 0 ? round($this->number($record, 'raised') / $target * 100).'%' : '—', 'tone' => $target > 0 && $this->number($record, 'raised') >= $target ? 'success' : null],
                ['label' => 'Investors', 'value' => (string) $this->linked('contributions', 'venture', $record)->where('status', 'received')->get()->pluck('data.investor')->unique()->count()],
            ]]]];
        }

        if ($record->entity === 'distributions' && $record->value('_allocations')) {
            $names = $this->records('members')->pluck('title', 'id');

            return [['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Paid to investors', 'icon' => 'hand-coins', 'empty' => '',
                'rows' => collect((array) $record->value('_allocations'))->map(fn ($amount, $investor) => ['label' => $names[$investor] ?? '—', 'value' => $this->money($amount)])->values()->all(),
            ]]];
        }

        return [];
    }

    public function homeCards(): array
    {
        $raising = $this->records('ventures')->whereIn('status', self::OPEN)->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Ventures raising money', 'icon' => 'rocket', 'empty' => 'No ventures are raising money.',
            'rows' => $raising->map(fn (Record $venture) => [
                'label' => $venture->title, 'sub' => $venture->due_on ? 'Closes '.$venture->due_on->format('d M Y') : null,
                'value' => $this->money($venture->value('raised')).($this->number($venture, 'target') > 0 ? ' of '.$this->money($venture->value('target')) : ''), 'href' => $venture->url(),
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $paid = $this->records('distributions')->where('status', 'paid')->get();
        $ventures = $this->records('ventures')->orderBy('title')->get()->map(function (Record $venture) use ($paid) {
            $returned = $paid->where('data.venture', $venture->id)->sum('amount');

            return [$venture->title, ucfirst($venture->status), $this->money($venture->value('target')), $this->money($venture->value('raised')), $this->money($returned),
                $this->number($venture, 'raised') > 0 ? round($returned / $this->number($venture, 'raised') * 100, 1).'%' : '—'];
        })->all();

        $investors = $this->records('members')->orderBy('title')->get()->map(fn (Record $investor) => [$investor->title, $this->money($investor->amount), $this->money($this->returnsFor($investor))])->all();

        return [
            ['title' => 'Ventures', 'columns' => ['Venture', 'Stage', 'Target', 'Raised', 'Returned', 'Return'], 'rows' => $ventures],
            ['title' => 'Investors', 'columns' => ['Investor', 'Invested', 'Returns received'], 'rows' => $investors],
        ];
    }
}
