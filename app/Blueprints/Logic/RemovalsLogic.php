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
 * Moving & removals: a move goes enquiry → surveyed → quoted → booked → in progress → completed.
 * The survey measures the volume, which sets the smallest crew; the quote is the volume at a rate
 * per cubic metre plus goods-in-transit cover on the declared value. A booked move needs a date,
 * a crew big enough and a truck that isn't on another move that day. Cancelling needs a reason.
 */
class RemovalsLogic extends AppLogic
{
    /**
     * Move steps in order.
     */
    protected const STEPS = ['enquiry', 'surveyed', 'quoted', 'booked', 'in_progress', 'completed'];

    /**
     * Cubic metres one mover handles on a move.
     */
    protected const VOLUME_PER_MOVER = 15;

    /**
     * Goods-in-transit premium as a share of the declared value.
     */
    public const INSURANCE_RATE = 0.015;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];
        $step = array_search($status, self::STEPS, true);

        if ($existing?->status === 'cancelled' && $status !== 'cancelled') {
            return ['status' => 'This move is cancelled.'];
        }
        if ($status === 'cancelled' && $existing?->status !== 'cancelled') {
            return ['status' => 'Use "Cancel" so the reason is recorded.'];
        }
        if ($existing && $existing->status !== 'cancelled' && $step < array_search($existing->status, self::STEPS, true)) {
            $errors['status'] = 'A move cannot go back to '.str_replace('_', ' ', $status).'.';
        }
        if ($this->address($data['from_address'] ?? '') !== '' && $this->address($data['from_address'] ?? '') === $this->address($data['to_address'] ?? '')) {
            $errors['data.to_address'] = 'The new address is the same as the old one.';
        }
        if ($status === 'cancelled' || $step === false) {
            return $errors;
        }
        $volume = (float) ($data['volume'] ?? 0);
        if ($step >= 1 && $volume <= 0) {
            $errors['data.volume'] = 'Record the surveyed volume.';
        }
        if ($step >= 2 && (float) ($payload['amount'] ?? 0) <= 0) {
            $errors['amount'] = 'Enter the quote.';
        }
        if ($step >= 3 && $step <= 4) {
            if (blank($payload['occurs_on'] ?? null)) {
                $errors['occurs_on'] = 'Set the move date.';
            }
            $needed = $this->crewNeeded($volume);
            if ((int) ($data['crew_size'] ?? 0) < $needed) {
                $errors['data.crew_size'] = number_format($volume, 1).' m³ needs a crew of at least '.$needed.'.';
            }
            if (blank($data['truck'] ?? null)) {
                $errors['data.truck'] = 'Assign a truck.';
            } elseif (filled($payload['occurs_on'] ?? null) && ($clash = $this->truckClash((string) $data['truck'], Carbon::parse($payload['occurs_on']), $existing))) {
                $errors['data.truck'] = $data['truck'].' is on '.$clash->title.' that day.';
            }
        }
        if ($step === 5 && $existing?->status !== 'completed') {
            $errors['status'] = 'Use "Complete" so the delivery is signed off.';
        }

        return $errors;
    }

    /**
     * An address in one form for comparison.
     */
    protected function address(mixed $address): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $address));
    }

    /**
     * The smallest crew for a volume.
     */
    public function crewNeeded(float $volume): int
    {
        return max(2, (int) ceil($volume / self::VOLUME_PER_MOVER));
    }

    /**
     * Another move using the truck on the date, if any.
     */
    protected function truckClash(string $truck, Carbon $date, ?Record $except): ?Record
    {
        $truck = $this->address($truck);

        return $this->records('moves')->whereIn('status', ['booked', 'in_progress'])->whereDate('occurs_on', $date->toDateString())
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))->get()
            ->first(fn (Record $move) => $this->address($move->value('truck')) === $truck);
    }

    public function saving(Record $record): void
    {
        $items = collect(preg_split('/\R/', (string) $record->value('inventory')) ?: [])->filter(fn (string $line) => trim($line) !== '')->count();
        $this->put($record, ['_items' => $items, '_crew_needed' => (float) $record->value('volume') > 0 ? $this->crewNeeded((float) $record->value('volume')) : null]);
    }

    public function actions(Record $record): array
    {
        $cancel = ['cancel' => ['label' => 'Cancel', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]];

        return match ($record->status) {
            'enquiry' => ['survey' => ['label' => 'Record survey', 'icon' => 'ruler', 'fields' => [
                ['name' => 'volume', 'label' => 'Volume (m³)', 'type' => 'number'],
                ['name' => 'inventory', 'label' => 'Goods inventory', 'type' => 'textarea', 'value' => $record->value('inventory')],
            ]], ...$cancel],
            'surveyed' => ['quote' => ['label' => 'Quote', 'icon' => 'calculator', 'fields' => [
                ['name' => 'rate', 'label' => 'Rate per m³', 'type' => 'number'],
                ['name' => 'declared_value', 'label' => 'Declared value for insurance (0 for none)', 'type' => 'number', 'value' => 0],
            ]], ...$cancel],
            'quoted' => ['book' => ['label' => 'Book', 'icon' => 'calendar-check', 'fields' => [
                ['name' => 'date', 'label' => 'Move date', 'type' => 'date', 'value' => $record->occurs_on?->toDateString()],
                ['name' => 'truck', 'label' => 'Truck', 'type' => 'text', 'value' => $record->value('truck')],
                ['name' => 'crew_size', 'label' => 'Crew size', 'type' => 'number', 'value' => $record->value('_crew_needed')],
            ]], ...$cancel],
            'booked' => ['start' => ['label' => 'Start move', 'icon' => 'play'], ...$cancel],
            'in_progress' => ['complete' => ['label' => 'Complete', 'icon' => 'check', 'fields' => [['name' => 'damages', 'label' => 'Damages reported (leave empty if none)', 'type' => 'text']]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'survey':
                $values = $request->validate(['volume' => ['required', 'numeric', 'gt:0'], 'inventory' => ['nullable', 'string', 'max:5000']]);
                $record->update(['status' => 'surveyed', 'data' => [...$record->data, 'volume' => (float) $values['volume'], 'inventory' => $values['inventory'] ?? $record->value('inventory')]]);

                return $record->title.' surveyed at '.number_format((float) $values['volume'], 1).' m³; crew of '.$record->value('_crew_needed').' needed.';
            case 'quote':
                $values = $request->validate(['rate' => ['required', 'numeric', 'gt:0'], 'declared_value' => ['nullable', 'numeric', 'min:0']]);
                $declared = (float) ($values['declared_value'] ?? 0);
                $haulage = round($this->number($record, 'volume') * (float) $values['rate'], 2);
                $premium = round($declared * self::INSURANCE_RATE, 2);
                $record->update(['status' => 'quoted', 'amount' => $haulage + $premium, 'data' => [
                    ...$record->data, 'insurance' => $declared > 0, '_rate' => (float) $values['rate'], '_haulage' => $haulage, '_declared_value' => $declared, '_premium' => $premium, '_quoted_on' => today()->toDateString(),
                ]]);

                return $record->title.' quoted at '.$this->money($haulage + $premium).($premium > 0 ? ' including '.$this->money($premium).' cover.' : '.');
            case 'book':
                $values = $request->validate(['date' => ['required', 'date', 'after_or_equal:today'], 'truck' => ['required', 'string', 'max:100'], 'crew_size' => ['required', 'integer', 'min:1']]);
                $needed = $this->crewNeeded($this->number($record, 'volume'));
                if ((int) $values['crew_size'] < $needed) {
                    throw ValidationException::withMessages(['crew_size' => 'This move needs a crew of at least '.$needed.'.']);
                }
                if ($clash = $this->truckClash($values['truck'], Carbon::parse($values['date']), $record)) {
                    throw ValidationException::withMessages(['truck' => $values['truck'].' is on '.$clash->title.' that day.']);
                }
                $record->update(['status' => 'booked', 'occurs_on' => $values['date'], 'data' => [...$record->data, 'truck' => trim($values['truck']), 'crew_size' => (int) $values['crew_size']]]);

                return $record->title.' booked for '.Carbon::parse($values['date'])->format('d M Y').' on '.trim($values['truck']).'.';
            case 'start':
                $record->update(['status' => 'in_progress', 'data' => [...$record->data, '_started_at' => now()->toDateTimeString()]]);

                return $record->title.' under way.';
            case 'complete':
                $values = $request->validate(['damages' => ['nullable', 'string', 'max:500']]);
                $damages = trim((string) ($values['damages'] ?? ''));
                $record->update(['status' => 'completed', 'data' => [...$record->data, '_completed_at' => now()->toDateTimeString(), '_damages' => $damages ?: null]]);

                return $record->title.' completed'.($damages !== '' ? '; damage claim noted.' : '.');
            default:
                $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
                $record->update(['status' => 'cancelled', 'data' => [...$record->data, '_cancelled_from' => $record->status, '_cancel_reason' => $reason]]);

                return $record->title.' cancelled: '.$reason.'.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ((float) $record->value('volume') <= 0) {
            return [];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Survey', 'icon' => 'ruler', 'stats' => array_values(array_filter([
            ['label' => 'Volume', 'value' => number_format($this->number($record, 'volume'), 1).' m³'],
            ['label' => 'Crew needed', 'value' => $record->value('_crew_needed')],
            ['label' => 'Items listed', 'value' => $record->value('_items')],
            $record->value('_haulage') !== null ? ['label' => 'Haulage', 'value' => $this->money($record->value('_haulage'))] : null,
            (float) $record->value('_premium') > 0 ? ['label' => 'Insurance', 'value' => $this->money($record->value('_premium'))] : null,
        ]))]]];
    }

    public function homeCards(): array
    {
        $coming = $this->records('moves')->whereIn('status', ['booked', 'in_progress'])->whereDate('occurs_on', '<=', today()->addDays(7)->toDateString())->with('contact')->orderBy('occurs_on')->get();
        $waiting = $this->records('moves')->where('status', 'quoted')->with('contact')->get()
            ->filter(fn (Record $move) => $move->value('_quoted_on') && Carbon::parse($move->value('_quoted_on'))->lte(today()->subDays(7)));

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Moves this week', 'icon' => 'truck', 'empty' => 'No moves in the next 7 days.',
                'rows' => $coming->map(fn (Record $move) => ['label' => $move->title, 'sub' => $move->contact?->name.' · '.$move->value('truck').' · crew '.$move->value('crew_size'), 'value' => $move->occurs_on?->format('D d M'), 'href' => $move->url(), 'tone' => $move->occurs_on?->lt(today()) ? 'danger' : null])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Quotes to follow up', 'icon' => 'phone', 'empty' => 'No quotes older than a week.',
                'rows' => $waiting->map(fn (Record $move) => ['label' => $move->title, 'sub' => $move->contact?->name, 'value' => $this->money($move->amount), 'href' => $move->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $moves = $this->records('moves')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();
        $reached = fn (string $status) => $moves->filter(function (Record $move) use ($status) {
            $at = $move->status === 'cancelled' ? (string) $move->value('_cancelled_from') : $move->status;

            return array_search($at, self::STEPS, true) >= array_search($status, self::STEPS, true);
        })->count();

        $done = $this->dated('moves', $from, $to)->where('status', 'completed')->get();
        $byTruck = $done->groupBy(fn (Record $move) => (string) $move->value('truck'))->sortKeys()
            ->map(fn (Collection $group, string $truck) => [$truck, $group->count(), number_format($group->sum(fn (Record $move) => $this->number($move, 'volume')), 1).' m³', $this->money($group->sum('amount')), $group->filter(fn (Record $move) => filled($move->value('_damages')))->count()])->values()->all();

        return [
            ['title' => 'Enquiries to moves', 'columns' => ['Stage', 'Moves', 'Of enquiries'], 'rows' => collect(['enquiry' => 'Enquiries', 'surveyed' => 'Surveyed', 'quoted' => 'Quoted', 'booked' => 'Booked', 'completed' => 'Completed'])
                ->map(fn (string $label, string $status) => [$label, $reached($status), $moves->isNotEmpty() ? round($reached($status) / $moves->count() * 100).'%' : '—'])->values()->all()],
            ['title' => 'Completed moves by truck', 'columns' => ['Truck', 'Moves', 'Volume', 'Revenue', 'Damage claims'], 'rows' => $byTruck],
        ];
    }
}
