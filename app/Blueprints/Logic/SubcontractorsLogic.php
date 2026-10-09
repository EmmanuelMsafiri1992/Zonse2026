<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Subcontractors: a subcontractor goes on site only with current insurance, claims are made only on
 * awarded packages, and a claim is never certified for more than was claimed or more than is left on
 * the subcontract. Certifying holds the retention, paid claims are locked, and the final account
 * waits for every claim to be settled.
 */
class SubcontractorsLogic extends AppLogic
{
    public const CERTIFIED = ['certified', 'paid'];

    public const IN_PROGRESS = ['submitted', 'assessed', 'certified'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'subcontracts') {
            $retention = (float) ($data['retention'] ?? 0);
            if ($retention < 0 || $retention > 100) {
                $errors['data.retention'] = 'Retention must be between 0 and 100%.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The completion date cannot be before the start date.';
            }
            if ($payload['status'] === 'on_site' && filled($data['insurance_expiry'] ?? null) && Carbon::parse($data['insurance_expiry'])->lt(today())) {
                $errors['data.insurance_expiry'] = 'Insurance expired on '.Carbon::parse($data['insurance_expiry'])->format('d M Y').'. Renew it before the subcontractor goes on site.';
            }
            if ($payload['status'] === 'final_account' && $existing) {
                $open = $this->linked('claims', 'subcontract', $existing)->whereIn('status', self::IN_PROGRESS)->count();
                if ($open > 0) {
                    $errors['status'] = $open.' '.str('claim')->plural($open).' still '.($open === 1 ? 'needs' : 'need').' to be paid before the final account.';
                }
            }

            return $errors;
        }

        $claimed = (float) ($data['claimed'] ?? 0);
        if ($claimed <= 0) {
            $errors['data.claimed'] = 'The amount claimed must be more than 0.';
        }
        $subcontract = ! empty($data['subcontract']) ? $this->records('subcontracts')->find($data['subcontract']) : null;
        if ($subcontract?->status === 'tender' && (! $existing || (int) $existing->value('subcontract') !== $subcontract->id)) {
            $errors['data.subcontract'] = 'Award '.$subcontract->title.' before taking claims on it.';
        }
        $certified = $payload['amount'] !== null ? (float) $payload['amount'] : null;
        if ($certified !== null && $certified > $claimed) {
            $errors['amount'] = 'You cannot certify more than the '.$this->money($claimed).' claimed.';
        }
        if (in_array($payload['status'], self::CERTIFIED, true)) {
            if (blank($data['certificate_number'] ?? null)) {
                $errors['data.certificate_number'] = 'Give the payment certificate a number.';
            }
            if ($subcontract && ($over = $this->overCertified($subcontract, $certified ?? $claimed, $existing)) !== null) {
                $errors['amount'] = $over;
            }
        }
        if ($existing?->status === 'paid' && ($payload['status'] !== 'paid' || (float) $existing->amount !== (float) $payload['amount'])) {
            $errors['status'] = 'Paid claims are locked.';
        }

        return $errors;
    }

    /** Why certifying this amount would take the account over the subcontract value, or null when it fits. */
    protected function overCertified(Record $subcontract, float $amount, ?Record $except): ?string
    {
        if (! $subcontract->amount) {
            return null;
        }
        $already = (float) $this->linked('claims', 'subcontract', $subcontract)->whereIn('status', self::CERTIFIED)->when($except, fn ($query) => $query->whereKeyNot($except->id))->sum('amount');
        if ($already + $amount <= (float) $subcontract->amount + 0.005) {
            return null;
        }

        return 'Certifying '.$this->money($amount).' takes the account to '.$this->money($already + $amount).', over the subcontract value of '.$this->money($subcontract->amount).'.';
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'claims') {
            $record->occurs_on ??= today();
            if (in_array($record->status, self::CERTIFIED, true)) {
                $record->amount ??= $this->number($record, 'claimed');
                if (blank($record->value('retention_held'))) {
                    $retention = $this->parent($record, 'subcontract')?->value('retention') ?? 0;
                    $this->put($record, ['retention_held' => round((float) $record->amount * (float) $retention / 100, 2)]);
                }
                $this->put($record, ['_certified_on' => $record->value('_certified_on') ?? today()->toDateString()]);
            }
            $this->put($record, ['_paid_on' => $record->status === 'paid' ? ($record->value('_paid_on') ?? today()->toDateString()) : null]);

            return;
        }

        $claims = $record->exists ? $this->linked('claims', 'subcontract', $record)->get() : collect();
        $certified = $claims->whereIn('status', self::CERTIFIED);
        $this->put($record, [
            '_claimed' => (float) $claims->where('status', '!=', 'disputed')->sum(fn (Record $claim) => $this->number($claim, 'claimed')),
            '_certified' => (float) $certified->sum('amount'),
            '_paid' => (float) $claims->where('status', 'paid')->sum('amount'),
            '_retention' => (float) $certified->sum(fn (Record $claim) => $this->number($claim, 'retention_held')),
            '_remaining' => round((float) $record->amount - (float) $certified->sum('amount'), 2),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'claims') {
            $this->recalculate($this->parent($record, 'subcontract'));
            $this->recalculate($this->previousParent($record, 'subcontract'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'claims') {
            $this->recalculate($this->parent($record, 'subcontract'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'claims') {
            return [];
        }

        return match ($record->status) {
            'submitted', 'assessed' => ['certify' => ['label' => 'Certify', 'icon' => 'file-check', 'fields' => [
                ['name' => 'amount', 'label' => 'Certified amount', 'type' => 'number', 'value' => $this->number($record, 'claimed')],
                ['name' => 'certificate_number', 'label' => 'Payment certificate', 'type' => 'text'],
            ]]],
            'certified' => ['mark_paid' => ['label' => 'Mark paid', 'icon' => 'banknote', 'confirm' => 'Mark '.$this->money($record->amount).' as paid?']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'mark_paid') {
            $record->update(['status' => 'paid']);

            return $record->title.' is paid.';
        }

        $input = $request->validate(['amount' => ['required', 'numeric', 'min:0'], 'certificate_number' => ['required', 'string', 'max:50']]);
        $amount = (float) $input['amount'];
        if ($amount > $this->number($record, 'claimed')) {
            throw ValidationException::withMessages(['amount' => 'You cannot certify more than the '.$this->money($record->value('claimed')).' claimed.']);
        }
        $subcontract = $this->parent($record, 'subcontract');
        if ($subcontract && ($over = $this->overCertified($subcontract, $amount, $record)) !== null) {
            throw ValidationException::withMessages(['amount' => $over]);
        }
        $record->update(['status' => 'certified', 'amount' => $amount, 'data' => [...(array) $record->data, 'certificate_number' => $input['certificate_number']]]);

        return $record->title.' certified at '.$this->money($amount).' on certificate '.$input['certificate_number'].'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'subcontracts') {
            return [];
        }

        $cards = [];
        $expiry = filled($record->value('insurance_expiry')) ? Carbon::parse($record->value('insurance_expiry')) : null;
        if ($expiry && $expiry->lte(today()->addDays(30)) && ! in_array($record->status, ['complete', 'final_account'], true)) {
            $cards[] = ['view' => 'apps.logic.alert-card', 'data' => ['tone' => $expiry->lt(today()) ? 'danger' : 'warning', 'icon' => 'shield-alert',
                'title' => $expiry->lt(today()) ? 'Insurance expired' : 'Insurance expires soon', 'body' => 'Cover ran out on '.$expiry->format('d M Y').'. Ask for a renewed certificate.']];
        }

        $claims = $this->linked('claims', 'subcontract', $record)->orderByDesc('occurs_on')->get();
        $cards[] = ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Account', 'icon' => 'file-signature', 'stats' => [
            ['label' => 'Value', 'value' => $this->money($record->amount)],
            ['label' => 'Certified', 'value' => $this->money($record->value('_certified'))],
            ['label' => 'Paid', 'value' => $this->money($record->value('_paid'))],
            ['label' => 'Retention held', 'value' => $this->money($record->value('_retention'))],
            ['label' => 'Remaining', 'value' => $this->money($record->value('_remaining')), 'tone' => (float) $record->value('_remaining') < 0 ? 'danger' : null],
        ]]];
        $cards[] = ['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Claims', 'icon' => 'file-check', 'empty' => 'No claims yet.',
            'rows' => $claims->take(10)->map(fn (Record $claim) => [
                'label' => $claim->title, 'sub' => $claim->occurs_on?->format('d M Y').(filled($claim->value('certificate_number')) ? ' · '.$claim->value('certificate_number') : ''),
                'value' => $this->money($claim->amount ?? $claim->value('claimed')), 'href' => $claim->url(),
                'tone' => $claim->status === 'paid' ? 'success' : ($claim->status === 'disputed' ? 'danger' : null),
            ])->values()->all(),
        ]];

        return $cards;
    }

    public function homeCards(): array
    {
        $subcontracts = $this->records('subcontracts')->with('contact')->get();
        $live = $subcontracts->whereNotIn('status', ['complete', 'final_account']);
        $expiring = $live->filter(fn (Record $subcontract) => filled($subcontract->value('insurance_expiry')) && Carbon::parse($subcontract->value('insurance_expiry'))->lte(today()->addDays(30)));
        $toPay = $this->records('claims')->where('status', 'certified')->get()->sortBy('due_on');
        $names = $subcontracts->pluck('title', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Subcontracts', 'icon' => 'users', 'stats' => [
                ['label' => 'On site', 'value' => (string) $subcontracts->where('status', 'on_site')->count()],
                ['label' => 'Certified, unpaid', 'value' => $this->money($toPay->sum('amount')), 'tone' => $toPay->isNotEmpty() ? 'warning' : null],
                ['label' => 'Retention held', 'value' => $this->money($subcontracts->sum(fn (Record $subcontract) => $this->number($subcontract, '_retention')))],
                ['label' => 'Insurance expiring', 'value' => (string) $expiring->count(), 'tone' => $expiring->isNotEmpty() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Claims to pay', 'icon' => 'banknote', 'empty' => 'No certified claims are waiting for payment.',
                'rows' => $toPay->take(10)->map(fn (Record $claim) => [
                    'label' => $claim->title, 'sub' => $names[$claim->value('subcontract')] ?? null, 'value' => $this->money($claim->amount), 'href' => $claim->url(),
                    'tone' => $claim->due_on?->lt(today()) ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $subcontracts = $this->records('subcontracts')->with('contact')->get();
        $statuses = $this->app->entities['subcontracts']->statuses;

        $accounts = $subcontracts->sortBy('title')->map(fn (Record $subcontract) => [
            $subcontract->contact?->name ?? '—', $subcontract->title, $statuses[$subcontract->status] ?? $subcontract->status, $this->money($subcontract->amount),
            $this->money($subcontract->value('_certified')), $this->money($subcontract->value('_paid')), $this->money($subcontract->value('_retention')),
        ])->values()->all();

        $claims = $this->dated('claims', $from, $to)->get();
        $months = collect($this->months($from, $to))->map(fn (string $label, string $month) => [
            $label,
            $this->money($claims->filter(fn (Record $claim) => $claim->occurs_on?->format('Y-m') === $month && $claim->status !== 'disputed')->sum(fn (Record $claim) => $this->number($claim, 'claimed'))),
            $this->money($claims->filter(fn (Record $claim) => $claim->occurs_on?->format('Y-m') === $month)->whereIn('status', self::CERTIFIED)->sum('amount')),
        ])->values()->all();

        return [
            ['title' => 'Subcontract accounts', 'columns' => ['Subcontractor', 'Package', 'Status', 'Value', 'Certified', 'Paid', 'Retention'], 'rows' => $accounts],
            ['title' => 'Claims by month', 'columns' => ['Month', 'Claimed', 'Certified'], 'rows' => $months],
        ];
    }
}
