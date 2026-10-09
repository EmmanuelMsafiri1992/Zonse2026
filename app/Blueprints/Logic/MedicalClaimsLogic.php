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
 * Medical aid claims: a claim is submitted with the member number, valid ICD-10 codes and the
 * amount claimed, and not more than four months after the service. A submitted claim is followed
 * up 30 days later, a query or rejection carries its reason, and a payment never exceeds what was
 * claimed, with any shortfall recorded so it can be recovered from the patient.
 */
class MedicalClaimsLogic extends AppLogic
{
    public const STALE_DAYS = 120;

    public const FOLLOW_UP_DAYS = 30;

    public const ICD10 = '/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/';

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        $codes = $this->codes($data['icd10_codes'] ?? null);
        if ($invalid = $codes->reject(fn (string $code) => preg_match(self::ICD10, $code) === 1)->first()) {
            $errors['data.icd10_codes'] = $invalid.' is not an ICD-10 code.';
        }
        if (in_array($payload['status'], ['submitted', 'queried', 'paid', 'rejected'], true)) {
            if (blank($data['member_number'] ?? null)) {
                $errors['data.member_number'] = 'Enter the medical aid member number.';
            }
            if ($codes->isEmpty() && ! isset($errors['data.icd10_codes'])) {
                $errors['data.icd10_codes'] = 'A claim needs at least one ICD-10 code.';
            }
            if ((float) ($payload['amount'] ?? 0) <= 0) {
                $errors['amount'] = 'Enter the amount claimed.';
            }
        }
        if ($payload['status'] === 'submitted' && (! $existing || $existing->status === 'draft') && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->lt(today()->subDays(self::STALE_DAYS))) {
            $errors['occurs_on'] = 'The service is more than four months old; the medical aid will not pay it.';
        }
        if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
            $errors['occurs_on'] = 'The service date cannot be in the future.';
        }
        if ($payload['status'] === 'paid' && (float) ($data['amount_paid'] ?? 0) <= 0) {
            $errors['data.amount_paid'] = 'Enter what the medical aid paid.';
        } elseif ((float) ($data['amount_paid'] ?? 0) > (float) ($payload['amount'] ?? 0)) {
            $errors['data.amount_paid'] = 'More than was claimed.';
        }
        if (in_array($payload['status'], ['queried', 'rejected'], true) && blank($data['query_reason'] ?? null)) {
            $errors['data.query_reason'] = 'Record the medical aid\'s reason.';
        }

        return $errors;
    }

    /**
     * The claim's ICD-10 codes, uppercased and split on commas, semicolons or spaces.
     *
     * @return Collection<int, string>
     */
    protected function codes(?string $codes): Collection
    {
        return collect(preg_split('/[\s,;]+/', strtoupper(trim((string) $codes)), -1, PREG_SPLIT_NO_EMPTY))->unique()->values();
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        $submitted = $record->status === 'draft' ? null : ($record->value('_submitted_on') ?? today()->toDateString());
        if ($submitted && in_array($record->status, ['submitted', 'queried'], true)) {
            $record->due_on ??= Carbon::parse($submitted)->addDays(self::FOLLOW_UP_DAYS);
        }
        $paid = $record->status === 'paid' ? ($record->value('_paid_on') ?? today()->toDateString()) : null;

        $this->put($record, [
            'icd10_codes' => $this->codes($record->value('icd10_codes'))->implode(', ') ?: null,
            'medical_aid' => trim((string) $record->value('medical_aid')),
            '_submitted_on' => $submitted,
            '_paid_on' => $paid,
            '_shortfall' => $paid ? round((float) $record->amount - $this->number($record, 'amount_paid'), 2) : null,
            '_days_to_pay' => $paid && $submitted ? (int) Carbon::parse($submitted)->diffInDays(Carbon::parse($paid)) : null,
            '_overdue' => in_array($record->status, ['submitted', 'queried'], true) && $record->due_on?->lt(today()),
        ]);
    }

    public function actions(Record $record): array
    {
        $reason = ['name' => 'query_reason', 'label' => 'Reason', 'type' => 'textarea'];

        return match ($record->status) {
            'draft' => ['submit' => ['label' => 'Submit', 'icon' => 'send', 'fields' => [['name' => 'reference', 'label' => 'Medical aid reference', 'type' => 'text']]]],
            'submitted' => [
                'pay' => ['label' => 'Paid', 'icon' => 'banknote', 'fields' => [['name' => 'amount_paid', 'label' => 'Amount paid', 'type' => 'number', 'value' => $record->amount]]],
                'query' => ['label' => 'Queried', 'icon' => 'circle-help', 'fields' => [$reason]],
                'reject' => ['label' => 'Rejected', 'icon' => 'x', 'fields' => [$reason]],
            ],
            'queried' => ['resubmit' => ['label' => 'Resubmit', 'icon' => 'send', 'fields' => [['name' => 'note', 'label' => 'What was corrected', 'type' => 'text']]], 'reject' => ['label' => 'Rejected', 'icon' => 'x', 'fields' => [$reason]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $aid = $record->value('medical_aid');

        switch ($action) {
            case 'submit':
                $errors = $this->validate($this->app->entities['claims'], ['status' => 'submitted', 'amount' => $record->amount, 'occurs_on' => $record->occurs_on?->toDateString(), 'data' => $record->data], $record);
                if ($errors) {
                    throw ValidationException::withMessages($errors);
                }
                $reference = $request->validate(['reference' => ['nullable', 'string']])['reference'] ?? null;
                $record->update(['status' => 'submitted', 'data' => [...$record->data, 'reference' => $reference ?: $record->value('reference')]]);

                return 'Claim for '.$record->title.' submitted to '.$aid.'; follow up by '.$record->fresh()->due_on->format('d M Y').'.';
            case 'pay':
                $paid = (float) $request->validate(['amount_paid' => ['required', 'numeric', 'gt:0']])['amount_paid'];
                if ($paid > (float) $record->amount) {
                    throw ValidationException::withMessages(['amount_paid' => 'More than was claimed.']);
                }
                $record->update(['status' => 'paid', 'data' => [...$record->data, 'amount_paid' => $paid]]);
                $shortfall = (float) $record->fresh()->value('_shortfall');

                return $aid.' paid '.$this->money($paid).($shortfall > 0 ? '; '.$this->money($shortfall).' short.' : ' in full.');
            case 'query':
            case 'reject':
                $reason = $request->validate(['query_reason' => ['required', 'string']])['query_reason'];
                $record->update(['status' => $action === 'query' ? 'queried' : 'rejected', 'data' => [...$record->data, 'query_reason' => $reason]]);

                return 'Claim for '.$record->title.($action === 'query' ? ' queried.' : ' rejected.');
        }

        $note = $request->validate(['note' => ['required', 'string']])['note'];
        $record->update(['status' => 'submitted', 'due_on' => today()->addDays(self::FOLLOW_UP_DAYS), 'data' => [...$record->data, '_resubmissions' => (int) $record->value('_resubmissions') + 1, '_last_correction' => $note]]);

        return 'Claim for '.$record->title.' resubmitted.';
    }

    public function homeCards(): array
    {
        $claims = $this->records('claims')->get();
        $open = $claims->whereIn('status', ['submitted', 'queried']);
        $follow = $open->sortBy('due_on');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Claims', 'icon' => 'file-check', 'stats' => [
                ['label' => 'Drafts to submit', 'value' => (string) $claims->where('status', 'draft')->count()],
                ['label' => 'Waiting on medical aids', 'value' => $this->money($open->sum('amount')).' · '.$open->count()],
                ['label' => 'Queried', 'value' => (string) $claims->where('status', 'queried')->count(), 'tone' => $claims->where('status', 'queried')->isNotEmpty() ? 'warning' : null],
                ['label' => 'Overdue follow-ups', 'value' => (string) $open->filter(fn (Record $claim) => $claim->value('_overdue'))->count(), 'tone' => $open->contains(fn (Record $claim) => $claim->value('_overdue')) ? 'danger' : null],
                ['label' => 'Shortfalls to recover', 'value' => $this->money($claims->where('status', 'paid')->sum(fn (Record $claim) => (float) $claim->value('_shortfall')))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Follow up', 'icon' => 'phone-call', 'empty' => 'No claims waiting.',
                'rows' => $follow->take(10)->map(fn (Record $claim) => [
                    'label' => $claim->title, 'sub' => $claim->value('medical_aid').' · '.ucfirst($claim->status), 'value' => $claim->due_on?->format('d M Y'), 'href' => $claim->url(), 'tone' => $claim->value('_overdue') ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $claims = $this->dated('claims', $from, $to)->get();
        $byAid = $claims->groupBy(fn (Record $claim) => $claim->value('medical_aid') ?: 'Unknown')->sortKeys()->map(function (Collection $group, string $aid) {
            $paid = $group->where('status', 'paid');

            return [$aid, $group->count(), $this->money($group->sum('amount')), $this->money($paid->sum(fn (Record $claim) => (float) $claim->value('amount_paid'))), $this->money($paid->sum(fn (Record $claim) => (float) $claim->value('_shortfall'))), $group->where('status', 'rejected')->count(), $paid->isEmpty() ? '—' : round($paid->avg(fn (Record $claim) => (int) $claim->value('_days_to_pay')), 1).' days'];
        })->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($claims) {
            $group = $claims->filter(fn (Record $claim) => $claim->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $this->money($group->sum('amount')), $this->money($group->where('status', 'paid')->sum(fn (Record $claim) => (float) $claim->value('amount_paid'))), $group->where('status', 'rejected')->count()];
        })->values()->all();

        $reasons = $claims->whereIn('status', ['queried', 'rejected'])->map(fn (Record $claim) => [$claim->occurs_on?->format('d M Y'), $claim->title, $claim->value('medical_aid'), ucfirst($claim->status), (string) $claim->value('query_reason')])->values()->all();

        return [
            ['title' => 'Claims by medical aid', 'columns' => ['Medical aid', 'Claims', 'Claimed', 'Paid', 'Shortfall', 'Rejected', 'Time to pay'], 'rows' => $byAid],
            ['title' => 'Claims by month', 'columns' => ['Month', 'Claims', 'Claimed', 'Paid', 'Rejected'], 'rows' => $byMonth],
            ['title' => 'Queries and rejections', 'columns' => ['Service date', 'Patient', 'Medical aid', 'Status', 'Reason'], 'rows' => $reasons],
        ];
    }
}
