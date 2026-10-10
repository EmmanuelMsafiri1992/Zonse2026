<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Membership site & paywall: tiers have unique names and a price per month, year or lifetime. A
 * member joins an active tier once per email address, renews a month or a year on from when they
 * are due (lifetime members never renew), and lapses the morning after an unpaid renewal date.
 * Content is unlocked for its minimum tier and every tier worth at least as much a year, and needs
 * a link before it is published. Monthly recurring revenue counts yearly tiers at a twelfth.
 */
class MembershipLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        return match ($entity->key) {
            'tiers' => $this->validateTier($payload, $existing),
            'members' => $this->validateMember($payload, $existing),
            default => $this->validateContent($payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateTier(array $payload, ?Record $existing): array
    {
        $errors = [];
        $name = mb_strtolower(trim((string) $payload['title']));
        if ($this->records('tiers')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $tier) => mb_strtolower(trim((string) $tier->title)) === $name)) {
            $errors['title'] = 'There is already a tier called '.trim((string) $payload['title']).'.';
        }
        if ((float) ($payload['data']['price'] ?? 0) <= 0) {
            $errors['data.price'] = 'Give the tier a price.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateMember(array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        $tier = filled($data['tier'] ?? null) ? $this->records('tiers')->find($data['tier']) : null;
        $changed = ! $existing || (int) $existing->value('tier') !== (int) ($data['tier'] ?? 0);
        if ($tier && $changed && $tier->status !== 'active') {
            $errors['data.tier'] = $tier->title.' is retired and takes no new members.';
        }
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        if ($email !== '' && $payload['status'] === 'active') {
            $twin = $this->records('members')->where('status', 'active')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $member) => mb_strtolower((string) $member->value('email')) === $email);
            if ($twin) {
                $errors['data.email'] = $email.' is already an active member ('.$twin->title.').';
            }
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The renewal date cannot be before the joining date.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateContent(array $payload): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($payload['status'] === 'published' && blank($data['url'] ?? null)) {
            $errors['data.url'] = 'Add the file or page link before publishing.';
        }
        if (filled($data['min_tier'] ?? null) && ($tier = $this->records('tiers')->find($data['min_tier'])) && $tier->status !== 'active' && $payload['status'] === 'published') {
            $errors['data.min_tier'] = $tier->title.' is retired; choose a tier members can still join.';
        }
        if ((float) ($data['price'] ?? 0) < 0) {
            $errors['data.price'] = 'The price cannot be negative.';
        }

        return $errors;
    }

    /**
     * The next renewal date after a date for a tier's interval; null for lifetime tiers.
     */
    public function renewal(?Record $tier, Carbon $from): ?Carbon
    {
        return match ($tier?->value('interval')) {
            'monthly' => $from->copy()->addMonthNoOverflow(),
            'yearly' => $from->copy()->addYearNoOverflow(),
            default => null,
        };
    }

    /**
     * What a tier brings in a month: monthly price, a twelfth of a yearly one, nothing for lifetime.
     */
    public function monthly(?Record $tier): float
    {
        return match ($tier?->value('interval')) {
            'monthly' => $this->number($tier, 'price'),
            'yearly' => round($this->number($tier, 'price') / 12, 2),
            default => 0.0,
        };
    }

    /**
     * How much a tier is worth over a year, for deciding what it unlocks. Lifetime tiers rank highest.
     */
    protected function rank(Record $tier): float
    {
        return match ($tier->value('interval')) {
            'monthly' => $this->number($tier, 'price') * 12,
            'yearly' => $this->number($tier, 'price'),
            default => INF,
        };
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'members') {
            return;
        }
        if (filled($record->value('email'))) {
            $this->put($record, ['email' => mb_strtolower(trim((string) $record->value('email')))]);
        }
        $record->occurs_on ??= today();
        $tier = $this->parent($record, 'tier');
        if (! $record->exists) {
            $record->due_on ??= $this->renewal($tier, Carbon::parse($record->occurs_on));
            $this->put($record, ['_paid' => $tier ? $this->number($tier, 'price') : 0, '_renewals' => 0]);
        }
        if ($record->isDirty('status') && $record->status === 'cancelled') {
            $this->put($record, ['_cancelled_on' => today()->toDateString()]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'members') {
            return [];
        }
        $lifetime = $this->parent($record, 'tier')?->value('interval') === 'lifetime';

        return match ($record->status) {
            'active' => [...($lifetime ? [] : ['renew' => ['label' => 'Renew', 'icon' => 'refresh-cw']]), 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]],
            'expired', 'cancelled' => ['renew' => ['label' => 'Rejoin', 'icon' => 'refresh-cw']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'cancel') {
            $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
            $record->update(['status' => 'cancelled', 'data' => [...$record->data, '_cancel_reason' => $reason]]);

            return $record->title.' cancelled: '.$reason.'.';
        }
        $tier = $this->parent($record, 'tier');
        if (! $tier || $tier->status !== 'active') {
            throw ValidationException::withMessages(['tier' => ($tier?->title ?? 'This tier').' is retired; move the member to another tier.']);
        }
        $from = $record->status === 'active' && $record->due_on && $record->due_on->gt(today()) ? $record->due_on : today();
        $due = $this->renewal($tier, $from);
        $record->update(['status' => 'active', 'due_on' => $due, 'data' => [...$record->data,
            '_paid' => round($this->number($record, '_paid') + $this->number($tier, 'price'), 2),
            '_renewals' => (int) $record->value('_renewals') + 1,
        ]]);

        return $record->title.' renewed on '.$tier->title.($due ? ' until '.$due->format('d M Y') : ' for life').': '.$this->money($tier->value('price')).'.';
    }

    public function daily(Workspace $workspace): int
    {
        $lapsed = $this->records('members')->where('status', 'active')->whereNotNull('due_on')->whereDate('due_on', '<', today()->toDateString())->get();
        $lapsed->each(fn (Record $member) => $member->update(['status' => 'expired']));

        return $lapsed->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'tiers') {
            $members = $this->linked('members', 'tier', $record)->where('status', 'active')->count();

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Members', 'icon' => 'users', 'stats' => [
                ['label' => 'Active members', 'value' => $members],
                ['label' => 'Monthly recurring revenue', 'value' => $this->money($members * $this->monthly($record))],
            ]]]];
        }
        if ($record->entity !== 'content') {
            return [];
        }
        $minimum = $this->parent($record, 'min_tier');
        $tiers = $this->records('tiers')->where('status', 'active')->get()->filter(fn (Record $tier) => ! $minimum || $this->rank($tier) >= $this->rank($minimum))->sortBy(fn (Record $tier) => $this->rank($tier));

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Who can see it', 'icon' => 'lock-open', 'empty' => 'No active tier unlocks this.',
            'rows' => $tiers->map(fn (Record $tier) => ['label' => $tier->title, 'value' => $this->money($tier->value('price')).' '.$tier->value('interval'), 'href' => $tier->url()])->values()->all(),
        ]]];
    }

    /**
     * Monthly recurring revenue from active members.
     */
    protected function recurring(Collection $members, Collection $tiers): float
    {
        return $members->where('status', 'active')->sum(fn (Record $member) => $this->monthly($tiers[$member->value('tier')] ?? null));
    }

    public function homeCards(): array
    {
        $members = $this->records('members')->get();
        $tiers = $this->records('tiers')->get()->keyBy('id');
        $due = $members->where('status', 'active')->filter(fn (Record $member) => $member->due_on && $member->due_on->lte(today()->addDays(7)))->sortBy('due_on');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Membership', 'icon' => 'lock', 'stats' => [
                ['label' => 'Active members', 'value' => $members->where('status', 'active')->count()],
                ['label' => 'Monthly recurring revenue', 'value' => $this->money($this->recurring($members, $tiers))],
                ['label' => 'Renewing in 7 days', 'value' => $due->count()],
                ['label' => 'Cancelled this month', 'value' => $members->filter(fn (Record $member) => $member->status === 'cancelled' && (string) $member->value('_cancelled_on') >= today()->startOfMonth()->toDateString())->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Renewals due', 'icon' => 'refresh-cw', 'empty' => 'No renewals this week.',
                'rows' => $due->map(fn (Record $member) => ['label' => $member->title, 'sub' => ($tiers[$member->value('tier')] ?? null)?->title, 'value' => $member->due_on->format('d M'), 'href' => $member->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $members = $this->records('members')->get();
        $tiers = $this->records('tiers')->orderBy('title')->get();
        $joined = $this->dated('members', $from, $to)->get();

        return [
            ['title' => 'Members by tier', 'columns' => ['Tier', 'Price', 'Active', 'Expired', 'Cancelled', 'Monthly recurring revenue', 'Collected'], 'rows' => $tiers->map(function (Record $tier) use ($members) {
                $group = $members->filter(fn (Record $member) => (int) $member->value('tier') === $tier->id);

                return [$tier->title, $this->money($tier->value('price')).' '.$tier->value('interval'), $group->where('status', 'active')->count(), $group->where('status', 'expired')->count(), $group->where('status', 'cancelled')->count(),
                    $this->money($group->where('status', 'active')->count() * $this->monthly($tier)), $this->money($group->sum(fn (Record $member) => $this->number($member, '_paid')))];
            })->values()->all()],
            ['title' => 'Joins by month', 'columns' => ['Month', 'Joined', 'Since cancelled', 'Since expired'], 'rows' => collect($this->months($from, $to))->map(function (string $label, string $month) use ($joined) {
                $group = $joined->filter(fn (Record $member) => $member->occurs_on?->format('Y-m') === $month);

                return [$label, $group->count(), $group->where('status', 'cancelled')->count(), $group->where('status', 'expired')->count()];
            })->values()->all()],
        ];
    }
}
