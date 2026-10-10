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
 * Affiliate & referral management: referral codes are kept in capitals and used once, and commission rates
 * run from 0 to 50%. Only active affiliates take new referrals. A converted referral needs its sale value
 * and earns the affiliate's rate on it, fixed at the rate when it converted. Converted referrals are paid
 * one at a time or all together.
 */
class AffiliateLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'affiliates') {
            $code = mb_strtoupper(trim((string) ($data['code'] ?? '')));
            if ($code !== '' && $this->records('affiliates')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $affiliate) => mb_strtoupper(trim((string) $affiliate->value('code'))) === $code)) {
                $errors['data.code'] = 'Referral code '.$code.' is already taken.';
            }
            if ((float) ($data['commission_rate'] ?? 0) < 0 || (float) ($data['commission_rate'] ?? 0) > 50) {
                $errors['data.commission_rate'] = 'Commission must be between 0 and 50%.';
            }

            return $errors;
        }
        if (! $existing && filled($data['affiliate'] ?? null) && ($affiliate = $this->records('affiliates')->find($data['affiliate'])) && $affiliate->status !== 'active') {
            $errors['data.affiliate'] = $affiliate->title.' is '.$affiliate->status.' and cannot take new referrals.';
        }
        if (in_array($payload['status'], ['converted', 'paid'], true) && (float) ($data['sale_value'] ?? 0) <= 0) {
            $errors['data.sale_value'] = 'Give the sale value.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'affiliates') {
            $this->put($record, ['code' => mb_strtoupper(trim((string) $record->value('code')))]);

            return;
        }
        $record->occurs_on ??= today();
        if (! in_array($record->status, ['converted', 'paid'], true)) {
            $record->amount = 0;
            $this->put($record, ['_rate' => null]);

            return;
        }
        $rate = $record->value('_rate') ?? $this->number($this->parent($record, 'affiliate') ?? new Record, 'commission_rate');
        $this->put($record, ['_rate' => (float) $rate]);
        $record->amount = round($this->number($record, 'sale_value') * (float) $rate / 100, 2);
    }

    /**
     * An affiliate's converted referrals that have not been paid yet.
     *
     * @return Collection<int, Record>
     */
    protected function owed(Record $affiliate): Collection
    {
        return $this->linked('referrals', 'affiliate', $affiliate)->where('status', 'converted')->get();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'affiliates') {
            return $this->owed($record)->isNotEmpty() ? ['pay_all' => ['label' => 'Pay commission owed', 'icon' => 'banknote']] : [];
        }

        return match ($record->status) {
            'pending' => [
                'convert' => ['label' => 'Converted', 'icon' => 'check', 'fields' => [['name' => 'sale_value', 'label' => 'Sale value', 'type' => 'number', 'value' => $record->value('sale_value')]]],
                'reject' => ['label' => 'Reject', 'icon' => 'x'],
            ],
            'converted' => ['pay' => ['label' => 'Commission paid', 'icon' => 'banknote']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'convert':
                $value = (float) ($request->validate(['sale_value' => ['nullable', 'numeric', 'min:0']])['sale_value'] ?? 0) ?: $this->number($record, 'sale_value');
                if ($value <= 0) {
                    throw ValidationException::withMessages(['sale_value' => 'Give the sale value.']);
                }
                $record->update(['status' => 'converted', 'data' => [...$record->data, 'sale_value' => $value]]);

                return $record->title.' converted; '.$this->money($record->amount).' commission to '.($this->parent($record, 'affiliate')?->title ?? 'the affiliate').'.';
            case 'reject':
                $record->update(['status' => 'rejected']);

                return $record->title.' rejected.';
            case 'pay':
                $record->update(['status' => 'paid']);

                return 'Paid '.$this->money($record->amount).' commission for '.$record->title.'.';
            default:
                $referrals = $this->owed($record);
                $referrals->each(fn (Record $referral) => $referral->update(['status' => 'paid']));

                return 'Paid '.$record->title.' '.$this->money($referrals->sum('amount')).' for '.$referrals->count().' '.str('referral')->plural($referrals->count()).'.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'affiliates') {
            return [];
        }
        $referrals = $this->linked('referrals', 'affiliate', $record)->orderByDesc('occurs_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Owed '.$this->money($referrals->where('status', 'converted')->sum('amount')).' · paid '.$this->money($referrals->where('status', 'paid')->sum('amount')), 'icon' => 'user-plus', 'empty' => 'No referrals yet.',
            'rows' => $referrals->map(fn (Record $referral) => ['label' => $referral->title, 'sub' => ucfirst($referral->status), 'value' => $this->money($referral->amount), 'href' => $referral->url(), 'tone' => $referral->status === 'converted' ? 'warning' : null])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $referrals = $this->records('referrals')->get();
        $affiliates = $this->records('affiliates')->get()->keyBy('id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Referrals', 'icon' => 'share-2', 'stats' => [
                ['label' => 'Pending', 'value' => $referrals->where('status', 'pending')->count()],
                ['label' => 'Commission owed', 'value' => $this->money($referrals->where('status', 'converted')->sum('amount'))],
                ['label' => 'Conversion rate', 'value' => ($decided = $referrals->whereIn('status', ['converted', 'paid', 'rejected'])->count()) ? round($referrals->whereIn('status', ['converted', 'paid'])->count() / $decided * 100).'%' : '—'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Top affiliates', 'icon' => 'trophy', 'empty' => 'No converted referrals yet.',
                'rows' => $referrals->whereIn('status', ['converted', 'paid'])->groupBy(fn (Record $referral) => (int) $referral->value('affiliate'))
                    ->map(fn ($group, int $id) => ['label' => $affiliates->get($id)?->title ?? 'Unknown', 'sub' => $group->count().' '.str('sale')->plural($group->count()), 'value' => $this->money($group->sum(fn (Record $referral) => $this->number($referral, 'sale_value'))), 'href' => $affiliates->get($id)?->url(), 'total' => $group->sum(fn (Record $referral) => $this->number($referral, 'sale_value'))])
                    ->sortByDesc('total')->take(5)->map(fn (array $row) => collect($row)->except('total')->all())->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $affiliates = $this->records('affiliates')->get()->keyBy('id');

        return [['title' => 'Referrals by affiliate', 'columns' => ['Affiliate', 'Referrals', 'Converted', 'Sales', 'Commission', 'Paid'], 'rows' => $this->dated('referrals', $from, $to)->get()
            ->groupBy(fn (Record $referral) => $affiliates->get((int) $referral->value('affiliate'))?->title ?? 'Unknown')->sortKeys()
            ->map(function ($group, string $affiliate) {
                $won = $group->whereIn('status', ['converted', 'paid']);

                return [$affiliate, $group->count(), $won->count(), $this->money($won->sum(fn (Record $referral) => $this->number($referral, 'sale_value'))), $this->money($won->sum('amount')), $this->money($group->where('status', 'paid')->sum('amount'))];
            })->values()->all()]];
    }
}
