<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Overtime: a claim is for hours already worked, one per employee per day, and is approved before it
 * is paid. An employee gets one live allowance of each type, and allowances end on their end date each
 * morning. A loan or advance is repaid by monthly deductions that bring its balance down to nothing;
 * nobody takes a new one while they still owe on an earlier one.
 */
class OvertimeLogic extends AppLogic
{
    /**
     * Advance statuses that still have money owing (or about to be).
     *
     * @var list<string>
     */
    public const OWING = ['requested', 'approved', 'repaying'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $employee = $data['employee'] ?? null;
        $errors = [];
        if ((float) ($payload['amount'] ?? 0) < 0) {
            $errors['amount'] = 'The amount cannot be negative.';
        }
        if ($entity->key === 'claims') {
            if ((float) ($data['hours'] ?? 0) <= 0 || (float) ($data['hours'] ?? 0) > 24) {
                $errors['data.hours'] = 'Give between 0 and 24 hours.';
            }
            $day = Carbon::parse($payload['occurs_on'] ?? today());
            if ($day->gt(today())) {
                $errors['occurs_on'] = 'Overtime can only be claimed once it is worked.';
            }
            if (filled($employee) && $this->records('claims')->where('data->employee', (int) $employee)->whereDate('occurs_on', $day->toDateString())->where('status', '!=', 'rejected')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                $errors['occurs_on'] = $this->name($employee).' already claimed overtime for '.$day->format('d M Y').'.';
            }
            if ($payload['status'] === 'paid' && (float) ($payload['amount'] ?? 0) <= 0) {
                $errors['amount'] = 'Set the amount before marking the claim paid.';
            }
        }
        if ($entity->key === 'allowances') {
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The allowance cannot end before it starts.';
            }
            if (filled($employee) && $payload['status'] === 'active' && $this->records('allowances')->where('data->employee', (int) $employee)->where('data->type', (string) ($data['type'] ?? ''))->where('status', 'active')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                $errors['data.type'] = $this->name($employee).' already gets a '.str_replace('_', ' ', (string) $data['type']).' allowance.';
            }
        }
        if ($entity->key === 'advances') {
            if ((float) ($data['instalment'] ?? 0) > (float) ($payload['amount'] ?? 0)) {
                $errors['data.instalment'] = 'The monthly deduction cannot be more than the amount.';
            }
            $earlier = filled($employee) && ! $existing ? $this->records('advances')->where('data->employee', (int) $employee)->whereIn('status', self::OWING)->get() : collect();
            if ($earlier->isNotEmpty()) {
                $errors['data.employee'] = $this->name($employee).' still owes '.$this->money($earlier->sum(fn (Record $advance) => $this->balance($advance))).' on an earlier advance.';
            }
        }

        return $errors;
    }

    protected function name(mixed $userId): string
    {
        return (string) (filled($userId) ? User::query()->whereKey($userId)->value('name') : null) ?: 'Unknown';
    }

    /**
     * What is still owed on an advance.
     */
    protected function balance(Record $advance): float
    {
        return $advance->value('balance') === null ? (float) $advance->amount : $this->number($advance, 'balance');
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'allowances' && $record->status === 'active' && $record->due_on && $record->due_on->lt(today())) {
            $record->status = 'ended';
        }
        if ($record->entity === 'advances') {
            if ($record->value('balance') === null || (! $record->exists && $this->number($record, 'balance') <= 0) || ($record->status === 'requested' && $record->isDirty('amount'))) {
                $this->put($record, ['balance' => (float) $record->amount]);
            }
            if (in_array($record->status, ['approved', 'repaying'], true) && $this->number($record, 'balance') <= 0) {
                $record->status = 'repaid';
            }
        }
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'claims' && $record->status === 'submitted' => ['approve' => ['label' => 'Approve', 'icon' => 'check'], 'reject' => ['label' => 'Reject', 'icon' => 'x']],
            $record->entity === 'claims' && $record->status === 'approved' => ['pay' => ['label' => 'Paid', 'icon' => 'banknote', 'fields' => [['name' => 'amount', 'label' => 'Amount paid', 'type' => 'number', 'value' => $record->amount]]]],
            $record->entity === 'advances' && $record->status === 'requested' => ['approve' => ['label' => 'Approve', 'icon' => 'check'], 'reject' => ['label' => 'Reject', 'icon' => 'x']],
            $record->entity === 'advances' && in_array($record->status, ['approved', 'repaying'], true) => ['deduct' => ['label' => 'Deduct', 'icon' => 'minus-circle', 'fields' => [['name' => 'amount', 'label' => 'Amount deducted', 'type' => 'number', 'value' => min($this->number($record, 'instalment') ?: $this->balance($record), $this->balance($record))]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $name = $this->name($record->value('employee'));
        switch ($action) {
            case 'reject':
                $record->update(['status' => 'rejected']);

                return $name.'\'s '.($record->entity === 'claims' ? 'claim' : 'request').' was rejected.';
            case 'approve':
                if ($record->entity === 'advances' && $this->number($record, 'instalment') <= 0) {
                    throw ValidationException::withMessages(['instalment' => 'Set the monthly deduction before approving.']);
                }
                $record->update(['status' => 'approved']);

                return $record->entity === 'claims'
                    ? $name.'\'s '.$this->number($record, 'hours').' hours approved.'
                    : $name.'\'s advance of '.$this->money($record->amount).' approved, repaid over '.(int) ceil((float) $record->amount / $this->number($record, 'instalment')).' months.';
            case 'pay':
                $amount = (float) ($request->validate(['amount' => ['nullable', 'numeric', 'min:0']])['amount'] ?? $record->amount);
                if ($amount <= 0) {
                    throw ValidationException::withMessages(['amount' => 'Set the amount before marking the claim paid.']);
                }
                $record->update(['status' => 'paid', 'amount' => $amount]);

                return 'Paid '.$name.' '.$this->money($amount).' overtime.';
            default:
                $balance = $this->balance($record);
                $amount = (float) $request->validate(['amount' => ['required', 'numeric', 'gt:0']])['amount'];
                if ($amount > $balance) {
                    throw ValidationException::withMessages(['amount' => 'Only '.$this->money($balance).' is left to repay.']);
                }
                $left = round($balance - $amount, 2);
                $record->update(['status' => 'repaying', 'data' => [...$record->data, 'balance' => $left, '_deductions' => (int) $record->value('_deductions') + 1]]);

                return 'Deducted '.$this->money($amount).' from '.$name.'\'s advance; '.($left > 0 ? $this->money($left).' left.' : 'it is repaid.');
        }
    }

    public function daily(Workspace $workspace): int
    {
        $ended = $this->records('allowances')->where('status', 'active')->whereNotNull('due_on')->whereDate('due_on', '<', today()->toDateString())->get();
        $ended->each(fn (Record $allowance) => $allowance->save());

        return $ended->count();
    }

    public function homeCards(): array
    {
        $claims = $this->records('claims')->where('status', 'submitted')->get();
        $owing = $this->records('advances')->whereIn('status', ['approved', 'repaying'])->get();
        $allowances = $this->records('allowances')->where('status', 'active')->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Pay extras', 'icon' => 'hand-coins', 'stats' => [
            ['label' => 'Claims to approve', 'value' => $claims->count().' ('.$claims->sum(fn (Record $claim) => $this->number($claim, 'hours')).' h)', 'tone' => $claims->isNotEmpty() ? 'warning' : null],
            ['label' => 'Allowances a month', 'value' => $this->money($allowances->sum(fn (Record $allowance) => (float) $allowance->amount))],
            ['label' => 'Owed on advances', 'value' => $this->money($owing->sum(fn (Record $advance) => $this->balance($advance)))],
            ['label' => 'Deducted each month', 'value' => $this->money($owing->sum(fn (Record $advance) => min($this->number($advance, 'instalment'), $this->balance($advance))))],
        ]]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $claims = $this->dated('claims', $from, $to)->where('status', '!=', 'rejected')->get();
        $advances = $this->records('advances')->whereIn('status', ['approved', 'repaying', 'repaid'])->get();
        $names = User::query()->whereIn('id', $claims->merge($advances)->map(fn (Record $record) => $record->value('employee'))->filter()->unique())->pluck('name', 'id');

        return [
            ['title' => 'Overtime by employee', 'columns' => ['Employee', 'Claims', 'Hours', 'Approved, not paid', 'Paid'], 'rows' => $claims
                ->groupBy(fn (Record $claim) => $names[$claim->value('employee')] ?? 'Unknown')->sortKeys()
                ->map(fn ($group, string $name) => [
                    $name, $group->count(), $group->sum(fn (Record $claim) => $this->number($claim, 'hours')),
                    $this->money($group->where('status', 'approved')->sum(fn (Record $claim) => (float) $claim->amount)), $this->money($group->where('status', 'paid')->sum(fn (Record $claim) => (float) $claim->amount)),
                ])->values()->all()],
            ['title' => 'Loan book', 'columns' => ['Employee', 'Purpose', 'Issued', 'Amount', 'Monthly deduction', 'Balance', 'Status'], 'rows' => $advances->sortBy('occurs_on')->map(fn (Record $advance) => [
                $names[$advance->value('employee')] ?? 'Unknown', $advance->title, $advance->occurs_on?->format('d M Y') ?? '—', $this->money($advance->amount),
                $this->money($this->number($advance, 'instalment')), $this->money($this->balance($advance)), ucfirst($advance->status),
            ])->values()->all()],
        ];
    }
}
