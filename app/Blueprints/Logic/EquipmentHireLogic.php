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
 * Equipment & tool hire: a booking lists hire items by name ("100 x Chairs"), and the hire total is
 * each item's daily rate times the quantity and days. Bookings can't take more of an item than is
 * owned and free over the same days, and items in maintenance or retired can't be hired. Bookings go
 * quoted → booked → out → returned → closed. Late returns are charged at the daily rate, damages
 * come off the deposit, and the home page shows what goes out and what is overdue.
 */
class EquipmentHireLogic extends AppLogic
{
    /**
     * Booking steps in order.
     */
    protected const STEPS = ['quoted', 'booked', 'out', 'returned', 'closed'];

    /**
     * Booking statuses that hold items.
     */
    protected const HOLDING = ['booked', 'out'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($entity->key === 'items') {
            if ((float) ($data['quantity'] ?? 0) < 0) {
                $errors['data.quantity'] = 'Quantity owned cannot be negative.';
            }
            if ((float) ($data['daily_rate'] ?? 0) < 0) {
                $errors['data.daily_rate'] = 'The daily rate cannot be negative.';
            }
            $title = mb_strtolower(trim((string) $payload['title']));
            if ($this->records('items')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $item) => mb_strtolower(trim($item->title)) === $title)) {
                $errors['title'] = 'There is already a hire item called '.trim((string) $payload['title']).'.';
            }

            return $errors;
        }

        if (blank($payload['occurs_on'] ?? null) || blank($payload['due_on'] ?? null)) {
            $errors['due_on'] = 'Enter when the items go out and come back.';

            return $errors;
        }
        $out = Carbon::parse($payload['occurs_on']);
        $back = Carbon::parse($payload['due_on']);
        if ($back->lt($out)) {
            $errors['due_on'] = 'The return date cannot be before the items go out.';
        }
        if ($existing && array_search($status, self::STEPS, true) < array_search($existing->status, self::STEPS, true)) {
            $errors['status'] = 'A booking cannot go back to '.$status.'.';
        }
        if ((float) ($data['deposit'] ?? 0) < 0) {
            $errors['data.deposit'] = 'The deposit cannot be negative.';
        }
        [$lines, $problems] = $this->lines((string) ($data['items'] ?? ''));
        if ($problems) {
            $errors['data.items'] = implode(' ', $problems);
        } elseif (in_array($status, ['quoted', ...self::HOLDING], true) && ! in_array($existing?->status, ['returned', 'closed'], true) && ($short = $this->shortages($lines, $out, $back, $existing))) {
            $errors['data.items'] = implode(' ', $short);
        }

        return $errors;
    }

    /**
     * Read item lines like "100 x Chairs" against the hire items.
     *
     * @return array{0: list<array{item: Record, quantity: float}>, 1: list<string>}
     */
    public function lines(string $items): array
    {
        $catalogue = $this->records('items')->get()->keyBy(fn (Record $item) => mb_strtolower(trim($item->title)));
        $lines = [];
        $problems = [];
        foreach (preg_split('/\R/', $items) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! preg_match('/^\s*(\d+)\s*[x×*]?\s+(.+?)\s*$/iu', $line, $match)) {
                $problems[] = 'Start "'.trim($line).'" with a quantity, like "10 x '.trim($line).'".';

                continue;
            }
            $item = $catalogue[mb_strtolower($match[2])] ?? null;
            if (! $item) {
                $problems[] = 'There is no hire item called "'.$match[2].'".';

                continue;
            }
            $lines[] = ['item' => $item, 'quantity' => (float) $match[1]];
        }

        return [$lines, $problems];
    }

    /**
     * Items the booking wants more of than are free between the dates.
     *
     * @param  list<array{item: Record, quantity: float}>  $lines
     * @return list<string>
     */
    protected function shortages(array $lines, Carbon $out, Carbon $back, ?Record $existing): array
    {
        $overlapping = $this->records('bookings')->whereIn('status', self::HOLDING)
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))
            ->whereDate('occurs_on', '<=', $back->toDateString())->whereDate('due_on', '>=', $out->toDateString())->get();
        $held = [];
        foreach ($overlapping as $booking) {
            foreach ($booking->value('_lines') ?? [] as $line) {
                $held[$line['id']] = ($held[$line['id']] ?? 0) + $line['quantity'];
            }
        }

        $problems = [];
        foreach (collect($lines)->groupBy(fn (array $line) => $line['item']->id) as $id => $group) {
            $item = $group->first()['item'];
            if (in_array($item->status, ['maintenance', 'retired'], true)) {
                $problems[] = $item->title.' is '.$item->status.'.';

                continue;
            }
            $free = $this->number($item, 'quantity') - ($held[$id] ?? 0);
            if ($group->sum('quantity') > $free) {
                $problems[] = 'Only '.max(0, $free).' '.$item->title.' free on those dates.';
            }
        }

        return $problems;
    }

    /**
     * Days the booking covers, counting both the out and return days.
     */
    protected function days(Record $booking): int
    {
        return $booking->occurs_on && $booking->due_on ? (int) $booking->occurs_on->copy()->startOfDay()->diffInDays($booking->due_on->copy()->startOfDay()) + 1 : 1;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'bookings') {
            return;
        }
        [$lines] = $this->lines((string) $record->value('items'));
        $days = $this->days($record);
        $daily = collect($lines)->sum(fn (array $line) => $line['quantity'] * $this->number($line['item'], 'daily_rate'));
        $record->amount = round($daily * $days, 2);
        $this->put($record, [
            '_lines' => collect($lines)->map(fn (array $line) => ['id' => $line['item']->id, 'item' => $line['item']->title, 'quantity' => $line['quantity'], 'rate' => $this->number($line['item'], 'daily_rate')])->all(),
            '_days' => $days,
            '_daily' => round($daily, 2),
        ]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'bookings') {
            return [];
        }

        return match ($record->status) {
            'quoted' => ['book' => ['label' => 'Confirm booking', 'icon' => 'calendar-check']],
            'booked' => ['dispatch' => ['label' => 'Send out', 'icon' => 'truck']],
            'out' => ['return' => ['label' => 'Returned', 'icon' => 'undo-2', 'fields' => [
                ['name' => 'damages', 'label' => 'Damages / missing', 'type' => 'text'],
                ['name' => 'damage_charge', 'label' => 'Damage charge', 'type' => 'number', 'value' => 0],
            ]]],
            'returned' => ['close' => ['label' => 'Close', 'icon' => 'check']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'book':
                [$lines] = $this->lines((string) $record->value('items'));
                if ($short = $this->shortages($lines, $record->occurs_on, $record->due_on, $record)) {
                    throw ValidationException::withMessages(['items' => implode(' ', $short)]);
                }
                $record->update(['status' => 'booked']);

                return $record->title.' booked: '.$this->money($record->amount).' for '.$record->value('_days').' '.str('day')->plural((int) $record->value('_days')).'.';
            case 'dispatch':
                $record->update(['status' => 'out', 'data' => [...$record->data, '_out_on' => today()->toDateString()]]);

                return $record->title.' sent out.';
            case 'return':
                $values = $request->validate(['damages' => ['nullable', 'string', 'max:500'], 'damage_charge' => ['nullable', 'numeric', 'min:0']]);
                $charge = (float) ($values['damage_charge'] ?? 0);
                if ($charge > 0 && blank($values['damages'] ?? null)) {
                    throw ValidationException::withMessages(['damages' => 'Describe the damage being charged for.']);
                }
                $late = $record->due_on && today()->gt($record->due_on) ? (int) $record->due_on->copy()->startOfDay()->diffInDays(today()) : 0;
                $lateFee = round($late * $this->number($record, '_daily'), 2);
                $deposit = $this->number($record, 'deposit');
                $kept = min($deposit, $charge + $lateFee);
                $record->update(['status' => 'returned', 'data' => [
                    ...$record->data, 'damages' => $values['damages'] ?? $record->value('damages'),
                    '_returned_on' => today()->toDateString(), '_days_late' => $late, '_late_fee' => $lateFee, '_damage_charge' => $charge,
                    '_deposit_kept' => $kept, '_deposit_refund' => round($deposit - $kept, 2), '_still_owed' => round($charge + $lateFee - $kept, 2),
                ]]);

                return $record->title.' returned'.($late ? ' '.$late.' '.str('day')->plural($late).' late' : '').'; refund '.$this->money($deposit - $kept).' of the deposit'.($charge + $lateFee > $kept ? ' and '.$this->money($charge + $lateFee - $kept).' still owed.' : '.');
            default:
                $record->update(['status' => 'closed']);

                return $record->title.' closed.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'items') {
            $upcoming = $this->records('bookings')->whereIn('status', self::HOLDING)->whereDate('due_on', '>=', today()->toDateString())->orderBy('occurs_on')->get()
                ->map(fn (Record $booking) => [$booking, collect($booking->value('_lines') ?? [])->where('id', $record->id)->sum('quantity')])
                ->filter(fn (array $pair) => $pair[1] > 0);

            return [['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Booked out', 'icon' => 'calendar-range', 'empty' => 'Not booked.',
                'rows' => $upcoming->map(fn (array $pair) => ['label' => $pair[0]->title, 'sub' => $pair[0]->occurs_on->format('d M').' – '.$pair[0]->due_on->format('d M'), 'value' => $pair[1].' of '.$this->number($record, 'quantity'), 'href' => $pair[0]->url()])->values()->all(),
            ]]];
        }
        if (! in_array($record->status, ['returned', 'closed'], true)) {
            return [];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Return', 'icon' => 'undo-2', 'stats' => [
            ['label' => 'Days late', 'value' => (int) $record->value('_days_late'), 'tone' => $record->value('_days_late') ? 'danger' : null],
            ['label' => 'Late fee', 'value' => $this->money($record->value('_late_fee'))],
            ['label' => 'Damage charge', 'value' => $this->money($record->value('_damage_charge'))],
            ['label' => 'Deposit refund', 'value' => $this->money($record->value('_deposit_refund')), 'tone' => 'success'],
        ]]]];
    }

    public function homeCards(): array
    {
        $goingOut = $this->records('bookings')->where('status', 'booked')->whereDate('occurs_on', '<=', today()->addDay()->toDateString())->orderBy('occurs_on')->with('contact')->get();
        $overdue = $this->records('bookings')->where('status', 'out')->whereNotNull('due_on')->where('due_on', '<', today()->startOfDay())->orderBy('due_on')->with('contact')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Going out today and tomorrow', 'icon' => 'truck', 'empty' => 'Nothing going out.',
                'rows' => $goingOut->map(fn (Record $booking) => ['label' => $booking->title, 'sub' => collect($booking->value('_lines') ?? [])->map(fn (array $line) => $line['quantity'].' '.$line['item'])->implode(', '), 'value' => $booking->occurs_on->format('D d M'), 'href' => $booking->url(), 'tone' => $booking->occurs_on->lt(today()) ? 'danger' : null])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Overdue returns', 'icon' => 'alarm-clock', 'empty' => 'Everything is back on time.',
                'rows' => $overdue->map(fn (Record $booking) => ['label' => $booking->title, 'sub' => $booking->contact?->name, 'value' => (int) $booking->due_on->copy()->startOfDay()->diffInDays(today()).' days late', 'href' => $booking->url(), 'tone' => 'danger'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $bookings = $this->dated('bookings', $from, $to)->whereIn('status', ['booked', 'out', 'returned', 'closed'])->get();
        $items = $this->records('items')->get()->keyBy('id');

        $lines = $bookings->flatMap(fn (Record $booking) => collect($booking->value('_lines') ?? [])->map(fn (array $line) => [...$line, 'days' => (int) $booking->value('_days')]));
        $byItem = $lines->groupBy('id')->map(function (Collection $group, int $id) use ($items, $from, $to) {
            $item = $items[$id] ?? null;
            $unitDays = $group->sum(fn (array $line) => $line['quantity'] * $line['days']);
            $capacity = $item ? $this->number($item, 'quantity') * ((int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1) : 0;

            return [$group->first()['item'], $group->count(), $unitDays, $capacity > 0 ? round($unitDays / $capacity * 100, 1).'%' : '—', $this->money($group->sum(fn (array $line) => $line['quantity'] * $line['days'] * $line['rate']))];
        })->sortBy(fn (array $row) => $row[0])->values()->all();

        $returned = $bookings->whereIn('status', ['returned', 'closed']);

        return [
            ['title' => 'Hire by item', 'columns' => ['Item', 'Bookings', 'Unit-days', 'Utilisation', 'Hire value'], 'rows' => $byItem],
            ['title' => 'Returns', 'columns' => ['Measure', 'Value'], 'rows' => [
                ['Bookings returned', $returned->count()],
                ['Returned late', $returned->filter(fn (Record $booking) => (int) $booking->value('_days_late') > 0)->count()],
                ['Late fees', $this->money($returned->sum(fn (Record $booking) => $this->number($booking, '_late_fee')))],
                ['Damage charges', $this->money($returned->sum(fn (Record $booking) => $this->number($booking, '_damage_charge')))],
            ]],
        ];
    }
}
