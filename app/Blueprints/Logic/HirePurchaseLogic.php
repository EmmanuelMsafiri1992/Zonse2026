<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Hire purchase: an agreement splits what is left after the deposit into equal instalments,
 * adds up the deposit and received instalments as paid to date, tracks arrears against one
 * instalment a month from the start date, completes itself when fully paid and is marked
 * defaulted once three instalments behind.
 */
class HirePurchaseLogic extends AppLogic
{
    /** Instalments behind before an agreement is marked defaulted. */
    public const DEFAULT_AFTER = 3;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];

        if ($entity->key === 'agreements' && (float) ($data['deposit'] ?? 0) > (float) ($payload['amount'] ?? 0)) {
            return ['data.deposit' => 'The deposit cannot be more than the total price.'];
        }

        if ($entity->key === 'payments' && ! empty($data['agreement']) && $payload['status'] === 'received') {
            $agreement = $this->records('agreements')->find($data['agreement']);
            if (! $agreement) {
                return [];
            }
            $counted = $existing && $existing->status === 'received' && (int) $existing->value('agreement') === $agreement->id ? (float) $existing->amount : 0;
            if (! $counted && $agreement->status !== 'active') {
                return ['data.agreement' => $agreement->number.' is '.$agreement->status.' and takes no more payments.'];
            }
            $outstanding = $this->outstanding($agreement) + $counted;
            if ((float) ($payload['amount'] ?? 0) - $outstanding > 0.004) {
                return ['amount' => 'Only '.$this->money($outstanding).' is still owed on '.$agreement->number.'.'];
            }
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'agreements') {
            return;
        }

        $count = (int) $this->number($record, 'instalments');
        if (($record->value('instalment') === null || $record->value('instalment') === '') && $count > 0) {
            $this->put($record, ['instalment' => round(((float) $record->amount - $this->number($record, 'deposit')) / $count, 2)]);
        }

        $received = $record->exists ? (float) $this->linked('payments', 'agreement', $record)->where('status', 'received')->sum('amount') : 0;
        $paid = round($this->number($record, 'deposit') + $received, 2);
        $this->put($record, ['paid_to_date' => $paid, '_arrears' => $this->arrears($record, $paid)]);

        if ($record->status === 'active' && (float) $record->amount > 0 && $paid >= (float) $record->amount - 0.004) {
            $record->status = 'completed';
        } elseif ($record->status === 'completed' && $paid < (float) $record->amount - 0.004) {
            $record->status = 'active';
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'payments') {
            $this->recalculate($this->parent($record, 'agreement'));
            $this->recalculate($this->previousParent($record, 'agreement'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'payments') {
            $this->recalculate($this->parent($record, 'agreement'));
        }
    }

    /** What should have been paid by now (deposit plus one instalment per elapsed month) less what was paid. */
    public function arrears(Record $agreement, float $paid): float
    {
        $instalment = $this->number($agreement, 'instalment');
        if (! $agreement->occurs_on || $instalment <= 0) {
            return 0;
        }

        $months = $agreement->occurs_on->gt(today()) ? 0 : (int) $agreement->occurs_on->diffInMonths(today());
        $count = (int) $this->number($agreement, 'instalments');
        if ($count > 0) {
            $months = min($months, $count);
        }
        $expected = min((float) $agreement->amount, $this->number($agreement, 'deposit') + $months * $instalment);

        return max(0, round($expected - $paid, 2));
    }

    public function outstanding(Record $agreement): float
    {
        return max(0, round((float) $agreement->amount - $this->number($agreement, 'paid_to_date'), 2));
    }

    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        foreach ($this->records('agreements')->where('status', 'active')->get() as $agreement) {
            $agreement->save();
            $instalment = $this->number($agreement, 'instalment');
            if ($agreement->status === 'active' && $instalment > 0 && $this->number($agreement, '_arrears') >= $instalment * self::DEFAULT_AFTER - 0.004) {
                $agreement->update(['status' => 'defaulted']);
                $changed++;
            }
        }

        return $changed;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'agreements') {
            return [];
        }

        $arrears = $this->number($record, '_arrears');

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Account', 'icon' => 'calendar-clock', 'stats' => [
            ['label' => 'Total price', 'value' => $this->money($record->amount)],
            ['label' => 'Paid to date', 'value' => $this->money($record->value('paid_to_date')), 'tone' => 'success'],
            ['label' => 'Still owed', 'value' => $this->money($this->outstanding($record))],
            ['label' => 'In arrears', 'value' => $this->money($arrears), 'tone' => $arrears > 0 ? 'danger' : null],
        ], 'note' => $this->number($record, 'instalment') > 0 ? $this->money($record->value('instalment')).' a month. Marked defaulted once '.self::DEFAULT_AFTER.' instalments behind.' : null]]];
    }

    public function homeCards(): array
    {
        $behind = $this->records('agreements')->where('status', 'active')->get()->filter(fn (Record $agreement) => $this->number($agreement, '_arrears') > 0)
            ->sortByDesc(fn (Record $agreement) => $this->number($agreement, '_arrears'));

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Agreements in arrears', 'icon' => 'alert-triangle', 'empty' => 'Every active agreement is up to date.',
            'rows' => $behind->take(10)->map(fn (Record $agreement) => [
                'label' => $agreement->title, 'sub' => $agreement->number, 'value' => $this->money($agreement->value('_arrears')), 'href' => $agreement->url(), 'tone' => 'danger',
            ])->values()->all(),
        ]]];
    }

    public function documents(Record $record): array
    {
        return $record->entity === 'agreements' ? ['statement' => 'Statement'] : [];
    }

    public function document(string $name, Record $record): ?array
    {
        if ($name !== 'statement' || $record->entity !== 'agreements') {
            return null;
        }

        $rows = [];
        if ($this->number($record, 'deposit') > 0) {
            $rows[] = [($record->occurs_on ?? $record->created_at)->format('d M Y'), 'Deposit', '', $this->money($record->value('deposit'))];
        }
        foreach ($this->linked('payments', 'agreement', $record)->where('status', 'received')->get()->sortBy(fn (Record $line) => ($line->occurs_on ?? $line->created_at)->timestamp) as $line) {
            $rows[] = [($line->occurs_on ?? $line->created_at)->format('d M Y'), $line->number, str_replace('_', ' ', (string) $line->value('method')), $this->money($line->amount)];
        }

        return ['view' => 'apps.logic.document', 'data' => [
            'heading' => 'Account statement',
            'meta' => array_filter(['Agreement' => $record->number, 'Goods' => $record->title, 'Customer' => $record->contact?->name, 'Statement date' => today()->format('d M Y')]),
            'columns' => ['Date', 'Reference', 'Method', 'Amount'], 'rows' => $rows,
            'totals' => ['Total price' => $this->money($record->amount), 'Paid to date' => $this->money($record->value('paid_to_date')), 'Balance' => $this->money($this->outstanding($record))],
        ]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $book = $this->records('agreements')->whereIn('status', ['active', 'defaulted'])->get()->map(fn (Record $agreement) => [
            $agreement->number, $agreement->title, $this->money($agreement->amount), $this->money($agreement->value('paid_to_date')),
            $this->money($this->outstanding($agreement)), $this->money($agreement->value('_arrears')), ucfirst($agreement->status),
        ])->values()->all();

        $payments = $this->dated('payments', $from, $to)->where('status', 'received')->get();
        $byMonth = [];
        foreach ($this->months($from, $to) as $month => $label) {
            $inMonth = $payments->filter(fn (Record $line) => ($line->occurs_on ?? $line->created_at)->format('Y-m') === $month);
            $byMonth[] = [$label, $inMonth->count(), $this->money($inMonth->sum('amount'))];
        }

        return [
            ['title' => 'Agreement book', 'columns' => ['Agreement', 'Goods', 'Total', 'Paid', 'Owed', 'Arrears', 'Status'], 'rows' => $book],
            ['title' => 'Collections by month', 'columns' => ['Month', 'Payments', 'Collected'], 'rows' => $byMonth],
        ];
    }
}
