<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Pawnshop: a loan is capped at a share of the item's valuation, interest runs per started
 * month, payments go to interest first and then the loan, a pledge is redeemed once nothing
 * is owed, can be extended for another term when its interest is paid up, is forfeited a
 * grace period after its redeem-by date, and only a forfeited item can be sold.
 */
class PawnshopLogic extends AppLogic
{
    public const LOAN_TO_VALUE = 0.7;

    public const TERM_DAYS = 30;

    public const GRACE_DAYS = 7;

    public const OPEN = ['pledged', 'extended'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];

        if ($entity->key === 'pledges') {
            $cap = round((float) ($data['valuation'] ?? 0) * self::LOAN_TO_VALUE, 2);
            if ((float) ($payload['amount'] ?? 0) - $cap > 0.004) {
                return ['amount' => 'The loan can be at most '.(self::LOAN_TO_VALUE * 100).'% of the valuation ('.$this->money($cap).').'];
            }
            if ($payload['status'] === 'sold' && (! $existing || ! in_array($existing->status, ['forfeited', 'sold'], true))) {
                return ['status' => 'Only a forfeited item can be sold.'];
            }
            if ($payload['status'] === 'redeemed' && $existing && $existing->status !== 'redeemed' && $this->owed($existing) > 0.004) {
                return ['status' => $this->money($this->owed($existing)).' is still owed. Take the redemption payment first.'];
            }

            return [];
        }

        if ($entity->key !== 'payments' || empty($data['pledge']) || $payload['status'] !== 'received') {
            return [];
        }

        $pledge = $this->records('pledges')->find($data['pledge']);
        if (! $pledge) {
            return [];
        }

        $counted = $existing && $existing->status === 'received' && (int) $existing->value('pledge') === $pledge->id ? (float) $existing->amount : 0;
        if (! $counted && ! in_array($pledge->status, self::OPEN, true)) {
            return ['data.pledge' => $pledge->number.' is '.$pledge->status.' and takes no more payments.'];
        }

        $owed = round($this->owed($pledge) + $counted, 2);
        $amount = (float) ($payload['amount'] ?? 0);
        if ($amount - $owed > 0.004) {
            return ['amount' => 'Only '.$this->money($owed).' is owed on '.$pledge->number.'.'];
        }
        if (($data['type'] ?? null) === 'redemption' && $owed - $amount > 0.004) {
            return ['amount' => 'Redeeming '.$pledge->number.' takes the full '.$this->money($owed).'.'];
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'pledges') {
            return;
        }

        if (! $record->due_on && $record->occurs_on) {
            $record->due_on = $record->occurs_on->copy()->addDays(self::TERM_DAYS);
        }

        $payments = $record->exists ? $this->linked('payments', 'pledge', $record)->where('status', 'received')->get() : collect();
        $closed = $record->value('_closed_on') ? Carbon::parse($record->value('_closed_on')) : today();
        $interest = round((float) $record->amount * $this->number($record, 'interest_rate') / 100 * $this->monthsRunning($record, $closed), 2);

        $interestPaid = (float) $payments->where('data.type', 'interest')->sum('amount');
        $principalPaid = (float) $payments->where('data.type', 'part_payment')->sum('amount');
        $redemption = (float) $payments->where('data.type', 'redemption')->sum('amount');
        $toInterest = min($redemption, max(0, $interest - $interestPaid));
        $interestPaid += $toInterest;
        $principalPaid += $redemption - $toInterest;

        $owed = max(0, round((float) $record->amount - $principalPaid, 2)) + max(0, round($interest - $interestPaid, 2));
        $this->put($record, ['_interest' => $interest, '_interest_paid' => round($interestPaid, 2), '_principal_paid' => round($principalPaid, 2), '_owed' => round($owed, 2)]);

        if (in_array($record->status, self::OPEN, true) && $payments->isNotEmpty() && $owed <= 0.004) {
            $record->status = 'redeemed';
        }
        if ($record->status === 'redeemed' && ! $record->value('_closed_on')) {
            $this->put($record, ['_closed_on' => today()->toDateString()]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'payments') {
            $this->recalculate($this->parent($record, 'pledge'));
            $this->recalculate($this->previousParent($record, 'pledge'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'payments') {
            $this->recalculate($this->parent($record, 'pledge'));
        }
    }

    /** Months started since the item was pledged; interest is charged for at least one. */
    public function monthsRunning(Record $pledge, Carbon $upTo): int
    {
        if (! $pledge->occurs_on) {
            return 1;
        }

        $days = $pledge->occurs_on->gt($upTo) ? 0 : (int) $pledge->occurs_on->diffInDays($upTo);

        return max(1, (int) ceil($days / self::TERM_DAYS));
    }

    public function owed(Record $pledge): float
    {
        return $this->number($pledge, '_owed');
    }

    public function interestOutstanding(Record $pledge): float
    {
        return max(0, round($this->number($pledge, '_interest') - $this->number($pledge, '_interest_paid'), 2));
    }

    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        foreach ($this->records('pledges')->whereIn('status', self::OPEN)->get() as $pledge) {
            if ($pledge->due_on && $pledge->due_on->copy()->addDays(self::GRACE_DAYS)->lt(today())) {
                $pledge->status = 'forfeited';
                $changed++;
            }
            $pledge->save();
        }

        return $changed;
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'pledges' && in_array($record->status, self::OPEN, true) && $record->due_on) {
            return ['extend' => ['label' => 'Extend '.self::TERM_DAYS.' days', 'icon' => 'calendar-plus', 'confirm' => 'Move the redeem-by date to '.$record->due_on->copy()->addDays(self::TERM_DAYS)->format('d M Y').'?']];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $record->save();
        if ($this->interestOutstanding($record) > 0.004) {
            return 'Collect the '.$this->money($this->interestOutstanding($record)).' interest owed before extending.';
        }

        $record->update(['status' => 'extended', 'due_on' => $record->due_on->copy()->addDays(self::TERM_DAYS)]);

        return 'Extended to '.$record->due_on->format('d M Y').'.';
    }

    public function documents(Record $record): array
    {
        return $record->entity === 'pledges' ? ['ticket' => 'Pawn ticket'] : [];
    }

    public function document(string $name, Record $record): ?array
    {
        if ($name !== 'ticket' || $record->entity !== 'pledges') {
            return null;
        }

        $rate = $this->number($record, 'interest_rate');

        return ['view' => 'apps.logic.document', 'data' => [
            'heading' => 'Pawn ticket '.$record->number,
            'meta' => array_filter(['Customer' => $record->contact?->name, 'ID number' => $record->value('id_number'), 'Pledged on' => $record->occurs_on?->format('d M Y'), 'Redeem by' => $record->due_on?->format('d M Y')]),
            'columns' => ['Item', 'Category', 'Serial / IMEI', 'Valuation'],
            'rows' => [[$record->title, ucfirst((string) $record->value('category')), (string) ($record->value('serial_number') ?? '—'), $this->money($record->value('valuation'))]],
            'totals' => ['Interest per month' => $rate.'% ('.$this->money((float) $record->amount * $rate / 100).')', 'Loan amount' => $this->money($record->amount)],
            'notes' => 'Interest is charged for every started '.self::TERM_DAYS.'-day period. Items not redeemed or extended within '.self::GRACE_DAYS.' days of the redeem-by date are forfeited.',
        ]];
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'pledges') {
            return [];
        }

        $late = in_array($record->status, self::OPEN, true) && $record->due_on && $record->due_on->lt(today());

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Loan', 'icon' => 'gem', 'stats' => [
            ['label' => 'Loan', 'value' => $this->money($record->amount)],
            ['label' => 'Interest charged', 'value' => $this->money($record->value('_interest'))],
            ['label' => 'Paid', 'value' => $this->money($this->number($record, '_interest_paid') + $this->number($record, '_principal_paid')), 'tone' => 'success'],
            ['label' => 'To redeem', 'value' => $this->money($this->owed($record)), 'tone' => $late ? 'danger' : null],
        ], 'note' => $late ? 'Past the redeem-by date. Forfeited after '.$record->due_on->copy()->addDays(self::GRACE_DAYS)->format('d M Y').'.' : null]]];
    }

    public function homeCards(): array
    {
        $due = $this->records('pledges')->whereIn('status', self::OPEN)->whereDate('due_on', '<=', today()->addDays(7))->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Due for redemption', 'icon' => 'gem', 'empty' => 'Nothing due this week.',
            'rows' => $due->take(15)->map(fn (Record $pledge) => [
                'label' => $pledge->title, 'sub' => $pledge->number.' · '.($pledge->contact?->name ?? '—'),
                'value' => $pledge->due_on->format('d M'), 'href' => $pledge->url(), 'tone' => $pledge->due_on->lt(today()) ? 'danger' : null,
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $pledges = $this->dated('pledges', $from, $to)->get();
        $byCategory = $pledges->groupBy(fn (Record $pledge) => (string) $pledge->value('category'))->sortKeys()->map(fn ($group, $category) => [
            ucfirst($category), $group->count(), $this->money($group->sum('amount')), $group->where('status', 'redeemed')->count(), $group->whereIn('status', ['forfeited', 'sold'])->count(),
        ])->values()->all();

        $payments = $this->dated('payments', $from, $to)->where('status', 'received')->get();
        $income = collect(['interest' => 'Interest', 'part_payment' => 'Part payments', 'redemption' => 'Redemptions'])
            ->map(fn ($label, $type) => [$label, $payments->where('data.type', $type)->count(), $this->money($payments->where('data.type', $type)->sum('amount'))])->values()->all();

        return [
            ['title' => 'Pledges by category', 'columns' => ['Category', 'Pledges', 'Lent', 'Redeemed', 'Forfeited / sold'], 'rows' => $byCategory],
            ['title' => 'Payments received', 'columns' => ['Type', 'Payments', 'Amount'], 'rows' => $income],
        ];
    }
}
