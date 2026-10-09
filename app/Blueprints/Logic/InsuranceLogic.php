<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Insurance broking: policy numbers are unique and an active policy has its cover dates; a
 * claim must fall inside cover on a policy that was bound, and approved and paid claims cannot
 * exceed the sum insured. Policies track their loss ratio and lapse when the renewal passes.
 */
class InsuranceLogic extends AppLogic
{
    public const RENEWAL_WARNING_DAYS = 30;

    public const OPEN_CLAIMS = ['reported', 'submitted', 'approved'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        return $entity->key === 'policies' ? $this->validatePolicy($payload, $existing) : $this->validateClaim($payload, $existing);
    }

    /** @param array<string, mixed> $payload */
    protected function validatePolicy(array $payload, ?Record $existing): array
    {
        $errors = [];

        if ($this->records('policies')->where('title', $payload['title'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
            $errors['title'] = 'Policy '.$payload['title'].' is already on the book.';
        }
        if ($payload['status'] === 'active') {
            if (blank($payload['occurs_on'] ?? null)) {
                $errors['occurs_on'] = 'An active policy needs its inception date.';
            }
            if (blank($payload['due_on'] ?? null)) {
                $errors['due_on'] = 'An active policy needs its renewal date.';
            }
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lte(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'Renewal must come after inception.';
        }

        return $errors;
    }

    /** @param array<string, mixed> $payload */
    protected function validateClaim(array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        $policy = ! empty($data['policy']) ? $this->records('policies')->find($data['policy']) : null;
        if (! $policy) {
            return $errors;
        }

        if ($policy->status === 'quoted') {
            $errors['data.policy'] = 'Policy '.$policy->title.' is only quoted, so it has no cover to claim on.';
        }
        if (filled($data['incident_date'] ?? null)) {
            $incident = Carbon::parse($data['incident_date']);
            if ($incident->gt(today())) {
                $errors['data.incident_date'] = 'The incident cannot be in the future.';
            } elseif (($policy->occurs_on && $incident->lt($policy->occurs_on)) || ($policy->due_on && $incident->gt($policy->due_on))) {
                $errors['data.incident_date'] = 'The incident is outside the cover period ('.($policy->occurs_on?->format('d M Y') ?? '…').' to '.($policy->due_on?->format('d M Y') ?? '…').').';
            }
        }

        $sumInsured = $this->number($policy, 'sum_insured');
        if ($sumInsured > 0 && in_array($payload['status'], ['approved', 'paid'], true)) {
            $other = (float) $this->linked('claims', 'policy', $policy)->whereIn('status', ['approved', 'paid'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->sum('amount');
            if ($other + (float) ($payload['amount'] ?? 0) - $sumInsured > 0.004) {
                $errors['amount'] = 'Only '.$this->money(max(0, $sumInsured - $other)).' of the '.$this->money($sumInsured).' sum insured is left.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'policies') {
            return;
        }

        $claims = $record->exists ? $this->linked('claims', 'policy', $record)->get() : collect();
        $paid = (float) $claims->where('status', 'paid')->sum('amount');
        $this->put($record, [
            '_claims_paid' => round($paid, 2),
            '_open_claims' => $claims->whereIn('status', self::OPEN_CLAIMS)->count(),
            '_loss_ratio' => (float) $record->amount > 0 ? round($paid / (float) $record->amount * 100, 1) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'claims') {
            $this->recalculate($this->parent($record, 'policy'));
            $this->recalculate($this->previousParent($record, 'policy'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'claims') {
            $this->recalculate($this->parent($record, 'policy'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $lapsed = 0;
        foreach ($this->records('policies')->where('status', 'active')->whereDate('due_on', '<', today())->get() as $policy) {
            $policy->update(['status' => 'lapsed']);
            $lapsed++;
        }

        return $lapsed;
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'policies' || ! in_array($record->status, ['active', 'lapsed'], true) || ! $record->due_on) {
            return [];
        }

        return ['renew' => ['label' => 'Renew for a year', 'icon' => 'refresh-cw', 'fields' => [['name' => 'premium', 'label' => 'New premium', 'type' => 'number', 'value' => (string) $record->amount]]]];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $premium = (float) $request->validate(['premium' => ['required', 'numeric', 'min:0']])['premium'];
        $from = $record->due_on->copy();
        $record->update(['status' => 'active', 'amount' => $premium, 'occurs_on' => $from, 'due_on' => $from->copy()->addYear()]);

        return 'Policy '.$record->title.' is renewed to '.$record->due_on->format('d M Y').' at '.$this->money($premium).'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'policies') {
            return [];
        }

        $cards = [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Claims', 'icon' => 'file-warning', 'stats' => [
            ['label' => 'Open claims', 'value' => (string) (int) $record->value('_open_claims')],
            ['label' => 'Paid out', 'value' => $this->money($record->value('_claims_paid'))],
            ['label' => 'Loss ratio', 'value' => $record->value('_loss_ratio') !== null ? $record->value('_loss_ratio').'%' : '—', 'tone' => $this->number($record, '_loss_ratio') > 100 ? 'danger' : null],
        ]]]];

        if ($record->status === 'active' && $record->due_on && $record->due_on->lte(today()->addDays(self::RENEWAL_WARNING_DAYS))) {
            $cards[] = ['view' => 'apps.logic.alert-card', 'data' => ['tone' => 'warning', 'icon' => 'refresh-cw', 'title' => 'Renewal due',
                'body' => 'This policy renews on '.$record->due_on->format('d M Y').'. Contact the policyholder for the renewal.']];
        }

        return $cards;
    }

    public function homeCards(): array
    {
        $renewals = $this->records('policies')->where('status', 'active')->whereDate('due_on', '<=', today()->addDays(self::RENEWAL_WARNING_DAYS))->orderBy('due_on')->get();
        $claims = $this->records('claims')->whereIn('status', self::OPEN_CLAIMS)->orderBy('occurs_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Renewals due', 'icon' => 'refresh-cw', 'empty' => 'No renewals in the next 30 days.',
                'rows' => $renewals->map(fn (Record $policy) => [
                    'label' => $policy->title, 'sub' => $policy->contact?->name ?? $policy->value('insurer'), 'value' => $policy->due_on->format('d M Y'), 'href' => $policy->url(), 'tone' => 'warning',
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Open claims', 'icon' => 'file-warning', 'empty' => 'No open claims.',
                'rows' => $claims->take(15)->map(fn (Record $claim) => [
                    'label' => $claim->title, 'sub' => ucfirst($claim->status), 'value' => $this->money($claim->amount), 'href' => $claim->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $policies = $this->records('policies')->whereIn('status', ['active', 'lapsed'])->get();
        $insurers = $policies->groupBy(fn (Record $policy) => (string) $policy->value('insurer'))->sortKeys()->map(fn ($group, $insurer) => [
            $insurer, $group->count(), $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $policy) => $this->number($policy, '_claims_paid'))),
        ])->values()->all();

        $claims = $this->dated('claims', $from, $to)->get();
        $statuses = collect(['reported', 'submitted', 'approved', 'paid', 'declined'])->map(fn (string $status) => [
            ucfirst($status), $claims->where('status', $status)->count(), $this->money($claims->where('status', $status)->sum('amount')),
        ])->all();

        return [
            ['title' => 'Book by insurer', 'columns' => ['Insurer', 'Policies', 'Premium', 'Claims paid'], 'rows' => $insurers],
            ['title' => 'Claims by status', 'columns' => ['Status', 'Claims', 'Amount'], 'rows' => $statuses],
        ];
    }
}
