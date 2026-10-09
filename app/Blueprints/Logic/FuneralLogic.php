<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Funeral services & policies: each policy has one number, a monthly premium, a cover amount and
 * the date it is paid until; premiums push that date on a month at a time, and a policy lapses by
 * itself two months after it runs out. A funeral is reported with the date of death, needs its
 * documents before it is arranged, happens on or after the death, and when settled draws the
 * policy's cover against the cost and marks the policy claimed.
 */
class FuneralLogic extends AppLogic
{
    public const LAPSE_DAYS = 60;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'policies') {
            $number = strtoupper(trim((string) ($data['policy_number'] ?? '')));
            if ($number !== '' && $this->records('policies')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $policy) => strtoupper(trim((string) $policy->value('policy_number'))) === $number)) {
                $errors['data.policy_number'] = 'Policy '.$number.' already exists.';
            }
            if ((float) ($data['premium'] ?? 0) <= 0) {
                $errors['data.premium'] = 'Enter the monthly premium.';
            }
            if ((float) ($payload['amount'] ?? 0) <= 0) {
                $errors['amount'] = 'Enter the cover amount.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The paid-until date is before the start date.';
            }

            return $errors;
        }

        $policy = ! empty($data['policy']) ? $this->records('policies')->find($data['policy']) : null;
        if ($policy && ! in_array($policy->status, ['active', 'claimed'], true) && (! $existing || (int) $existing->value('policy') !== $policy->id)) {
            $errors['data.policy'] = 'Policy '.($policy->value('policy_number') ?: $policy->title).' is '.$policy->status.'; there is no cover.';
        }
        $death = filled($data['date_of_death'] ?? null) ? Carbon::parse($data['date_of_death']) : null;
        if ($death && $death->gt(today())) {
            $errors['data.date_of_death'] = 'The date of death is in the future.';
        }
        if ($death && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->lt($death)) {
            $errors['occurs_on'] = 'The funeral is on or after the date of death.';
        }
        if ((float) ($payload['amount'] ?? 0) < 0) {
            $errors['amount'] = 'The cost cannot be negative.';
        }
        if (in_array($payload['status'], ['arranged', 'completed', 'paid'], true) && blank($data['death_certificate'] ?? null)) {
            $errors['data.death_certificate'] = 'The death certificate number is needed before the funeral is arranged.';
        }
        if (in_array($payload['status'], ['completed', 'paid'], true) && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
            $errors['status'] = 'The funeral has not taken place yet.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'funerals') {
            $policy = $this->parent($record, 'policy');
            $cover = $policy && in_array($policy->status, ['active', 'claimed'], true) ? (float) $policy->amount : 0.0;
            $cost = (float) $record->amount;
            $this->put($record, [
                '_cover' => round($cover, 2),
                '_shortfall' => round(max(0, $cost - $cover), 2),
                '_days_since_death' => filled($record->value('date_of_death')) ? (int) Carbon::parse($record->value('date_of_death'))->diffInDays(today()) : null,
                '_settled_on' => $record->status === 'paid' ? ($record->value('_settled_on') ?? today()->toDateString()) : null,
            ]);

            return;
        }

        $funerals = $record->exists ? $this->linked('funerals', 'policy', $record)->get() : collect();
        $arrears = $record->status === 'active' && $record->due_on?->lt(today()) ? (int) ceil($record->due_on->diffInDays(today()) / 30) : 0;
        $this->put($record, [
            'policy_number' => strtoupper(trim((string) $record->value('policy_number'))) ?: null,
            'premium' => round((float) $record->value('premium'), 2),
            '_months_in_arrears' => $arrears,
            '_arrears' => round($arrears * (float) $record->value('premium'), 2),
            '_funerals' => $funerals->count(),
            '_claimed' => round($funerals->where('status', 'paid')->sum(fn (Record $funeral) => min((float) $funeral->amount, (float) $funeral->value('_cover'))), 2),
            '_lapsed_on' => $record->status === 'lapsed' ? ($record->value('_lapsed_on') ?? today()->toDateString()) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'funerals') {
            $this->recalculate($this->parent($record, 'policy'));
            $this->recalculate($this->previousParent($record, 'policy'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'funerals') {
            $this->recalculate($this->parent($record, 'policy'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $lapsed = 0;
        foreach ($this->records('policies')->where('status', 'active')->whereNotNull('due_on')->whereDate('due_on', '<', today()->subDays(self::LAPSE_DAYS))->get() as $policy) {
            $policy->update(['status' => 'lapsed']);
            $lapsed++;
        }

        return $lapsed;
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'policies') {
            $premium = ['label' => 'Premium received', 'icon' => 'banknote', 'fields' => [['name' => 'months', 'label' => 'Months paid', 'type' => 'number', 'value' => 1]]];

            return match ($record->status) {
                'active' => ['record_premium' => $premium, 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel policy '.($record->value('policy_number') ?: $record->title).'?']],
                'lapsed' => ['reinstate' => ['label' => 'Reinstate', 'icon' => 'shield-check', 'fields' => [['name' => 'months', 'label' => 'Months paid', 'type' => 'number', 'value' => max(1, (int) $record->value('_months_in_arrears'))]]]],
                default => [],
            };
        }

        return match ($record->status) {
            'reported' => ['documents' => ['label' => 'Documents received', 'icon' => 'file-check', 'fields' => [['name' => 'death_certificate', 'label' => 'Death certificate number', 'type' => 'text', 'value' => $record->value('death_certificate')]]]],
            'documents' => ['arrange' => ['label' => 'Arrange funeral', 'icon' => 'calendar-plus', 'fields' => [
                ['name' => 'occurs_on', 'label' => 'Funeral date', 'type' => 'date', 'value' => $record->occurs_on?->toDateString() ?? today()->addDays(7)->toDateString()],
                ['name' => 'service_venue', 'label' => 'Service venue', 'type' => 'text', 'value' => $record->value('service_venue')],
                ['name' => 'cemetery', 'label' => 'Cemetery / crematorium', 'type' => 'text', 'value' => $record->value('cemetery')],
                ['name' => 'amount', 'label' => 'Funeral cost', 'type' => 'number', 'value' => $record->amount],
            ]]],
            'arranged' => ['complete' => ['label' => 'Funeral held', 'icon' => 'flower-2']],
            'completed' => ['settle' => ['label' => 'Settle', 'icon' => 'badge-check', 'fields' => [['name' => 'amount', 'label' => 'Final cost', 'type' => 'number', 'value' => $record->amount]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'record_premium':
            case 'reinstate':
                $months = (int) $request->validate(['months' => ['required', 'integer', 'min:1', 'max:24']])['months'];
                $base = $record->due_on && $record->due_on->gt(today()) && $record->status === 'active' ? $record->due_on : today();
                $until = $base->copy()->addMonths($months);
                $record->update(['status' => 'active', 'due_on' => $until]);
                $label = $record->value('policy_number') ?: $record->title;

                return ($action === 'reinstate' ? 'Policy '.$label.' reinstated' : 'Premium received for '.$label).'; paid until '.$until->format('d M Y').'.';
            case 'cancel':
                $record->update(['status' => 'cancelled']);

                return 'Policy '.($record->value('policy_number') ?: $record->title).' cancelled.';
            case 'documents':
                $certificate = $request->validate(['death_certificate' => ['required', 'string']])['death_certificate'];
                $record->update(['status' => 'documents', 'data' => [...$record->data, 'death_certificate' => $certificate]]);

                return 'Documents for '.$record->title.' received.';
            case 'arrange':
                $input = $request->validate(['occurs_on' => ['required', 'date'], 'service_venue' => ['nullable', 'string'], 'cemetery' => ['nullable', 'string'], 'amount' => ['nullable', 'numeric', 'min:0']]);
                $day = Carbon::parse($input['occurs_on']);
                if (filled($record->value('date_of_death')) && $day->lt(Carbon::parse($record->value('date_of_death')))) {
                    throw ValidationException::withMessages(['occurs_on' => 'The funeral is on or after the date of death.']);
                }
                $record->update(['status' => 'arranged', 'occurs_on' => $day, 'amount' => $input['amount'] ?? $record->amount, 'data' => [...$record->data, 'service_venue' => $input['service_venue'] ?? $record->value('service_venue'), 'cemetery' => $input['cemetery'] ?? $record->value('cemetery')]]);

                return 'Funeral of '.$record->title.' arranged for '.$day->format('d M Y').($input['service_venue'] ? ' at '.$input['service_venue'] : '').'.';
            case 'complete':
                if ($record->occurs_on?->gt(today())) {
                    throw ValidationException::withMessages(['status' => 'The funeral has not taken place yet.']);
                }
                $record->update(['status' => 'completed']);

                return 'Funeral of '.$record->title.' held.';
        }

        $amount = $request->validate(['amount' => ['nullable', 'numeric', 'min:0']])['amount'] ?? $record->amount;
        $record->update(['status' => 'paid', 'amount' => $amount]);
        $policy = $this->parent($record, 'policy');
        if ($policy && $policy->status === 'active') {
            $policy->update(['status' => 'claimed']);
        }
        $shortfall = (float) $record->fresh()->value('_shortfall');

        return 'Funeral of '.$record->title.' settled'.($policy ? ' against policy '.($policy->value('policy_number') ?: $policy->title) : ' with no policy cover').($shortfall > 0 ? '; the family covers the shortfall.' : '.');
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'funerals') {
            $policy = $this->parent($record, 'policy');

            return [
                ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Claim', 'icon' => 'flower-2', 'stats' => [
                    ['label' => 'Policy', 'value' => $policy ? ($policy->value('policy_number') ?: $policy->title) : 'None'],
                    ['label' => 'Cover', 'value' => $this->money($this->number($record, '_cover'))],
                    ['label' => 'Cost', 'value' => $this->money($record->amount)],
                    ['label' => 'Shortfall', 'value' => $this->money($this->number($record, '_shortfall')), 'tone' => $this->number($record, '_shortfall') > 0 ? 'danger' : 'success'],
                    ['label' => 'Date of death', 'value' => filled($record->value('date_of_death')) ? Carbon::parse($record->value('date_of_death'))->format('d M Y') : '—'],
                    ['label' => 'Funeral', 'value' => $record->occurs_on?->format('d M Y') ?? 'Not arranged'],
                ]]],
            ];
        }

        $funerals = $this->linked('funerals', 'policy', $record)->orderByDesc('occurs_on')->get();
        $plans = $this->app->entities['policies']->field('plan')?->options ?? [];

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Policy', 'icon' => 'shield', 'stats' => [
                ['label' => 'Number', 'value' => $record->value('policy_number') ?: '—'],
                ['label' => 'Plan', 'value' => $plans[$record->value('plan')] ?? ucfirst(str_replace('_', ' ', (string) $record->value('plan')))],
                ['label' => 'Premium', 'value' => $this->money($this->number($record, 'premium')).' a month'],
                ['label' => 'Cover', 'value' => $this->money($record->amount)],
                ['label' => 'Paid until', 'value' => $record->due_on?->format('d M Y') ?? 'Not set', 'tone' => (int) $record->value('_months_in_arrears') > 0 ? 'danger' : null],
                ['label' => 'Arrears', 'value' => (int) $record->value('_months_in_arrears') > 0 ? (int) $record->value('_months_in_arrears').' months, '.$this->money($this->number($record, '_arrears')) : 'None', 'tone' => (int) $record->value('_months_in_arrears') > 0 ? 'danger' : 'success'],
                ['label' => 'Claimed', 'value' => $this->money($this->number($record, '_claimed'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Funerals', 'icon' => 'flower-2', 'empty' => 'No funerals on this policy.',
                'rows' => $funerals->take(10)->map(fn (Record $funeral) => [
                    'label' => $funeral->title, 'sub' => ($funeral->occurs_on?->format('d M Y') ?? 'Not arranged').' · '.ucfirst($funeral->status), 'value' => $this->money($funeral->amount), 'href' => $funeral->url(), 'tone' => $funeral->status === 'paid' ? 'success' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $policies = $this->records('policies')->get();
        $active = $policies->where('status', 'active');
        $arrears = $active->filter(fn (Record $policy) => (int) $policy->value('_months_in_arrears') > 0)->sortBy('due_on');
        $funerals = $this->records('funerals')->get();
        $upcoming = $funerals->filter(fn (Record $funeral) => in_array($funeral->status, ['documents', 'arranged'], true) && $funeral->occurs_on?->gte(today()))->sortBy('occurs_on');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Funeral services', 'icon' => 'flower-2', 'stats' => [
                ['label' => 'Active policies', 'value' => (string) $active->count()],
                ['label' => 'Premiums a month', 'value' => $this->money($active->sum(fn (Record $policy) => (float) $policy->value('premium')))],
                ['label' => 'In arrears', 'value' => (string) $arrears->count(), 'tone' => $arrears->isNotEmpty() ? 'danger' : null],
                ['label' => 'Lapsed', 'value' => (string) $policies->where('status', 'lapsed')->count()],
                ['label' => 'Funerals in progress', 'value' => (string) $funerals->whereIn('status', ['reported', 'documents', 'arranged'])->count()],
                ['label' => 'To settle', 'value' => (string) $funerals->where('status', 'completed')->count(), 'tone' => $funerals->where('status', 'completed')->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Premiums in arrears', 'icon' => 'alert-triangle', 'empty' => 'Every active policy is paid up.',
                'rows' => $arrears->take(10)->map(fn (Record $policy) => [
                    'label' => $policy->title, 'sub' => ($policy->value('policy_number') ?: '').' · paid until '.$policy->due_on->format('d M Y'), 'value' => (int) $policy->value('_months_in_arrears').' months', 'href' => $policy->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Upcoming funerals', 'icon' => 'calendar-days', 'empty' => 'No funerals arranged.',
                'rows' => $upcoming->take(10)->map(fn (Record $funeral) => [
                    'label' => $funeral->title, 'sub' => implode(' · ', array_filter([$funeral->value('service_venue'), $funeral->value('cemetery')])) ?: ucfirst($funeral->status), 'value' => $funeral->occurs_on->format('d M Y'), 'href' => $funeral->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $policies = $this->records('policies')->get();
        $plans = $this->app->entities['policies']->field('plan')?->options ?? [];
        $byPlan = collect($plans)->map(fn (string $label, string $plan) => [
            $label, $policies->where('data.plan', $plan)->where('status', 'active')->count(), $policies->where('data.plan', $plan)->where('status', 'lapsed')->count(), $policies->where('data.plan', $plan)->where('status', 'claimed')->count(), $this->money($policies->where('data.plan', $plan)->where('status', 'active')->sum(fn (Record $policy) => (float) $policy->value('premium'))), $this->money($policies->where('data.plan', $plan)->where('status', 'active')->sum('amount')),
        ])->values()->all();

        $funerals = $this->dated('funerals', $from, $to)->get();
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($funerals) {
            $group = $funerals->filter(fn (Record $funeral) => $funeral->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $funeral) => min((float) $funeral->amount, (float) $funeral->value('_cover')))), $this->money($group->sum(fn (Record $funeral) => (float) $funeral->value('_shortfall')))];
        })->values()->all();

        $claims = $funerals->sortByDesc('occurs_on')->map(fn (Record $funeral) => [
            $funeral->title, $policies->firstWhere('id', (int) $funeral->value('policy'))?->value('policy_number') ?? 'None', $funeral->occurs_on?->format('d M Y') ?? '—', ucfirst($funeral->status), $this->money($funeral->amount), $this->money((float) $funeral->value('_cover')), $this->money((float) $funeral->value('_shortfall')),
        ])->values()->all();

        return [
            ['title' => 'Policies by plan', 'columns' => ['Plan', 'Active', 'Lapsed', 'Claimed', 'Premiums a month', 'Cover'], 'rows' => $byPlan],
            ['title' => 'Funerals by month', 'columns' => ['Month', 'Funerals', 'Cost', 'Covered', 'Shortfall'], 'rows' => $byMonth],
            ['title' => 'Claims', 'columns' => ['Deceased', 'Policy', 'Funeral', 'Status', 'Cost', 'Cover', 'Shortfall'], 'rows' => $claims],
        ];
    }
}
