<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Savings groups: members' contributions build up their savings, shares and social fund; a
 * member can borrow up to three times what they have saved; loans add flat monthly interest,
 * settle themselves when repaid and fall into arrears after the due date. Meetings total
 * what was collected on the day.
 */
class SavingsGroupsLogic extends AppLogic
{
    /** How many times their savings and shares a member may borrow. */
    public const LOAN_MULTIPLE = 3;

    public const LIVE_LOANS = ['disbursed', 'in_arrears'];

    public const FUNDS = ['savings' => 'Savings', 'shares' => 'Shares', 'social_fund' => 'Social fund', 'fine' => 'Fines', 'other' => 'Other'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'loans') {
            return [];
        }

        $data = $payload['data'];
        $member = ! empty($data['member']) ? $this->records('members')->find($data['member']) : null;
        $errors = [];
        $approving = in_array($payload['status'], ['approved', 'disbursed'], true) && (! $existing || $existing->status === 'applied');

        if ($member && $approving) {
            if ($member->status !== 'active') {
                $errors['data.member'] = $member->title.' is '.$member->status.' and cannot borrow.';
            } else {
                $limit = $this->borrowingLimit($member, $existing);
                if ((float) ($payload['amount'] ?? 0) > $limit) {
                    $errors['amount'] = $member->title.' can borrow up to '.$this->money($limit).' ('.self::LOAN_MULTIPLE.'× savings and shares, less loans still owing).';
                }
            }
        }

        $total = $this->totalDue((float) ($payload['amount'] ?? 0), (float) ($data['interest_rate'] ?? 0), $payload['occurs_on'] ?? null, $payload['due_on'] ?? null);
        if ((float) ($data['repaid'] ?? 0) - $total > 0.004) {
            $errors['data.repaid'] = 'Repaid is more than the '.$this->money($total).' owed (principal plus interest).';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'members' && $record->exists) {
            $received = $this->linked('contributions', 'member', $record)->where('status', 'received')->get();
            $totals = [];
            foreach (array_keys(self::FUNDS) as $fund) {
                $totals['_'.$fund] = round((float) $received->where('data.type', $fund)->sum('amount'), 2);
            }
            $this->put($record, $totals);
        }

        if ($record->entity === 'loans') {
            $total = $this->totalDue((float) $record->amount, $this->number($record, 'interest_rate'), $record->occurs_on, $record->due_on);
            $this->put($record, ['_total_due' => $total]);
            $repaid = $this->number($record, 'repaid');

            if (in_array($record->status, self::LIVE_LOANS, true) && $total > 0 && $repaid >= $total - 0.004) {
                $record->status = 'repaid';
            } elseif ($record->status === 'disbursed' && $record->due_on && $record->due_on->lt(today())) {
                $record->status = 'in_arrears';
            } elseif ($record->status === 'in_arrears' && $record->due_on && ! $record->due_on->lt(today())) {
                $record->status = 'disbursed';
            }
        }

        if ($record->entity === 'meetings' && $record->occurs_on) {
            $record->amount = round((float) $this->records('contributions')->where('status', 'received')->whereDate('occurs_on', $record->occurs_on)->sum('amount'), 2);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'contributions') {
            $this->recalculate($this->parent($record, 'member'));
            $this->recalculate($this->previousParent($record, 'member'));
            $this->recalculateMeetings($record);
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'contributions') {
            $this->recalculate($this->parent($record, 'member'));
            $this->recalculateMeetings($record);
        }
    }

    protected function recalculateMeetings(Record $contribution): void
    {
        $dates = array_filter([$contribution->occurs_on?->toDateString(), $contribution->getOriginal('occurs_on') ? Carbon::parse($contribution->getOriginal('occurs_on'))->toDateString() : null]);
        foreach (array_unique($dates) as $date) {
            $this->records('meetings')->whereDate('occurs_on', $date)->get()->each(fn (Record $meeting) => $meeting->save());
        }
    }

    /** Principal plus flat monthly interest for each started month of the loan term (at least one). */
    public function totalDue(float $principal, float $monthlyRate, Carbon|string|null $from, Carbon|string|null $to): float
    {
        $months = 1;
        if ($from && $to) {
            $months = max(1, (int) ceil(Carbon::parse($from)->diffInMonths(Carbon::parse($to))));
        }

        return round($principal * (1 + $monthlyRate / 100 * $months), 2);
    }

    /** Savings plus shares times the multiple, less what the member still owes on other loans. */
    public function borrowingLimit(Record $member, ?Record $except = null): float
    {
        $member->refresh();
        $owing = $this->linked('loans', 'member', $member)->whereIn('status', ['approved', ...self::LIVE_LOANS])
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))->get()
            ->sum(fn (Record $loan) => $this->outstanding($loan));

        return max(0, round(($this->number($member, '_savings') + $this->number($member, '_shares')) * self::LOAN_MULTIPLE - $owing, 2));
    }

    public function outstanding(Record $loan): float
    {
        return max(0, round($this->number($loan, '_total_due') - $this->number($loan, 'repaid'), 2));
    }

    public function daily(Workspace $workspace): int
    {
        $late = $this->records('loans')->where('status', 'disbursed')->whereDate('due_on', '<', today())->get();
        $late->each(fn (Record $loan) => $loan->save());

        return $late->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'members') {
            $loans = $this->linked('loans', 'member', $record)->whereIn('status', ['approved', ...self::LIVE_LOANS])->get();
            $stats = [];
            foreach (['savings', 'shares', 'social_fund', 'fine'] as $fund) {
                $stats[] = ['label' => self::FUNDS[$fund], 'value' => $this->money($record->value('_'.$fund))];
            }
            $stats[] = ['label' => 'Loans owing', 'value' => $this->money($loans->sum(fn (Record $loan) => $this->outstanding($loan))), 'tone' => $loans->contains('status', 'in_arrears') ? 'danger' : null];
            $stats[] = ['label' => 'Can still borrow', 'value' => $this->money($this->borrowingLimit($record)), 'tone' => 'success'];

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Member balances', 'icon' => 'piggy-bank', 'stats' => $stats]]];
        }

        if ($record->entity === 'loans') {
            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Loan', 'icon' => 'piggy-bank', 'stats' => [
                ['label' => 'Principal', 'value' => $this->money($record->amount)],
                ['label' => 'Interest', 'value' => $this->money($this->number($record, '_total_due') - (float) $record->amount)],
                ['label' => 'Total to repay', 'value' => $this->money($record->value('_total_due'))],
                ['label' => 'Still owing', 'value' => $this->money($this->outstanding($record)), 'tone' => $record->status === 'in_arrears' ? 'danger' : null],
            ], 'note' => 'Flat interest of '.($this->number($record, 'interest_rate') ?: 0).'% a month on the principal, for each started month to the due date.']]];
        }

        return [];
    }

    public function homeCards(): array
    {
        $contributions = $this->records('contributions')->where('status', 'received')->get();
        $loans = $this->records('loans')->whereIn('status', self::LIVE_LOANS)->get();
        $arrears = $loans->where('status', 'in_arrears');
        $cash = $contributions->sum('amount') - $loans->sum('amount') + $loans->sum(fn (Record $loan) => $this->number($loan, 'repaid'))
            + $this->records('loans')->where('status', 'repaid')->get()->sum(fn (Record $loan) => $this->number($loan, '_total_due') - (float) $loan->amount);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Group fund', 'icon' => 'users-round', 'stats' => [
                ['label' => 'Contributed', 'value' => $this->money($contributions->sum('amount'))],
                ['label' => 'Out on loan', 'value' => $this->money($loans->sum(fn (Record $loan) => $this->outstanding($loan)))],
                ['label' => 'Cash in hand', 'value' => $this->money($cash)],
                ['label' => 'Loans in arrears', 'value' => (string) $arrears->count(), 'tone' => $arrears->isNotEmpty() ? 'danger' : null],
            ], 'note' => 'Cash in hand is contributions plus loan repayments and interest earned, less money lent out.']],
        ];
    }

    public function documents(Record $record): array
    {
        return $record->entity === 'members' ? ['statement' => 'Member statement'] : [];
    }

    public function document(string $name, Record $record): ?array
    {
        if ($name !== 'statement' || $record->entity !== 'members') {
            return null;
        }

        $rows = $this->linked('contributions', 'member', $record)->where('status', 'received')->get()
            ->sortBy(fn (Record $line) => ($line->occurs_on ?? $line->created_at)->timestamp)
            ->map(fn (Record $line) => [($line->occurs_on ?? $line->created_at)->format('d M Y'), $line->number, self::FUNDS[$line->value('type')] ?? $line->value('type'), $this->money($line->amount)])
            ->values()->all();
        $totals = [];
        foreach (self::FUNDS as $fund => $label) {
            if ($this->number($record, '_'.$fund) > 0) {
                $totals[$label] = $this->money($record->value('_'.$fund));
            }
        }
        $totals['Total contributed'] = $this->money(array_sum(array_map(fn (string $fund) => $this->number($record, '_'.$fund), array_keys(self::FUNDS))));

        return ['view' => 'apps.logic.document', 'data' => [
            'heading' => 'Member statement',
            'meta' => array_filter(['Member' => $record->title, 'Member number' => $record->value('member_number'), 'Statement date' => today()->format('d M Y')]),
            'columns' => ['Date', 'Reference', 'Fund', 'Amount'], 'rows' => $rows, 'totals' => $totals,
        ]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $contributions = $this->dated('contributions', $from, $to)->where('status', 'received')->get();
        $byMonth = [];
        foreach ($this->months($from, $to) as $month => $label) {
            $inMonth = $contributions->filter(fn (Record $line) => ($line->occurs_on ?? $line->created_at)->format('Y-m') === $month);
            $byMonth[] = [$label, ...array_map(fn (string $fund) => $this->money($inMonth->where('data.type', $fund)->sum('amount')), ['savings', 'shares', 'social_fund', 'fine']), $this->money($inMonth->sum('amount'))];
        }

        $names = $this->records('members')->pluck('title', 'id');
        $book = $this->records('loans')->whereIn('status', ['approved', ...self::LIVE_LOANS])->get()->map(fn (Record $loan) => [
            $names[$loan->value('member')] ?? '—', $loan->number, $this->money($loan->amount), $this->money($loan->value('_total_due')),
            $this->money($loan->value('repaid')), $this->money($this->outstanding($loan)), $loan->due_on?->format('d M Y') ?? '—', str_replace('_', ' ', $loan->status),
        ])->values()->all();

        return [
            ['title' => 'Contributions by month', 'columns' => ['Month', 'Savings', 'Shares', 'Social fund', 'Fines', 'Total'], 'rows' => $byMonth],
            ['title' => 'Loan book', 'columns' => ['Member', 'Loan', 'Principal', 'Total due', 'Repaid', 'Owing', 'Due', 'Status'], 'rows' => $book],
        ];
    }
}
