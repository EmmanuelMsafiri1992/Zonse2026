<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Payroll: each payslip works out its gross and net pay from its earnings and deductions,
 * the pay run adds its payslips up, and approving or paying a run carries its payslips along.
 * Payslips print for the employee.
 */
class PayrollLogic extends AppLogic
{
    public const EARNINGS = ['basic_pay' => 'Basic pay', 'allowances' => 'Allowances', 'overtime' => 'Overtime'];

    public const DEDUCTIONS = ['paye' => 'PAYE / income tax', 'social_security' => 'Social security', 'other_deductions' => 'Other deductions'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'payslips') {
            return [];
        }

        $data = $payload['data'];
        $gross = array_sum(array_map(fn (string $key) => (float) ($data[$key] ?? 0), array_keys(self::EARNINGS)));
        $deductions = array_sum(array_map(fn (string $key) => (float) ($data[$key] ?? 0), array_keys(self::DEDUCTIONS)));
        $errors = [];
        if ($deductions > $gross) {
            $errors['data.other_deductions'] = 'Deductions ('.$this->money($deductions).') are more than the gross pay ('.$this->money($gross).').';
        }

        $run = ! empty($data['run']) ? $this->records('runs')->find($data['run']) : null;
        if ($run && $run->status === 'paid' && (! $existing || (int) $existing->value('run') !== $run->id)) {
            $errors['data.run'] = 'Pay run '.$run->number.' is already paid. Add the payslip to a new run.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'payslips') {
            $gross = $this->gross($record);
            $this->put($record, ['_gross' => $gross]);
            $record->amount = round($gross - $this->deductions($record), 2);
        }

        if ($record->entity === 'runs' && $record->exists) {
            $payslips = $this->linked('payslips', 'run', $record)->get();
            $this->put($record, [
                'employee_count' => $payslips->count(),
                'gross_total' => round($payslips->sum(fn (Record $payslip) => $this->gross($payslip)), 2),
                'deductions_total' => round($payslips->sum(fn (Record $payslip) => $this->deductions($payslip)), 2),
            ]);
            $record->amount = round($payslips->sum('amount'), 2);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'payslips') {
            $this->recalculate($this->parent($record, 'run'));
            $this->recalculate($this->previousParent($record, 'run'));
        }

        if ($record->entity === 'runs' && $record->wasChanged('status') && in_array($record->status, ['approved', 'paid'], true)) {
            $this->linked('payslips', 'run', $record)->whereNot('status', $record->status)->get()
                ->each(fn (Record $payslip) => $payslip->update(['status' => $record->status]));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'payslips') {
            $this->recalculate($this->parent($record, 'run'));
        }
    }

    public function gross(Record $payslip): float
    {
        return round(array_sum(array_map(fn (string $key) => $this->number($payslip, $key), array_keys(self::EARNINGS))), 2);
    }

    public function deductions(Record $payslip): float
    {
        return round(array_sum(array_map(fn (string $key) => $this->number($payslip, $key), array_keys(self::DEDUCTIONS))), 2);
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'payslips') {
            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Pay', 'icon' => 'banknote', 'stats' => [
                ['label' => 'Gross pay', 'value' => $this->money($this->gross($record))],
                ['label' => 'Deductions', 'value' => $this->money($this->deductions($record))],
                ['label' => 'Net pay', 'value' => $this->money($record->amount), 'tone' => 'success'],
            ]]]];
        }

        if ($record->entity === 'runs') {
            $payslips = $this->linked('payslips', 'run', $record)->orderBy('title')->get();

            return [['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Payslips in this run', 'icon' => 'file-text', 'empty' => 'No payslips yet. Add payslips and pick this run.',
                'rows' => $payslips->map(fn (Record $payslip) => [
                    'label' => $payslip->title, 'sub' => $payslip->number.' · gross '.$this->money($this->gross($payslip)), 'value' => $this->money($payslip->amount), 'href' => $payslip->url(),
                ])->all(),
            ]]];
        }

        return [];
    }

    public function documents(Record $record): array
    {
        return $record->entity === 'payslips' ? ['payslip' => 'Payslip'] : [];
    }

    public function document(string $name, Record $record): ?array
    {
        if ($name !== 'payslip' || $record->entity !== 'payslips') {
            return null;
        }

        $run = $record->related('run');
        $rows = [];
        foreach (self::EARNINGS as $key => $label) {
            if ($this->number($record, $key) != 0) {
                $rows[] = [$label, $this->money($record->value($key)), ''];
            }
        }
        foreach (self::DEDUCTIONS as $key => $label) {
            if ($this->number($record, $key) != 0) {
                $rows[] = [$label, '', $this->money($record->value($key))];
            }
        }

        return ['view' => 'apps.logic.document', 'data' => [
            'heading' => 'Payslip',
            'meta' => array_filter([
                'Employee' => $record->title, 'Employee number' => $record->value('employee_number'),
                'Pay period' => $run?->title, 'Pay date' => $run?->occurs_on?->format('d M Y'), 'Paid into' => $record->value('bank_account'),
            ]),
            'columns' => ['Item', 'Earnings', 'Deductions'],
            'rows' => $rows,
            'totals' => ['Gross pay' => $this->money($this->gross($record)), 'Total deductions' => $this->money($this->deductions($record)), 'Net pay' => $this->money($record->amount)],
        ]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $runs = $this->dated('runs', $from, $to)->whereIn('status', ['approved', 'paid'])->get()->sortBy(fn (Record $run) => ($run->occurs_on ?? $run->created_at)->timestamp);
        $payslips = $runs->isEmpty() ? collect() : $this->records('payslips')->get()->filter(fn (Record $payslip) => $runs->contains('id', (int) $payslip->value('run')));

        $deductionRows = [];
        foreach (self::DEDUCTIONS as $key => $label) {
            $deductionRows[] = [$label, $this->money($payslips->sum(fn (Record $payslip) => $this->number($payslip, $key)))];
        }

        return [
            ['title' => 'Pay runs', 'columns' => ['Pay run', 'Pay date', 'Employees', 'Gross', 'Deductions', 'Net pay'], 'rows' => $runs->map(fn (Record $run) => [
                $run->title, ($run->occurs_on ?? $run->created_at)->format('d M Y'), (int) $run->value('employee_count'),
                $this->money($run->value('gross_total')), $this->money($run->value('deductions_total')), $this->money($run->amount),
            ])->values()->all()],
            ['title' => 'Deductions to pay over', 'columns' => ['Deduction', 'Total'], 'rows' => $deductionRows,
                'note' => 'PAYE and social security withheld in approved and paid runs, due to the tax authority and the social security fund.'],
        ];
    }
}
