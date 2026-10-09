<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Medical billing and coding: a claim is coded with valid ICD-10 codes and must reach the insurer
 * within ninety days of the service (the "submit by" date, unless one is set). Submitting needs a
 * claimed amount and is refused once the deadline has passed. The insurer's remittance marks the
 * claim paid or part paid and keeps the shortfall; a rejection needs its reason, and a rejected
 * claim can be corrected and resubmitted. Reports show each insurer's payment and rejection rates
 * and how long unpaid claims have been waiting.
 */
class MedicalBillingLogic extends AppLogic
{
    /**
     * Days after the service date within which a claim must be submitted.
     */
    protected const SUBMIT_WITHIN = 90;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        $claimed = (float) ($payload['amount'] ?? 0);

        if ($invalid = $this->codes($data['icd10'] ?? null)->reject(fn (string $code) => preg_match(EmrLogic::ICD10, $code) === 1)->first()) {
            $errors['data.icd10'] = $invalid.' is not an ICD-10 code.';
        }
        if ($payload['status'] !== 'draft' && $claimed <= 0) {
            $errors['amount'] = 'Enter the amount claimed before submitting.';
        }
        if (filled($data['paid_amount'] ?? null) && (float) $data['paid_amount'] > $claimed) {
            $errors['data.paid_amount'] = 'The insurer cannot pay more than was claimed.';
        }
        if ($payload['status'] === 'part_paid' && ((float) ($data['paid_amount'] ?? 0) <= 0 || (float) $data['paid_amount'] >= $claimed)) {
            $errors['data.paid_amount'] = 'A part payment is more than nothing and less than the claim.';
        }
        if ($payload['status'] === 'paid' && (float) ($data['paid_amount'] ?? 0) < $claimed) {
            $errors['data.paid_amount'] = 'Mark the claim part paid when less than the claim was paid.';
        }
        if ($payload['status'] === 'rejected' && blank($data['rejection_reason'] ?? null)) {
            $errors['data.rejection_reason'] = 'Record why the insurer rejected the claim.';
        }
        if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->isFuture()) {
            $errors['occurs_on'] = 'A claim is for a service already given.';
        }
        if ($payload['status'] === 'submitted' && $existing?->status !== 'submitted' && ($deadline = $this->deadline($payload)) && $deadline->lt(today())) {
            $errors['status'] = 'The submission deadline was '.$deadline->format('d M Y').'.';
        }

        return $errors;
    }

    /**
     * ICD-10 codes, uppercased and split on commas, semicolons or spaces.
     *
     * @return Collection<int, string>
     */
    protected function codes(?string $codes): Collection
    {
        return collect(preg_split('/[\s,;]+/', strtoupper(trim((string) $codes)), -1, PREG_SPLIT_NO_EMPTY))->unique()->values();
    }

    /**
     * The submit-by date for a claim's attributes.
     *
     * @param  array<string, mixed>  $values
     */
    protected function deadline(array $values): ?Carbon
    {
        if (filled($values['due_on'] ?? null)) {
            return Carbon::parse($values['due_on']);
        }

        return filled($values['occurs_on'] ?? null) ? Carbon::parse($values['occurs_on'])->addDays(self::SUBMIT_WITHIN) : null;
    }

    public function saving(Record $record): void
    {
        if (! $record->due_on && $record->occurs_on) {
            $record->due_on = $record->occurs_on->copy()->addDays(self::SUBMIT_WITHIN);
        }
        if (in_array($record->status, ['submitted', 'resubmitted'], true) && blank($record->value('_submitted_on'))) {
            $this->put($record, ['_submitted_on' => today()->toDateString()]);
        }
        if (in_array($record->status, ['paid', 'part_paid'], true) && blank($record->value('_paid_on'))) {
            $this->put($record, ['_paid_on' => today()->toDateString()]);
        }
        $paid = $this->number($record, 'paid_amount');
        $submitted = $record->value('_submitted_on') ? Carbon::parse($record->value('_submitted_on')) : null;
        $this->put($record, [
            'icd10' => $this->codes($record->value('icd10'))->implode(', ') ?: null,
            '_shortfall' => in_array($record->status, ['paid', 'part_paid'], true) ? round((float) $record->amount - $paid, 2) : null,
            '_days_to_pay' => $submitted && $record->value('_paid_on') ? (int) $submitted->diffInDays(Carbon::parse($record->value('_paid_on'))) : null,
        ]);
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'draft' => ['submit' => ['label' => 'Submit to insurer', 'icon' => 'send']],
            'submitted', 'resubmitted' => [
                'accept' => ['label' => 'Accepted', 'icon' => 'check'],
                'remit' => $this->remitAction(),
                'reject' => ['label' => 'Rejected', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Rejection reason', 'type' => 'textarea']]],
            ],
            'accepted', 'part_paid' => ['remit' => $this->remitAction()],
            'rejected' => ['resubmit' => ['label' => 'Correct & resubmit', 'icon' => 'rotate-ccw', 'fields' => [['name' => 'icd10', 'label' => 'ICD-10 codes', 'type' => 'text', 'value' => (string) $record->value('icd10')]]]],
            default => [],
        };
    }

    /**
     * The remittance action with its amount field.
     *
     * @return array{label: string, icon: string, fields: list<array<string, string>>}
     */
    protected function remitAction(): array
    {
        return ['label' => 'Record remittance', 'icon' => 'banknote', 'fields' => [['name' => 'paid', 'label' => 'Amount paid', 'type' => 'number']]];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'submit') {
            if ((float) $record->amount <= 0) {
                throw ValidationException::withMessages(['status' => 'Enter the amount claimed before submitting.']);
            }
            if ($record->due_on?->lt(today())) {
                throw ValidationException::withMessages(['status' => 'The submission deadline was '.$record->due_on->format('d M Y').'.']);
            }
            $record->update(['status' => 'submitted']);

            return 'Claim for '.$record->title.' submitted to '.$record->value('insurer').'.';
        }
        if ($action === 'accept') {
            $record->update(['status' => 'accepted']);

            return $record->value('insurer').' accepted the claim for '.$record->title.'.';
        }
        if ($action === 'reject') {
            $reason = $request->validate(['reason' => ['required', 'string']])['reason'];
            $record->update(['status' => 'rejected', 'data' => [...$record->data, 'rejection_reason' => $reason, '_rejections' => (int) $record->value('_rejections') + 1]]);

            return 'Claim for '.$record->title.' rejected: '.$reason;
        }
        if ($action === 'resubmit') {
            $codes = $this->codes($request->validate(['icd10' => ['required', 'string']])['icd10']);
            if ($invalid = $codes->reject(fn (string $code) => preg_match(EmrLogic::ICD10, $code) === 1)->first()) {
                throw ValidationException::withMessages(['icd10' => $invalid.' is not an ICD-10 code.']);
            }
            $record->update(['status' => 'resubmitted', 'data' => [...$record->data, 'icd10' => $codes->implode(', '), '_submitted_on' => today()->toDateString()]]);

            return 'Claim for '.$record->title.' corrected and resubmitted.';
        }

        $already = $this->number($record, 'paid_amount');
        $paid = (float) $request->validate(['paid' => ['required', 'numeric', 'gt:0']])['paid'];
        $total = round($already + $paid, 2);
        if ($total > (float) $record->amount) {
            throw ValidationException::withMessages(['paid' => 'That takes the payments to '.$this->money($total).', more than the '.$this->money($record->amount).' claimed.']);
        }
        $status = $total >= (float) $record->amount ? 'paid' : 'part_paid';
        $record->update(['status' => $status, 'data' => [...$record->data, 'paid_amount' => $total, '_paid_on' => today()->toDateString()]]);

        return $status === 'paid'
            ? 'Claim for '.$record->title.' paid in full.'
            : $this->money($paid).' received for '.$record->title.'; '.$this->money((float) $record->amount - $total).' still short.';
    }

    public function recordCards(Record $record): array
    {
        $date = fn (?string $value) => $value ? Carbon::parse($value)->format('d M Y') : '—';

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Claim', 'icon' => 'file-check', 'stats' => [
                ['label' => 'Submit by', 'value' => $record->due_on?->format('d M Y') ?? '—', 'tone' => $record->status === 'draft' && $record->due_on?->lte(today()->addWeek()) ? 'danger' : null],
                ['label' => 'Submitted', 'value' => $date($record->value('_submitted_on'))],
                ['label' => 'Claimed', 'value' => $this->money($record->amount)],
                ['label' => 'Paid', 'value' => $this->money($record->value('paid_amount'))],
                ['label' => 'Shortfall', 'value' => $record->value('_shortfall') === null ? '—' : $this->money($record->value('_shortfall')), 'tone' => (float) $record->value('_shortfall') > 0 ? 'warning' : null],
                ['label' => 'Days to pay', 'value' => $record->value('_days_to_pay') === null ? '—' : (string) $record->value('_days_to_pay')],
            ]]],
        ];
    }

    public function homeCards(): array
    {
        $claims = $this->records('claims')->get();
        $drafts = $claims->where('status', 'draft')->filter(fn (Record $claim) => $claim->due_on?->lte(today()->addWeek()))->sortBy('due_on');
        $unpaid = $claims->whereIn('status', ['submitted', 'resubmitted', 'accepted', 'part_paid']);
        $outstanding = $unpaid->sum(fn (Record $claim) => (float) $claim->amount - $this->number($claim, 'paid_amount'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Claims', 'icon' => 'file-check', 'stats' => [
                ['label' => 'Drafts', 'value' => (string) $claims->where('status', 'draft')->count()],
                ['label' => 'Awaiting payment', 'value' => (string) $unpaid->count()],
                ['label' => 'Outstanding', 'value' => $this->money($outstanding)],
                ['label' => 'Rejected', 'value' => (string) $claims->where('status', 'rejected')->count(), 'tone' => $claims->where('status', 'rejected')->isNotEmpty() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Submit this week', 'icon' => 'calendar-clock', 'empty' => 'No draft claim is near its deadline.',
                'rows' => $drafts->map(fn (Record $claim) => [
                    'label' => $claim->title, 'sub' => (string) $claim->value('insurer'), 'value' => $claim->due_on->format('d M'), 'href' => $claim->url(), 'tone' => $claim->due_on->lt(today()) ? 'danger' : 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $claims = $this->dated('claims', $from, $to)->get();
        $sent = $claims->where('status', '!=', 'draft');

        $byInsurer = $sent->groupBy(fn (Record $claim) => (string) $claim->value('insurer'))->sortKeys()->map(function (Collection $group, string $insurer) {
            $paid = $group->sum(fn (Record $claim) => $this->number($claim, 'paid_amount'));
            $rejected = $group->filter(fn (Record $claim) => (int) $claim->value('_rejections') > 0)->count();
            $settled = $group->filter(fn (Record $claim) => $claim->value('_days_to_pay') !== null);

            return [
                $insurer, $group->count(), $this->money($group->sum('amount')), $this->money($paid), (int) round($rejected / $group->count() * 100).'%',
                $settled->isEmpty() ? '—' : round($settled->avg(fn (Record $claim) => (int) $claim->value('_days_to_pay'))).' days',
            ];
        })->values()->all();

        $reasons = $claims->filter(fn (Record $claim) => filled($claim->value('rejection_reason')))->sortBy('occurs_on')
            ->map(fn (Record $claim) => [$claim->title, (string) $claim->value('insurer'), (string) $claim->value('icd10'), (string) $claim->value('rejection_reason'), str_replace('_', ' ', ucfirst($claim->status))])->values()->all();

        $unpaid = $this->records('claims')->whereIn('status', ['submitted', 'resubmitted', 'accepted', 'part_paid'])->get();
        $bands = ['0–30 days' => [0, 30], '31–60 days' => [31, 60], '61–90 days' => [61, 90], 'Over 90 days' => [91, PHP_INT_MAX]];
        $ageing = collect($bands)->map(function (array $band, string $label) use ($unpaid) {
            $group = $unpaid->filter(function (Record $claim) use ($band) {
                $days = (int) Carbon::parse($claim->value('_submitted_on') ?? $claim->occurs_on)->diffInDays(today());

                return $days >= $band[0] && $days <= $band[1];
            });

            return [$label, $group->count(), $this->money($group->sum(fn (Record $claim) => (float) $claim->amount - $this->number($claim, 'paid_amount')))];
        })->values()->all();

        return [
            ['title' => 'Claims by insurer', 'columns' => ['Insurer', 'Claims', 'Claimed', 'Paid', 'Rejection rate', 'Average days to pay'], 'rows' => $byInsurer],
            ['title' => 'Rejections', 'columns' => ['Patient', 'Insurer', 'ICD-10', 'Reason', 'Status now'], 'rows' => $reasons],
            ['title' => 'Unpaid claims by age', 'columns' => ['Waiting', 'Claims', 'Outstanding'], 'rows' => $ageing],
        ];
    }
}
