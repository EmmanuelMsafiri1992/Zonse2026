<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Loyalty & rewards: a member's points balance is their opening points plus everything earned or adjusted,
 * less everything redeemed or expired. Their tier follows lifetime points: silver from 500, gold from 2,000
 * and platinum from 5,000. Earning with a spend and no points gives a point per 10 spent. Only active
 * members earn, and nobody can redeem or expire more than their balance. Reversing a movement undoes it.
 */
class LoyaltyLogic extends AppLogic
{
    public const SPEND_PER_POINT = 10;

    /**
     * Lifetime points needed for each tier, highest first.
     *
     * @var array<string, int>
     */
    public const TIERS = ['platinum' => 5000, 'gold' => 2000, 'silver' => 500, 'bronze' => 0];

    /**
     * Movement types that take points off.
     *
     * @var list<string>
     */
    public const SPENDING = ['redeemed', 'expired'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        if ($entity->key !== 'transactions' || $payload['status'] !== 'posted' || ! filled($data['member'] ?? null) || ! ($member = $this->records('members')->find($data['member']))) {
            return [];
        }
        $type = $data['type'] ?? null;
        $points = (float) ($data['points'] ?? 0);
        if ($type === 'earned' && ! $existing && $member->status !== 'active') {
            return ['data.member' => $member->title.' is inactive and cannot earn points.'];
        }
        if ($type !== 'adjusted' && $points < 0) {
            return ['data.points' => 'Give the points as a positive number.'];
        }
        if ($type === 'earned' && $points <= 0 && (float) ($payload['amount'] ?? 0) < self::SPEND_PER_POINT) {
            return ['data.points' => 'Give the points earned, or a spend of at least '.self::SPEND_PER_POINT.'.'];
        }
        if (in_array($type, self::SPENDING, true)) {
            $available = $this->number($member, 'points') + ($existing && $existing->status === 'posted' && (int) $existing->value('member') === $member->id ? $this->signed($existing) * -1 : 0);
            if ($points <= 0) {
                return ['data.points' => 'Give the points '.$type.'.'];
            }
            if ($points > $available) {
                return ['data.points' => $member->title.' only has '.number_format($available).' points.'];
            }
        }

        return [];
    }

    /**
     * A posted movement's effect on the balance: positive for earned and adjusted, negative for redeemed and expired.
     */
    protected function signed(Record $movement): float
    {
        return in_array($movement->value('type'), self::SPENDING, true) ? -abs($this->number($movement, 'points')) : $this->number($movement, 'points');
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'transactions') {
            if ($record->value('type') === 'earned' && $this->number($record, 'points') <= 0) {
                $this->put($record, ['points' => floor((float) $record->amount / self::SPEND_PER_POINT)]);
            }

            return;
        }
        if (! $record->exists) {
            $this->put($record, ['_opening' => $this->number($record, 'points')]);
        }
        $movements = $record->exists ? $this->linked('transactions', 'member', $record)->where('status', 'posted')->get() : collect();
        $opening = $this->number($record, '_opening');
        $lifetime = $opening + $movements->filter(fn (Record $movement) => $this->signed($movement) > 0)->sum(fn (Record $movement) => $this->signed($movement));
        $this->put($record, [
            'points' => $opening + $movements->sum(fn (Record $movement) => $this->signed($movement)),
            '_lifetime' => $lifetime,
            'tier' => collect(self::TIERS)->search(fn (int $threshold) => $lifetime >= $threshold),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'transactions') {
            $this->parent($record, 'member')?->save();
            $this->previousParent($record, 'member')?->save();
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'transactions') {
            $this->parent($record, 'member')?->save();
        }
    }

    public function actions(Record $record): array
    {
        return $record->entity === 'transactions' && $record->status === 'posted' ? ['reverse' => ['label' => 'Reverse', 'icon' => 'undo-2']] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $member = $this->parent($record, 'member');
        if ($member && $this->signed($record) > 0 && $this->number($member, 'points') < $this->signed($record)) {
            throw ValidationException::withMessages(['status' => $member->title.' has already spent these points.']);
        }
        $record->update(['status' => 'reversed']);

        return ucfirst((string) $record->value('type')).' points reversed; '.$member?->title.' now has '.number_format($this->number($member?->fresh() ?? new Record, 'points')).' points.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'members') {
            return [];
        }
        $movements = $this->linked('transactions', 'member', $record)->orderByDesc('occurs_on')->orderByDesc('id')->limit(20)->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => number_format($this->number($record, 'points')).' points · '.ucfirst((string) $record->value('tier')), 'icon' => 'sparkles', 'empty' => 'No points movements yet.',
            'rows' => $movements->map(fn (Record $movement) => ['label' => $movement->title, 'sub' => ucfirst((string) $movement->value('type')).' · '.$movement->occurs_on?->format('d M Y'), 'value' => ($this->signed($movement) > 0 ? '+' : '').number_format($this->signed($movement)), 'href' => $movement->url(), 'tone' => $movement->status === 'reversed' ? 'muted' : null])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $members = $this->records('members')->where('status', 'active')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Loyalty', 'icon' => 'gift', 'stats' => [
                ['label' => 'Active members', 'value' => $members->count()],
                ['label' => 'Points outstanding', 'value' => number_format($members->sum(fn (Record $member) => $this->number($member, 'points')))],
                ['label' => 'Gold and platinum', 'value' => $members->whereIn('data.tier', ['gold', 'platinum'])->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Top members', 'icon' => 'trophy', 'empty' => 'No members yet.',
                'rows' => $members->sortByDesc(fn (Record $member) => $this->number($member, 'points'))->take(5)->map(fn (Record $member) => ['label' => $member->title, 'sub' => ucfirst((string) $member->value('tier')), 'value' => number_format($this->number($member, 'points')).' pts', 'href' => $member->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $movements = $this->dated('transactions', $from, $to)->where('status', 'posted')->get();

        return [['title' => 'Points by month', 'columns' => ['Month', 'Earned', 'Redeemed', 'Expired', 'Spend'], 'rows' => collect($this->months($from, $to))
            ->map(function (string $label, string $month) use ($movements) {
                $inMonth = $movements->filter(fn (Record $movement) => $movement->occurs_on->format('Y-m') === $month);
                $points = fn (string $type) => number_format($inMonth->filter(fn (Record $movement) => $movement->value('type') === $type)->sum(fn (Record $movement) => $this->number($movement, 'points')));

                return [$label, $points('earned'), $points('redeemed'), $points('expired'), $this->money($inMonth->sum('amount'))];
            })->values()->all()]];
    }
}
