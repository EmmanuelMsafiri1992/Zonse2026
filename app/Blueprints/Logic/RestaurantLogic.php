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
 * Restaurant & kitchen: an order's items are written one per line ("2 x Beef burger") and priced
 * from the menu, so the bill always matches it; dishes that are sold out or not on the menu are
 * refused. Dine-in orders need a table, which is occupied while the order is open and left dirty
 * once it is paid until it is cleared. Orders go to the kitchen, come out ready, are served and
 * paid. Reservations must fit the table and cannot overlap another booking on it within two hours.
 */
class RestaurantLogic extends AppLogic
{
    /**
     * Orders still on the floor.
     *
     * @var list<string>
     */
    protected const OPEN = ['open', 'sent_to_kitchen', 'ready', 'served'];

    /**
     * How long a table is held for a reservation, in minutes.
     */
    protected const SITTING_MINUTES = 120;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'orders') {
            if ($existing && in_array($existing->status, ['paid', 'cancelled'], true) && $payload['status'] !== $existing->status) {
                $errors['status'] = 'This order is '.$existing->status.' and closed.';
            }
            if (($data['type'] ?? null) === 'dine_in' && blank($data['table'] ?? null)) {
                $errors['data.table'] = 'Choose the table for a dine-in order.';
            }
            [, $problems] = $this->priceItems((string) ($data['items'] ?? ''));
            if ($problems && $payload['status'] !== 'cancelled') {
                $errors['data.items'] = implode(' ', $problems);
            }

            return $errors;
        }

        if ($entity->key === 'reservations') {
            $table = filled($data['table'] ?? null) ? $this->records('tables')->find($data['table']) : null;
            if ($table && (int) ($data['party_size'] ?? 0) > (int) $table->value('seats')) {
                $errors['data.party_size'] = $table->title.' seats '.(int) $table->value('seats').'.';
            }
            if ($table && in_array($payload['status'], ['booked', 'seated'], true) && filled($data['time'] ?? null)
                && ($clash = $this->clash($table, $payload['occurs_on'] ?? today()->toDateString(), (string) $data['time'], $existing?->id))) {
                $errors['data.time'] = $table->title.' is booked for '.$clash->title.' at '.$clash->value('time').'.';
            }

            return $errors;
        }

        if ($entity->key === 'tables' && $existing && $payload['status'] === 'free' && $this->openOrders($existing)->isNotEmpty()) {
            $errors['status'] = 'An order is still open at this table.';
        }

        return $errors;
    }

    /**
     * Price order lines against the menu.
     *
     * @return array{0: list<array{dish: string, quantity: int, price: float, category: string}>, 1: list<string>}
     */
    protected function priceItems(string $items): array
    {
        $menu = $this->records('menu')->get()->keyBy(fn (Record $dish) => mb_strtolower(trim($dish->title)));
        $lines = [];
        $problems = [];

        foreach (preg_split('/\R/', $items) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            preg_match('/^\s*(?:(\d+)\s*[x×*]?\s+)?(.+?)\s*$/iu', $line, $match);
            $quantity = max(1, (int) ($match[1] ?? 1));
            $name = trim($match[2] ?? $line);
            $dish = $menu->get(mb_strtolower($name));
            if (! $dish || $dish->status === 'hidden') {
                $problems[] = '"'.$name.'" is not on the menu.';

                continue;
            }
            if ($dish->status === 'sold_out') {
                $problems[] = $dish->title.' is sold out.';

                continue;
            }
            $lines[] = ['dish' => $dish->title, 'quantity' => $quantity, 'price' => (float) $dish->value('price'), 'category' => (string) $dish->value('category')];
        }

        return [$lines, $problems];
    }

    /**
     * Another reservation on the table within a sitting of this time.
     */
    protected function clash(Record $table, string $date, string $time, ?int $except = null): ?Record
    {
        $at = Carbon::parse($date.' '.$time);

        return $this->linked('reservations', 'table', $table)->whereIn('status', ['booked', 'seated'])->whereDate('occurs_on', Carbon::parse($date))
            ->when($except, fn ($query) => $query->whereKeyNot($except))->get()
            ->first(fn (Record $booking) => abs(Carbon::parse($date.' '.$booking->value('time'))->diffInMinutes($at, false)) < self::SITTING_MINUTES);
    }

    /**
     * Orders still open at a table.
     *
     * @return Collection<int, Record>
     */
    protected function openOrders(Record $table): Collection
    {
        return $this->linked('orders', 'table', $table)->whereIn('status', self::OPEN)->get();
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity !== 'orders') {
            return;
        }

        [$lines] = $this->priceItems((string) $record->value('items'));
        $record->amount = round(collect($lines)->sum(fn (array $line) => $line['quantity'] * $line['price']), 2);
        $this->put($record, ['_lines' => $lines, '_covers' => collect($lines)->whereIn('category', ['main', 'special'])->sum('quantity')]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'orders') {
            $this->settle($this->parent($record, 'table'));
            $this->settle($this->previousParent($record, 'table'));
        }
        if ($record->entity === 'reservations' && $record->status === 'seated' && ($table = $this->parent($record, 'table')) && $table->status !== 'occupied') {
            $table->update(['status' => 'occupied']);
        }
    }

    /**
     * Occupy a table with open orders; leave it dirty once its last order is closed.
     */
    protected function settle(?Record $table): void
    {
        if (! $table) {
            return;
        }
        $open = $this->openOrders($table)->isNotEmpty();
        if ($open && $table->status !== 'occupied') {
            $table->update(['status' => 'occupied']);
        } elseif (! $open && $table->status === 'occupied') {
            $table->update(['status' => 'dirty']);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'tables') {
            return $record->status === 'dirty' ? ['clear' => ['label' => 'Cleared & reset', 'icon' => 'sparkles']] : [];
        }
        if ($record->entity === 'reservations') {
            return $record->status === 'booked' ? ['seat' => ['label' => 'Seat guests', 'icon' => 'armchair'], 'no_show' => ['label' => 'No-show', 'icon' => 'user-x']] : [];
        }
        if ($record->entity !== 'orders') {
            return [];
        }

        return match ($record->status) {
            'open' => ['send' => ['label' => 'Send to kitchen', 'icon' => 'chef-hat'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]],
            'sent_to_kitchen' => ['ready' => ['label' => 'Ready', 'icon' => 'bell']],
            'ready' => ['serve' => ['label' => 'Served', 'icon' => 'hand-platter']],
            'served' => ['pay' => ['label' => 'Take payment', 'icon' => 'banknote', 'fields' => [
                ['name' => 'method', 'label' => 'Paid by', 'type' => 'select', 'options' => ['cash' => 'Cash', 'card' => 'Card', 'mobile_money' => 'Mobile money']],
                ['name' => 'tip', 'label' => 'Tip', 'type' => 'number'],
            ]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'clear':
                $record->update(['status' => 'free']);

                return $record->title.' is free.';
            case 'seat':
                $table = $this->parent($record, 'table');
                if ($table && $table->status !== 'free') {
                    throw ValidationException::withMessages(['table' => $table->title.' is '.$table->status.'.']);
                }
                $record->update(['status' => 'seated']);

                return $record->title.' (party of '.(int) $record->value('party_size').') seated'.($table ? ' at '.$table->title : '').'.';
            case 'no_show':
                $record->update(['status' => 'no_show']);

                return $record->title.' marked as a no-show.';
            case 'send':
                if (! $record->value('_lines')) {
                    throw ValidationException::withMessages(['items' => 'Add the dishes first.']);
                }
                $record->update(['status' => 'sent_to_kitchen', 'data' => [...$record->data, '_sent_at' => now()->toDateTimeString()]]);

                return $record->number.' sent to the kitchen: '.collect($record->value('_lines'))->map(fn (array $line) => $line['quantity'].' x '.$line['dish'])->implode(', ').'.';
            case 'ready':
                $minutes = $record->value('_sent_at') ? (int) Carbon::parse($record->value('_sent_at'))->diffInMinutes(now()) : null;
                $record->update(['status' => 'ready', 'data' => [...$record->data, '_ticket_minutes' => $minutes]]);

                return $record->number.' is ready'.($minutes !== null ? ' after '.$minutes.' min' : '').'.';
            case 'serve':
                $record->update(['status' => 'served']);

                return $record->number.' served.';
            case 'pay':
                $input = $request->validate(['method' => ['required', 'in:cash,card,mobile_money'], 'tip' => ['nullable', 'numeric', 'min:0']]);
                $record->update(['status' => 'paid', 'data' => [...$record->data, '_method' => $input['method'], '_tip' => (float) ($input['tip'] ?? 0)]]);

                return $record->number.' paid: '.$this->money($record->amount).' by '.str_replace('_', ' ', $input['method']).((float) ($input['tip'] ?? 0) > 0 ? ' plus '.$this->money($input['tip']).' tip' : '').'.';
            default:
                $reason = $request->validate(['reason' => ['required', 'string', 'max:190']])['reason'];
                $record->update(['status' => 'cancelled', 'data' => [...$record->data, '_cancel_reason' => $reason]]);

                return $record->number.' cancelled.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'orders') {
            return [];
        }

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Bill', 'icon' => 'receipt', 'empty' => 'No dishes yet.',
            'rows' => collect($record->value('_lines') ?? [])->map(fn (array $line) => ['label' => $line['quantity'].' x '.$line['dish'], 'sub' => $this->money($line['price']).' each', 'value' => $this->money($line['quantity'] * $line['price'])])
                ->push(['label' => 'Total', 'value' => $this->money($record->amount)])->values()->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $kitchen = $this->records('orders')->where('status', 'sent_to_kitchen')->orderBy('id')->get();
        $tables = $this->records('tables')->get();
        $today = $this->records('reservations')->whereDate('occurs_on', today())->where('status', 'booked')->get()->sortBy(fn (Record $booking) => $booking->value('time'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Floor', 'icon' => 'armchair', 'stats' => [
                ['label' => 'Free tables', 'value' => $tables->where('status', 'free')->count().' / '.$tables->count()],
                ['label' => 'To clear', 'value' => (string) $tables->where('status', 'dirty')->count(), 'tone' => $tables->where('status', 'dirty')->isNotEmpty() ? 'warning' : null],
                ['label' => 'Bookings today', 'value' => (string) $today->count()],
                ['label' => 'Sales today', 'value' => $this->money($this->records('orders')->where('status', 'paid')->whereDate('occurs_on', today())->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Kitchen queue', 'icon' => 'chef-hat', 'empty' => 'Nothing on the pass.',
                'rows' => $kitchen->map(function (Record $order) {
                    $waiting = $order->value('_sent_at') ? (int) Carbon::parse($order->value('_sent_at'))->diffInMinutes(now()) : 0;

                    return ['label' => $order->number.' · '.($order->related('table')?->title ?? str_replace('_', ' ', (string) $order->value('type'))), 'sub' => collect($order->value('_lines') ?? [])->map(fn (array $line) => $line['quantity'].' x '.$line['dish'])->implode(', '), 'value' => $waiting.' min', 'href' => $order->url(), 'tone' => $waiting >= 20 ? 'danger' : null];
                })->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $paid = $this->dated('orders', $from, $to)->where('status', 'paid')->get();
        $lines = $paid->flatMap(fn (Record $order) => $order->value('_lines') ?? []);

        $dishes = $lines->groupBy('dish')->map(fn (Collection $group, string $dish) => [$dish, ucfirst((string) $group->first()['category']), $group->sum('quantity'), $this->money($group->sum(fn (array $line) => $line['quantity'] * $line['price']))])
            ->sortByDesc(fn (array $row) => $row[2])->values()->all();

        $byType = $paid->groupBy(fn (Record $order) => $order->value('type') ?: 'dine_in')->sortKeys()
            ->map(fn (Collection $group, string $type) => [ucwords(str_replace('_', ' ', $type)), $group->count(), $this->money($group->sum('amount')), $this->money($group->avg('amount')), $this->money($group->sum(fn (Record $order) => (float) $order->value('_tip')))])->values()->all();

        $timed = $this->dated('orders', $from, $to)->get()->filter(fn (Record $order) => $order->value('_ticket_minutes') !== null);

        return [
            ['title' => 'Dishes sold', 'columns' => ['Dish', 'Category', 'Sold', 'Sales'], 'rows' => $dishes],
            ['title' => 'Sales by order type', 'columns' => ['Type', 'Orders', 'Sales', 'Average bill', 'Tips'], 'rows' => $byType],
            ['title' => 'Kitchen times', 'columns' => ['Tickets', 'Average minutes', 'Slowest'], 'rows' => $timed->isEmpty() ? [] : [[$timed->count(), round($timed->avg(fn (Record $order) => (int) $order->value('_ticket_minutes')), 1), $timed->max(fn (Record $order) => (int) $order->value('_ticket_minutes'))]]],
        ];
    }
}
