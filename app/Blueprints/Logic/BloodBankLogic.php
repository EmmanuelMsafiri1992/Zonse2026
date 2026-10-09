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
 * Blood bank: a donor gives at most every 56 days, and each unit collected gets its expiry from
 * its component. Units sit in quarantine until screened; a negative screen releases them and a
 * reactive one discards them and defers the donor. Issues always take the unit that expires
 * first, a unit is never issued past its expiry, and expired units are taken off the shelf daily.
 */
class BloodBankLogic extends AppLogic
{
    /**
     * Days between whole-blood donations.
     */
    protected const DONATION_INTERVAL = 56;

    /**
     * Shelf life of each component, in days from collection.
     */
    protected const SHELF_LIFE = ['whole_blood' => 35, 'red_cells' => 42, 'plasma' => 365, 'platelets' => 5];

    /**
     * Units on the shelf that can still be used.
     */
    protected const IN_STOCK = ['quarantine', 'available', 'reserved'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'donors') {
            return $errors;
        }

        $donor = filled($data['donor'] ?? null) ? $this->records('donors')->find($data['donor']) : null;
        if ($donor && ! $existing) {
            if ($donor->status !== 'eligible') {
                $errors['data.donor'] = $donor->title.' is '.$donor->status.' and cannot donate.';
            } elseif (filled($donor->value('last_donation')) && Carbon::parse($donor->value('last_donation'))->diffInDays(Carbon::parse($payload['occurs_on'] ?? today())) < self::DONATION_INTERVAL) {
                $errors['data.donor'] = $donor->title.' last gave on '.Carbon::parse($donor->value('last_donation'))->format('d M Y').'; the next donation is from '.Carbon::parse($donor->value('last_donation'))->addDays(self::DONATION_INTERVAL)->format('d M Y').'.';
            }
        }
        if ($donor && filled($data['blood_group'] ?? null) && filled($donor->value('blood_group')) && $donor->value('blood_group') !== $data['blood_group']) {
            $errors['data.blood_group'] = 'The donor is '.$this->group($donor->value('blood_group')).'.';
        }
        if (in_array($payload['status'], ['available', 'reserved', 'issued'], true) && ($data['screening'] ?? 'pending') !== 'negative') {
            $errors['status'] = 'Only units that screened negative can leave quarantine.';
        }
        if ($payload['status'] === 'issued' && blank($data['issued_to'] ?? null)) {
            $errors['data.issued_to'] = 'Say who the unit was issued to.';
        }
        if ($payload['status'] === 'issued' && $existing?->status !== 'issued' && $existing?->due_on?->lt(today())) {
            $errors['status'] = 'This unit expired on '.$existing->due_on->format('d M Y').'.';
        }

        return $errors;
    }

    /**
     * A blood group code as people write it, e.g. "O+".
     */
    protected function group(?string $code): string
    {
        return $code ? strtoupper(explode('_', $code)[0]).(str_ends_with($code, '_pos') ? '+' : '−') : '—';
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'donors') {
            $last = $record->value('last_donation');
            $this->put($record, ['_next_donation' => $last ? Carbon::parse($last)->addDays(self::DONATION_INTERVAL)->toDateString() : null]);

            return;
        }

        $record->occurs_on ??= today();
        $this->put($record, ['screening' => $record->value('screening') ?? 'pending']);
        if (! $record->due_on || $record->isDirty('occurs_on') || $record->value('component') !== ($record->getOriginal('data')['component'] ?? null)) {
            $record->due_on = $record->occurs_on->copy()->addDays(self::SHELF_LIFE[$record->value('component')] ?? 35);
        }
        if ($record->value('screening') === 'reactive' && $record->status !== 'discarded') {
            $record->status = 'discarded';
            $this->put($record, ['_discard_reason' => 'Reactive screen']);
        }
        if ($record->status === 'issued') {
            $this->put($record, ['_issued_on' => $record->value('_issued_on') ?? today()->toDateString()]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'units' || ! $donor = $this->parent($record, 'donor')) {
            return;
        }
        $data = $donor->data;
        $status = $donor->status;
        if ($record->wasRecentlyCreated && (blank($donor->value('last_donation')) || $record->occurs_on->gt(Carbon::parse($donor->value('last_donation'))))) {
            $data['last_donation'] = $record->occurs_on->toDateString();
            $data['_next_donation'] = $record->occurs_on->copy()->addDays(self::DONATION_INTERVAL)->toDateString();
            $data['_donations'] = (int) ($data['_donations'] ?? 0) + 1;
        }
        if ($record->value('screening') === 'reactive' && $status === 'eligible') {
            $status = 'deferred';
            $data['_deferred_reason'] = 'Reactive screen on unit '.$record->title;
        }
        if ($data !== $donor->data || $status !== $donor->status) {
            $donor->data = $data;
            $donor->status = $status;
            $donor->saveQuietly();
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'donors') {
            return $record->status === 'deferred' ? ['reinstate' => ['label' => 'Reinstate', 'icon' => 'user-check']] : [];
        }

        return match ($record->status) {
            'quarantine' => [
                'release' => ['label' => 'Screen negative — release', 'icon' => 'shield-check'],
                'reactive' => ['label' => 'Screen reactive — discard', 'icon' => 'shield-alert'],
            ],
            'available', 'reserved' => [
                'issue' => ['label' => 'Issue', 'icon' => 'hand-heart', 'fields' => [['name' => 'issued_to', 'label' => 'Issued to (patient / ward)', 'type' => 'text']]],
                'discard' => ['label' => 'Discard', 'icon' => 'trash', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]],
            ],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'reinstate':
                $record->update(['status' => 'eligible']);

                return $record->title.' can donate again.';
            case 'release':
                $record->update(['status' => 'available', 'data' => [...$record->data, 'screening' => 'negative']]);

                return $record->title.' released to stock.';
            case 'reactive':
                $record->update(['data' => [...$record->data, 'screening' => 'reactive']]);

                return $record->title.' discarded after a reactive screen'.($this->parent($record, 'donor') ? '; the donor is deferred' : '').'.';
            case 'issue':
                $issuedTo = $request->validate(['issued_to' => ['required', 'string']])['issued_to'];
                $first = $this->firstToExpire((string) $record->value('blood_group'), (string) $record->value('component'));
                if ($first && $first->id !== $record->id && $first->due_on->lt($record->due_on)) {
                    throw ValidationException::withMessages(['issued_to' => 'Issue '.$first->title.' first; it expires on '.$first->due_on->format('d M Y').'.']);
                }
                $record->update(['status' => 'issued', 'data' => [...$record->data, 'issued_to' => $issuedTo]]);

                return $record->title.' ('.$this->group($record->value('blood_group')).' '.str_replace('_', ' ', (string) $record->value('component')).') issued to '.$issuedTo.'.';
        }

        $reason = $request->validate(['reason' => ['required', 'string']])['reason'];
        $record->update(['status' => 'discarded', 'data' => [...$record->data, '_discard_reason' => $reason]]);

        return $record->title.' discarded.';
    }

    /**
     * The available unit of a group and component that expires first.
     */
    protected function firstToExpire(string $group, string $component): ?Record
    {
        return $this->records('units')->where('status', 'available')->whereDate('due_on', '>=', today())->orderBy('due_on')->orderBy('id')->get()
            ->first(fn (Record $unit) => $unit->value('blood_group') === $group && $unit->value('component') === $component);
    }

    public function daily(Workspace $workspace): int
    {
        $expired = $this->records('units')->whereIn('status', self::IN_STOCK)->whereDate('due_on', '<', today())->get();
        $expired->each(fn (Record $unit) => $unit->update(['status' => 'expired']));

        return $expired->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'donors') {
            return [];
        }
        $units = $this->linked('units', 'donor', $record)->orderByDesc('occurs_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Donations', 'icon' => 'droplet', 'stats' => [
                ['label' => 'Blood group', 'value' => $this->group($record->value('blood_group'))],
                ['label' => 'Units given', 'value' => (string) $units->count()],
                ['label' => 'Next donation from', 'value' => filled($record->value('_next_donation')) ? Carbon::parse($record->value('_next_donation'))->format('d M Y') : 'Any time'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Units', 'icon' => 'droplet', 'empty' => 'No donations yet.',
                'rows' => $units->map(fn (Record $unit) => [
                    'label' => $unit->title, 'sub' => $unit->occurs_on?->format('d M Y').' · '.str_replace('_', ' ', (string) $unit->value('component')), 'value' => ucfirst($unit->status), 'href' => $unit->url(), 'tone' => $unit->status === 'discarded' ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $stock = $this->records('units')->where('status', 'available')->get();
        $groups = collect(['o_neg', 'o_pos', 'a_neg', 'a_pos', 'b_neg', 'b_pos', 'ab_neg', 'ab_pos']);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Blood bank', 'icon' => 'droplet', 'stats' => [
                ['label' => 'Units available', 'value' => (string) $stock->count()],
                ['label' => 'In quarantine', 'value' => (string) $this->records('units')->where('status', 'quarantine')->count()],
                ['label' => 'Expiring in 3 days', 'value' => (string) $stock->filter(fn (Record $unit) => $unit->due_on?->lte(today()->addDays(3)))->count()],
                ['label' => 'Donors eligible today', 'value' => (string) $this->records('donors')->where('status', 'eligible')->get()->filter(fn (Record $donor) => blank($donor->value('_next_donation')) || Carbon::parse($donor->value('_next_donation'))->lte(today()))->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Stock by blood group', 'icon' => 'layers', 'empty' => 'No stock.',
                'rows' => $groups->map(function (string $group) use ($stock) {
                    $units = $stock->filter(fn (Record $unit) => $unit->value('blood_group') === $group);

                    return [
                        'label' => $this->group($group), 'sub' => $units->countBy(fn (Record $unit) => str_replace('_', ' ', (string) $unit->value('component')))->map(fn (int $count, string $component) => $count.' '.$component)->implode(', '),
                        'value' => (string) $units->count(), 'tone' => $units->count() < 2 ? 'danger' : null,
                    ];
                })->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $units = $this->dated('units', $from, $to)->get();
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($units) {
            $group = $units->filter(fn (Record $unit) => $unit->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->where('status', 'issued')->count(), $group->where('status', 'expired')->count(), $group->where('status', 'discarded')->count()];
        })->values()->all();

        $byGroup = $units->groupBy(fn (Record $unit) => $this->group($unit->value('blood_group')))->sortKeys()
            ->map(fn (Collection $group, string $blood) => [$blood, $group->count(), $group->where('status', 'issued')->count(), $group->whereIn('status', ['expired', 'discarded'])->count(), $group->count() > 0 ? (int) round($group->whereIn('status', ['expired', 'discarded'])->count() / $group->count() * 100).'%' : '—'])->values()->all();

        $wastage = $units->whereIn('status', ['expired', 'discarded'])->groupBy(fn (Record $unit) => $unit->status === 'expired' ? 'Expired' : (string) ($unit->value('_discard_reason') ?? 'Discarded'))->sortKeys()
            ->map(fn (Collection $group, string $reason) => [$reason, $group->count()])->values()->all();

        return [
            ['title' => 'Units by month', 'columns' => ['Month', 'Collected', 'Issued', 'Expired', 'Discarded'], 'rows' => $byMonth],
            ['title' => 'Units by blood group', 'columns' => ['Blood group', 'Collected', 'Issued', 'Wasted', 'Wastage'], 'rows' => $byGroup],
            ['title' => 'Wastage reasons', 'columns' => ['Reason', 'Units'], 'rows' => $wastage],
        ];
    }
}
