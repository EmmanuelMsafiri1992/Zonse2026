<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Insurance premium financing: an agreement finances the premium plus its finance charge,
 * splits it into equal monthly instalments, adds up the instalments received, goes into
 * arrears once a full instalment is behind and completes itself when fully paid.
 */
class PremiumFinancingLogic extends AppLogic
{
    public const LIVE = ['active', 'in_arrears'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'collections' || empty($payload['data']['agreement']) || $payload['status'] !== 'received') {
            return [];
        }

        $agreement = $this->records('agreements')->find($payload['data']['agreement']);
        if (! $agreement) {
            return [];
        }

        $counted = $existing && $existing->status === 'received' && (int) $existing->value('agreement') === $agreement->id ? (float) $existing->amount : 0;
        if (! $counted && ! in_array($agreement->status, self::LIVE, true)) {
            return ['data.agreement' => $agreement->number.' is '.str_replace('_', ' ', $agreement->status).' and takes no more instalments.'];
        }

        $outstanding = $this->outstanding($agreement) + $counted;
        if ((float) ($payload['amount'] ?? 0) - $outstanding > 0.004) {
            return ['amount' => 'Only '.$this->money($outstanding).' is still owed on '.$agreement->number.'.'];
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'agreements') {
            return;
        }

        if ((float) $record->amount <= 0 && $this->number($record, 'premium') > 0) {
            $record->amount = round($this->number($record, 'premium') * (1 + $this->number($record, 'interest_rate') / 100), 2);
        }

        $count = (int) $this->number($record, 'instalments');
        if (! $record->due_on && $record->occurs_on && $count > 0) {
            $record->due_on = $record->occurs_on->copy()->addMonthsNoOverflow($count - 1);
        }

        $instalment = $count > 0 ? round((float) $record->amount / $count, 2) : (float) $record->amount;
        $paid = $record->exists ? round((float) $this->linked('collections', 'agreement', $record)->where('status', 'received')->sum('amount'), 2) : 0;
        $this->put($record, ['_instalment' => $instalment, '_paid' => $paid, '_arrears' => $this->arrears($record, $instalment, $paid)]);

        if (! in_array($record->status, [...self::LIVE, 'completed'], true)) {
            return;
        }

        if ((float) $record->amount > 0 && $paid >= (float) $record->amount - 0.004) {
            $record->status = 'completed';
        } elseif ($instalment > 0 && $this->number($record, '_arrears') >= $instalment - 0.004) {
            $record->status = 'in_arrears';
        } else {
            $record->status = 'active';
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'collections') {
            $this->recalculate($this->parent($record, 'agreement'));
            $this->recalculate($this->previousParent($record, 'agreement'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'collections') {
            $this->recalculate($this->parent($record, 'agreement'));
        }
    }

    /** Instalments due by today (the first on the start date, then one a month) less what was received. */
    public function arrears(Record $agreement, float $instalment, float $paid): float
    {
        if (! $agreement->occurs_on || $instalment <= 0 || $agreement->occurs_on->gt(today())) {
            return 0;
        }

        $due = (int) $agreement->occurs_on->diffInMonths(today()) + 1;
        $count = (int) $this->number($agreement, 'instalments');
        if ($count > 0) {
            $due = min($due, $count);
        }

        return max(0, round(min((float) $agreement->amount, $due * $instalment) - $paid, 2));
    }

    public function outstanding(Record $agreement): float
    {
        return max(0, round((float) $agreement->amount - $this->number($agreement, '_paid'), 2));
    }

    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        foreach ($this->records('agreements')->whereIn('status', self::LIVE)->get() as $agreement) {
            $before = $agreement->status;
            $agreement->save();
            $changed += $agreement->status !== $before ? 1 : 0;
        }

        return $changed;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'agreements') {
            return [];
        }

        $arrears = $this->number($record, '_arrears');
        $finance = (float) $record->amount - $this->number($record, 'premium');

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Finance account', 'icon' => 'umbrella', 'stats' => [
            ['label' => 'Amount financed', 'value' => $this->money($record->amount)],
            ['label' => 'Instalment', 'value' => $this->money($record->value('_instalment'))],
            ['label' => 'Received', 'value' => $this->money($record->value('_paid')), 'tone' => 'success'],
            ['label' => 'Still owed', 'value' => $this->money($this->outstanding($record))],
            ['label' => 'In arrears', 'value' => $this->money($arrears), 'tone' => $arrears > 0 ? 'danger' : null],
        ], 'note' => $finance > 0 ? 'Includes a finance charge of '.$this->money($finance).' on the '.$this->money($record->value('premium')).' premium.' : null]]];
    }

    public function homeCards(): array
    {
        $behind = $this->records('agreements')->where('status', 'in_arrears')->get()->sortByDesc(fn (Record $agreement) => $this->number($agreement, '_arrears'));

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Agreements in arrears', 'icon' => 'umbrella', 'empty' => 'No agreements are behind.',
            'rows' => $behind->take(10)->map(fn (Record $agreement) => [
                'label' => $agreement->contact?->name ?? $agreement->title, 'sub' => $agreement->number.' · '.$agreement->value('insurer'),
                'value' => $this->money($agreement->value('_arrears')), 'href' => $agreement->url(), 'tone' => 'danger',
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $collections = $this->dated('collections', $from, $to)->get();
        $received = $this->sumByMonth($collections->where('status', 'received'));
        $byMonth = [];
        foreach ($this->months($from, $to) as $month => $label) {
            $byMonth[] = [$label, $this->money($received[$month] ?? 0), $collections->where('status', 'missed')->filter(fn (Record $line) => ($line->occurs_on ?? $line->created_at)->format('Y-m') === $month)->count()];
        }

        $book = $this->records('agreements')->whereIn('status', self::LIVE)->get()->groupBy(fn (Record $agreement) => (string) $agreement->value('insurer'))->sortKeys()
            ->map(fn ($group, $insurer) => [$insurer, $group->count(), $this->money($group->sum(fn (Record $agreement) => $this->outstanding($agreement))), $this->money($group->sum(fn (Record $agreement) => $this->number($agreement, '_arrears')))])
            ->values()->all();

        return [
            ['title' => 'Instalments collected', 'columns' => ['Month', 'Received', 'Missed'], 'rows' => $byMonth],
            ['title' => 'Book by insurer', 'columns' => ['Insurer', 'Live agreements', 'Outstanding', 'In arrears'], 'rows' => $book],
        ];
    }
}
