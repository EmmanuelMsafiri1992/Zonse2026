<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Models\Invoice;

/**
 * Rentals: leases keep their unit's occupancy right, one live lease per unit, rent is
 * invoiced every month (once per period) and goes up by the escalation % each anniversary.
 */
class RentalsLogic extends AppLogic
{
    public const LIVE = ['active', 'notice_given'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'leases' || ! in_array($payload['status'], self::LIVE, true) || empty($payload['data']['unit'])) {
            return [];
        }

        $clash = $this->linked('leases', 'unit', (int) $payload['data']['unit'])->whereIn('status', self::LIVE)
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first();

        return $clash ? ['data.unit' => 'This unit already has a live lease ('.$clash->number.' · '.$clash->title.'). End it first.'] : [];
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'leases' || ! ($record->wasRecentlyCreated || $record->wasChanged(['status', 'data']))) {
            return;
        }

        $this->refreshUnit($record->value('unit'));
        // Still the pre-save values here: Eloquent syncs originals after the "saved" event.
        $previousUnit = ((array) $record->getOriginal('data'))['unit'] ?? null;
        if ($previousUnit && $previousUnit !== $record->value('unit')) {
            $this->refreshUnit($previousUnit);
        }
    }

    /** A unit is occupied while it has a live lease and vacant once the last one ends (units under repair are left alone). */
    protected function refreshUnit(mixed $unitId): void
    {
        $unit = $unitId ? $this->records('units')->find($unitId) : null;
        if (! $unit) {
            return;
        }

        $occupied = $this->linked('leases', 'unit', $unit)->whereIn('status', self::LIVE)->exists();
        $status = $occupied ? 'occupied' : ($unit->status === 'under_repair' ? 'under_repair' : 'vacant');
        if ($unit->status !== $status) {
            $unit->update(['status' => $status]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'leases' || ! in_array($record->status, self::LIVE, true) || ! $this->billing()->available()) {
            return [];
        }

        return ['bill_rent' => ['label' => 'Bill rent for '.today()->format('F Y'), 'icon' => 'receipt']];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $invoice = $this->billRent($record, today());

        return 'Rent invoice '.$invoice->number.' raised for '.$invoice->money($invoice->total).'.';
    }

    /** Invoice one month's rent; the period key stops the same month being billed twice. */
    public function billRent(Record $lease, Carbon $month): Invoice
    {
        $period = $month->format('Y-m');
        $unit = $lease->related('unit');
        $dueDay = min(28, max(1, (int) ($lease->value('payment_day') ?: 1)));

        return $this->billing()->invoice($lease, [[
            'description' => 'Rent for '.$month->format('F Y').($unit ? ' · Unit '.$unit->title : ''),
            'quantity' => 1, 'unit_price' => (float) $lease->amount,
        ]], $period, true, today(), $month->copy()->startOfMonth()->day($dueDay)->max(today()));
    }

    /** Bill this month's rent for every live lease that has started, and apply anniversary escalations. */
    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        $leases = $this->records('leases')->whereIn('status', self::LIVE)->get();

        foreach ($leases as $lease) {
            if ($lease->occurs_on && $lease->occurs_on->isFuture()) {
                continue;
            }
            $changed += $this->escalate($lease) ? 1 : 0;

            if ($lease->due_on && $lease->due_on->lt(today()->startOfMonth())) {
                continue;
            }
            if ($this->billing()->available() && (float) $lease->amount > 0 && ! $lease->invoices()->where('period', today()->format('Y-m'))->exists()) {
                try {
                    $this->billRent($lease, today());
                    $changed++;
                } catch (ValidationException) {
                    // Nothing to bill (no rent set) — skip quietly; the lease page shows why.
                }
            }
        }

        return $changed;
    }

    /** Raise the rent by the escalation % once a year, on the month the lease started. */
    public function escalate(Record $lease): bool
    {
        $percent = (float) $lease->value('escalation_percent');
        $start = $lease->occurs_on;
        if ($percent <= 0 || ! $start || $start->diffInMonths(today()) < 12 || $start->month !== today()->month
            || (int) $lease->value('_escalated_year') === today()->year) {
            return false;
        }

        $old = (float) $lease->amount;
        $new = Money::round($old * (1 + $percent / 100));
        $lease->update(['amount' => $new, 'data' => array_merge((array) $lease->data, ['_escalated_year' => today()->year])]);
        $lease->addComment('Rent escalated by '.rtrim(rtrim(number_format($percent, 2), '0'), '.').'% from '.$this->money($old).' to '.$this->money($new).'.', null, true);

        return true;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'leases') {
            $owing = $this->owingFor([$record->id]);
            $nextEscalation = null;
            if ($record->occurs_on && (float) $record->value('escalation_percent') > 0) {
                $nextEscalation = $record->occurs_on->copy()->year(today()->year);
                if ($nextEscalation->lte(today())) {
                    $nextEscalation->addYear();
                }
            }

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Rent account', 'icon' => 'wallet', 'stats' => [
                ['label' => 'Monthly rent', 'value' => $this->money($record->amount)],
                ['label' => 'Arrears', 'value' => $this->money($owing), 'tone' => $owing > 0 ? 'danger' : 'success'],
                ['label' => 'Months billed', 'value' => (string) $record->invoices()->whereNotNull('period')->count()],
                ['label' => 'Next increase', 'value' => $nextEscalation ? $nextEscalation->format('M Y') : '—'],
            ]]]];
        }

        if ($record->entity === 'units') {
            $lease = $this->linked('leases', 'unit', $record)->whereIn('status', self::LIVE)->with('contact')->first();

            return [['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Current tenant', 'icon' => 'key', 'empty' => 'Vacant.',
                'rows' => $lease ? [[
                    'label' => $lease->contact?->displayName() ?? $lease->title, 'sub' => $lease->number.' · since '.($lease->occurs_on?->format('d M Y') ?? '—'),
                    'value' => $this->money($lease->amount), 'href' => $lease->url(),
                ]] : [],
            ]]];
        }

        return [];
    }

    public function homeCards(): array
    {
        $ending = $this->records('leases')->whereIn('status', self::LIVE)->whereNotNull('due_on')
            ->whereBetween('due_on', [today(), today()->addDays(60)->endOfDay()])->orderBy('due_on')->limit(10)->get();
        $arrears = $this->arrears()->take(10);

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Leases ending in 60 days', 'icon' => 'calendar-x', 'empty' => 'No leases ending soon.',
                'rows' => $ending->map(fn (Record $lease) => ['label' => $lease->title, 'sub' => $lease->number, 'value' => $lease->due_on->format('d M Y'), 'href' => $lease->url()])->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Tenants in arrears', 'icon' => 'alarm-clock', 'empty' => 'Everyone is paid up.',
                'rows' => $arrears->map(fn (array $row) => ['label' => $row['lease']->title, 'sub' => $row['lease']->number, 'value' => $this->money($row['owing']), 'href' => $row['lease']->url()])->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $properties = $this->records('properties')->orderBy('title')->get();
        $units = $this->records('units')->get();
        $occupancy = $properties->map(function (Record $property) use ($units) {
            $own = $units->filter(fn (Record $unit) => (int) $unit->value('property') === $property->id);
            $occupied = $own->where('status', 'occupied')->count();

            return [$property->title, $own->count(), $occupied, $own->where('status', 'vacant')->count(), $own->count() ? round($occupied / $own->count() * 100).'%' : '—'];
        })->all();

        $unitNames = $units->pluck('title', 'id');
        $rentRoll = $this->records('leases')->whereIn('status', self::LIVE)->orderBy('title')->get()
            ->map(fn (Record $lease) => [$lease->title, $unitNames[$lease->value('unit')] ?? '—', $lease->due_on?->format('d M Y') ?? 'Open-ended', $this->money($lease->amount)])->all();

        $collected = Invoice::query()->whereIn('record_id', $this->records('leases')->select('id'))->whereNotNull('period')
            ->whereBetween('issue_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get()
            ->groupBy('period')->sortKeys()
            ->map(fn ($group, $period) => [Carbon::createFromFormat('Y-m', $period)->format('M Y'), $this->money($group->sum('total')), $this->money($group->sum('amount_paid')), $this->money($group->sum('balance'))])
            ->values()->all();

        return [
            ['title' => 'Occupancy', 'columns' => ['Property', 'Units', 'Occupied', 'Vacant', 'Occupancy'], 'rows' => $occupancy],
            ['title' => 'Rent roll', 'columns' => ['Tenant', 'Unit', 'Lease ends', 'Monthly rent'], 'rows' => $rentRoll,
                'note' => 'Total monthly rent: '.$this->money($this->records('leases')->whereIn('status', self::LIVE)->sum('amount'))],
            ['title' => 'Rent billed and collected', 'columns' => ['Month', 'Billed', 'Collected', 'Outstanding'], 'rows' => $this->billing()->available() ? $collected : []],
            ['title' => 'Arrears', 'columns' => ['Tenant', 'Lease', 'Owing'], 'rows' => $this->arrears()->map(fn (array $row) => [$row['lease']->title, $row['lease']->number, $this->money($row['owing'])])->values()->all()],
        ];
    }

    /** @return Collection<int, array{lease: Record, owing: float}> */
    protected function arrears()
    {
        if (! $this->billing()->available()) {
            return collect();
        }

        $owing = Invoice::query()->whereIn('record_id', $this->records('leases')->select('id'))->whereIn('status', Invoice::OPEN_STATUSES)
            ->whereDate('due_date', '<', today())->selectRaw('record_id, sum(balance) as owing')->groupBy('record_id')->pluck('owing', 'record_id');
        $leases = $this->records('leases')->whereKey($owing->keys()->all())->get()->keyBy('id');

        return $owing->map(fn ($amount, $id) => ['lease' => $leases->get($id), 'owing' => (float) $amount])
            ->filter(fn (array $row) => $row['lease'] && $row['owing'] > 0)->sortByDesc('owing')->values();
    }
}
