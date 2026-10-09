<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Cooperative: members get the next member number when none is given and numbers stay
 * unique; received contributions add up on the member by type (share capital, subscriptions,
 * levies, savings) and members who have exited take no more contributions.
 */
class CooperativeLogic extends AppLogic
{
    public const TYPES = ['share_capital' => 'Share capital', 'subscription' => 'Subscriptions', 'levy' => 'Levies', 'savings' => 'Savings'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];

        if ($entity->key === 'members' && filled($data['member_number'] ?? null)
            && $this->records('members')->where('data->member_number', $data['member_number'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
            return ['data.member_number' => 'Member number '.$data['member_number'].' is already taken.'];
        }

        if ($entity->key === 'contributions' && ! empty($data['member']) && ! $existing) {
            $member = $this->records('members')->find($data['member']);
            if ($member && $member->status === 'exited') {
                return ['data.member' => $member->title.' has left the cooperative.'];
            }
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'members') {
            return;
        }

        if (blank($record->value('member_number'))) {
            $this->put($record, ['member_number' => $this->nextMemberNumber()]);
        }

        if ($record->exists) {
            $received = $this->linked('contributions', 'member', $record)->where('status', 'received')->get();
            $totals = [];
            foreach (self::TYPES as $type => $label) {
                $totals['_'.$type] = round((float) $received->where('data.type', $type)->sum('amount'), 2);
            }
            $this->put($record, $totals);
        }
    }

    public function nextMemberNumber(): string
    {
        $highest = $this->records('members')->get()->map(fn (Record $member) => (int) preg_replace('/\D/', '', (string) $member->value('member_number')))->max() ?? 0;

        return 'M'.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'contributions') {
            $this->recalculate($this->parent($record, 'member'));
            $this->recalculate($this->previousParent($record, 'member'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'contributions') {
            $this->recalculate($this->parent($record, 'member'));
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'members') {
            return [];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Member account', 'icon' => 'coins', 'stats' => [
            ['label' => 'Member number', 'value' => (string) $record->value('member_number')],
            ...array_map(fn (string $type) => ['label' => self::TYPES[$type], 'value' => $this->money($record->value('_'.$type))], array_keys(self::TYPES)),
        ]]]];
    }

    public function homeCards(): array
    {
        $members = $this->records('members')->where('status', 'active')->get();
        $pending = $this->records('contributions')->where('status', 'pending')->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Cooperative', 'icon' => 'users-round', 'stats' => [
            ['label' => 'Active members', 'value' => (string) $members->count()],
            ['label' => 'Share capital', 'value' => $this->money($members->sum(fn (Record $member) => $this->number($member, '_share_capital')))],
            ['label' => 'Savings', 'value' => $this->money($members->sum(fn (Record $member) => $this->number($member, '_savings')))],
            ['label' => 'Pending', 'value' => $pending->count().' · '.$this->money($pending->sum('amount')), 'tone' => $pending->count() ? 'warning' : null],
        ]]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $received = $this->dated('contributions', $from, $to)->where('status', 'received')->get();
        $monthly = collect($this->months($from, $to))->map(function ($label, $month) use ($received) {
            $inMonth = $received->filter(fn (Record $contribution) => ($contribution->occurs_on ?? $contribution->created_at)->format('Y-m') === $month);

            return [$label, ...array_map(fn (string $type) => $this->money($inMonth->where('data.type', $type)->sum('amount')), array_keys(self::TYPES)), $this->money($inMonth->sum('amount'))];
        })->values()->all();

        $register = $this->records('members')->orderBy('title')->get()->map(fn (Record $member) => [
            (string) $member->value('member_number'), $member->title, ucfirst($member->status), (string) ($member->value('village') ?? '—'), $this->money($member->value('_share_capital')), $this->money($member->value('_savings')),
        ])->all();

        return [
            ['title' => 'Contributions by month', 'columns' => ['Month', ...array_values(self::TYPES), 'Total'], 'rows' => $monthly],
            ['title' => 'Member register', 'columns' => ['Number', 'Member', 'Status', 'Village', 'Share capital', 'Savings'], 'rows' => $register],
        ];
    }
}
