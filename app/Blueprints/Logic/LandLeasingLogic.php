<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Land leasing: parcel numbers are unique; a parcel takes one active lease at a time, a lease
 * must end after it starts, and parcels the business leases in cannot be leased out; a parcel
 * shows as leased out while it has an active lease, and leases expire on their end date.
 */
class LandLeasingLogic extends AppLogic
{
    public const RENEWAL_WARNING_DAYS = 60;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];

        if ($entity->key === 'parcels' && filled($data['parcel_number'] ?? null)
            && $this->records('parcels')->where('data->parcel_number', $data['parcel_number'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
            return ['data.parcel_number' => 'Parcel number '.$data['parcel_number'].' is already registered.'];
        }

        if ($entity->key !== 'leases') {
            return [];
        }

        $errors = [];
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lte(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The lease must end after it starts.';
        }

        $parcel = ! empty($data['parcel']) ? $this->records('parcels')->find($data['parcel']) : null;
        if ($parcel && $payload['status'] === 'active') {
            if ($parcel->status === 'leased_in') {
                $errors['data.parcel'] = $parcel->title.' is leased in, so it cannot be leased out.';
            } elseif ($other = $this->linked('leases', 'parcel', $parcel)->where('status', 'active')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first()) {
                $errors['data.parcel'] = $parcel->title.' is already leased to '.$other->title.'.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'leases' && ($parcel = $this->parent($record, 'parcel'))) {
            $hectares = $this->number($parcel, 'hectares');
            $this->put($record, ['_rent_per_ha' => $hectares > 0 && (float) $record->amount > 0 ? round((float) $record->amount / $hectares, 2) : null]);
        }

        if ($record->entity === 'parcels' && $record->exists && $record->status !== 'leased_in') {
            $active = $this->linked('leases', 'parcel', $record)->where('status', 'active')->exists();
            if ($active) {
                $record->status = 'leased_out';
            } elseif ($record->status === 'leased_out') {
                $record->status = 'vacant';
            }
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'leases') {
            $this->recalculate($this->parent($record, 'parcel'));
            $this->recalculate($this->previousParent($record, 'parcel'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'leases') {
            $this->recalculate($this->parent($record, 'parcel'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $ended = $this->records('leases')->where('status', 'active')->whereDate('due_on', '<', today())->get();
        $ended->each(fn (Record $lease) => $lease->update(['status' => 'expired']));

        return $ended->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'parcels') {
            return [];
        }

        $leases = $this->linked('leases', 'parcel', $record)->orderByDesc('occurs_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Lease history', 'icon' => 'file-signature', 'empty' => 'Never leased.',
            'rows' => $leases->map(fn (Record $lease) => [
                'label' => $lease->title, 'sub' => ($lease->occurs_on?->format('d M Y') ?? '—').' – '.($lease->due_on?->format('d M Y') ?? 'open'),
                'value' => $this->money($lease->amount).' / yr', 'href' => $lease->url(), 'tone' => $lease->status === 'active' ? 'success' : null,
            ])->values()->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $ending = $this->records('leases')->where('status', 'active')->whereNotNull('due_on')->whereDate('due_on', '<=', today()->addDays(self::RENEWAL_WARNING_DAYS))->orderBy('due_on')->get();
        $parcels = $this->records('parcels')->get();
        $hectares = fn (string $status) => round($parcels->where('status', $status)->sum(fn (Record $parcel) => $this->number($parcel, 'hectares')), 1).' ha';

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Land', 'icon' => 'map', 'stats' => [
                ['label' => 'Owned', 'value' => $hectares('owned')],
                ['label' => 'Leased out', 'value' => $hectares('leased_out')],
                ['label' => 'Leased in', 'value' => $hectares('leased_in')],
                ['label' => 'Vacant', 'value' => $hectares('vacant')],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Leases ending soon', 'icon' => 'calendar-clock', 'empty' => 'No leases end in the next 60 days.',
                'rows' => $ending->map(fn (Record $lease) => ['label' => $lease->title, 'sub' => $this->money($lease->amount).' / yr', 'value' => $lease->due_on->format('d M Y'), 'href' => $lease->url(), 'tone' => 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $parcels = $this->records('parcels')->get()->keyBy('id');
        $roll = $this->records('leases')->where('status', 'active')->with('contact')->orderBy('due_on')->get()->map(function (Record $lease) use ($parcels) {
            $parcel = $parcels[$lease->value('parcel')] ?? null;

            return [$parcel?->title ?? '—', $lease->contact?->name ?? $lease->title, ucfirst(str_replace('_', ' ', (string) ($lease->value('rent_basis') ?? '—'))),
                $this->money($lease->amount), $lease->value('_rent_per_ha') !== null ? $this->money($lease->value('_rent_per_ha')) : '—', $lease->due_on?->format('d M Y') ?? 'Open'];
        })->all();

        $use = $parcels->groupBy(fn (Record $parcel) => (string) ($parcel->value('land_use') ?: 'not given'))->sortKeys()
            ->map(fn ($group, $use) => [ucfirst(str_replace('_', ' ', $use)), $group->count(), round($group->sum(fn (Record $parcel) => $this->number($parcel, 'hectares')), 1)])->values()->all();

        return [
            ['title' => 'Rent roll', 'columns' => ['Parcel', 'Lessee', 'Basis', 'Annual rent', 'Per ha', 'Ends'], 'rows' => $roll],
            ['title' => 'Land use', 'columns' => ['Use', 'Parcels', 'Hectares'], 'rows' => $use],
        ];
    }
}
