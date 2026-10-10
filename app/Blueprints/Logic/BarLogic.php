<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Bar: a tab's items are written one per line as "2 x Castle @ 25", and the tab total is always the
 * sum of those lines. Rounds are added to an open tab, and closing it needs a payment method; a tab
 * left unpaid is put on the unpaid list until it is settled. VIP bookings hold a table or section for
 * the night, so a section is never booked twice on one date, and on settling the night the shortfall
 * against the minimum spend is charged on top of what was spent, less the deposit already taken.
 */
class BarLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'tabs') {
            [, $problems] = $this->parseItems((string) ($data['items'] ?? ''));
            if ($problems) {
                $errors['data.items'] = implode(' ', $problems);
            }
            if ($payload['status'] === 'closed' && blank($data['payment'] ?? null)) {
                $errors['data.payment'] = 'Choose how the tab was paid.';
            }
            if ($existing && $existing->status === 'closed' && $payload['status'] !== 'closed') {
                $errors['status'] = 'This tab is closed.';
            }

            return $errors;
        }

        $section = trim((string) ($data['section'] ?? ''));
        if ($section !== '' && in_array($payload['status'], ['booked', 'arrived'], true)) {
            $date = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today();
            $clash = $this->records('bookings')->whereIn('status', ['booked', 'arrived'])->whereDate('occurs_on', $date)
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $booking) => strcasecmp(trim((string) $booking->value('section')), $section) === 0);
            if ($clash) {
                $errors['data.section'] = $section.' is booked for '.$clash->title.' that night.';
            }
        }

        return $errors;
    }

    /**
     * Read tab lines like "2 x Castle @ 25".
     *
     * @return array{0: list<array{item: string, quantity: int, price: float}>, 1: list<string>}
     */
    protected function parseItems(string $items): array
    {
        $lines = [];
        $problems = [];
        foreach (preg_split('/\R/', $items) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! preg_match('/^\s*(?:(\d+)\s*[x×*]?\s+)?(.+?)\s*@\s*(\d+(?:\.\d+)?)\s*$/iu', $line, $match)) {
                $problems[] = 'Give "'.trim($line).'" a price, like "2 x '.trim($line).' @ 25".';

                continue;
            }
            $lines[] = ['item' => trim($match[2]), 'quantity' => max(1, (int) ($match[1] ?: 1)), 'price' => (float) $match[3]];
        }

        return [$lines, $problems];
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'tabs') {
            [$lines] = $this->parseItems((string) $record->value('items'));
            $record->amount = round(collect($lines)->sum(fn (array $line) => $line['quantity'] * $line['price']), 2);
            $this->put($record, ['_lines' => $lines, '_drinks' => collect($lines)->sum('quantity')]);

            return;
        }

        $minimum = $this->number($record, 'minimum_spend');
        $spent = $record->value('_spent');
        $this->put($record, ['_shortfall' => $spent === null ? null : round(max(0, $minimum - (float) $spent), 2)]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'bookings') {
            return match ($record->status) {
                'booked' => ['arrive' => ['label' => 'Guests arrived', 'icon' => 'party-popper'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
                'arrived' => ['settle' => ['label' => 'Settle the night', 'icon' => 'banknote', 'fields' => [['name' => 'spent', 'label' => 'Total spent', 'type' => 'number']]]],
                default => [],
            };
        }

        $payment = ['name' => 'payment', 'label' => 'Paid by', 'type' => 'select', 'options' => ['cash' => 'Cash', 'card' => 'Card', 'mobile_money' => 'Mobile money', 'account' => 'Account'], 'value' => $record->value('payment')];

        return match ($record->status) {
            'open' => [
                'round' => ['label' => 'Add a round', 'icon' => 'beer', 'fields' => [['name' => 'round', 'label' => 'Drinks (e.g. 2 x Castle @ 25)', 'type' => 'textarea']]],
                'close' => ['label' => 'Close tab', 'icon' => 'check', 'fields' => [$payment]],
                'walk_out' => ['label' => 'Left unpaid', 'icon' => 'triangle-alert'],
            ],
            'unpaid' => ['close' => ['label' => 'Settle', 'icon' => 'check', 'fields' => [$payment]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'round':
                $round = trim($request->validate(['round' => ['required', 'string']])['round']);
                [$lines, $problems] = $this->parseItems($round);
                if ($problems) {
                    throw ValidationException::withMessages(['round' => implode(' ', $problems)]);
                }
                $record->update(['data' => [...$record->data, 'items' => trim($record->value('items')."\n".$round)]]);

                return 'Round of '.collect($lines)->sum('quantity').' added; the tab is '.$this->money($record->fresh()->amount).'.';
            case 'close':
                $payment = $request->validate(['payment' => ['required', 'in:cash,card,mobile_money,account']])['payment'];
                $record->update(['status' => 'closed', 'data' => [...$record->data, 'payment' => $payment, '_closed_at' => now()->toDateTimeString()]]);

                return $record->title.' closed: '.$this->money($record->amount).' by '.str_replace('_', ' ', $payment).'.';
            case 'walk_out':
                $record->update(['status' => 'unpaid']);

                return $record->title.' left '.$this->money($record->amount).' unpaid.';
            case 'arrive':
                $record->update(['status' => 'arrived']);

                return $record->title.' arrived'.($record->value('section') ? ' at '.$record->value('section') : '').'.';
            case 'settle':
                $spent = (float) $request->validate(['spent' => ['required', 'numeric', 'min:0']])['spent'];
                $record->update(['status' => 'completed', 'data' => [...$record->data, '_spent' => $spent]]);
                $record = $record->fresh();
                $due = $spent + (float) $record->value('_shortfall') - (float) $record->amount;

                return $record->title.' spent '.$this->money($spent).((float) $record->value('_shortfall') > 0 ? ', '.$this->money($record->value('_shortfall')).' short of the minimum' : '').'; '.($due >= 0 ? $this->money($due).' to pay after the deposit.' : $this->money(-$due).' of the deposit to return.');
            default:
                $record->update(['status' => 'cancelled']);

                return 'Booking for '.$record->title.' cancelled.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'tabs') {
            return [];
        }

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Tab', 'icon' => 'receipt', 'empty' => 'Nothing on the tab.',
            'rows' => collect($record->value('_lines') ?? [])->map(fn (array $line) => ['label' => $line['quantity'].' x '.$line['item'], 'sub' => $this->money($line['price']).' each', 'value' => $this->money($line['quantity'] * $line['price'])])
                ->push(['label' => 'Total', 'value' => $this->money($record->amount)])->values()->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $open = $this->records('tabs')->where('status', 'open')->get();
        $unpaid = $this->records('tabs')->where('status', 'unpaid')->get();
        $tonight = $this->records('bookings')->whereIn('status', ['booked', 'arrived'])->whereDate('occurs_on', today())->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Tonight', 'icon' => 'wine', 'stats' => [
                ['label' => 'Open tabs', 'value' => $open->count().' · '.$this->money($open->sum('amount'))],
                ['label' => 'Taken today', 'value' => $this->money($this->records('tabs')->where('status', 'closed')->whereDate('occurs_on', today())->sum('amount'))],
                ['label' => 'VIP bookings', 'value' => (string) $tonight->count()],
                ['label' => 'Unpaid tabs', 'value' => $this->money($unpaid->sum('amount')), 'tone' => $unpaid->isNotEmpty() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Unpaid tabs', 'icon' => 'triangle-alert', 'empty' => 'No unpaid tabs.',
                'rows' => $unpaid->map(fn (Record $tab) => ['label' => $tab->title, 'sub' => $tab->occurs_on?->format('d M Y'), 'value' => $this->money($tab->amount), 'href' => $tab->url(), 'tone' => 'danger'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $tabs = $this->dated('tabs', $from, $to)->whereIn('status', ['closed', 'unpaid'])->get();

        $byPayment = $tabs->groupBy(fn (Record $tab) => $tab->status === 'unpaid' ? 'unpaid' : ($tab->value('payment') ?: 'cash'))->sortKeys()
            ->map(fn (Collection $group, string $method) => [ucwords(str_replace('_', ' ', $method)), $group->count(), $this->money($group->sum('amount'))])->values()->all();

        $names = User::query()->whereIn('id', $tabs->map(fn (Record $tab) => $tab->value('bartender'))->filter()->unique())->pluck('name', 'id');
        $byBartender = $tabs->groupBy(fn (Record $tab) => (int) $tab->value('bartender'))
            ->map(fn (Collection $group, int $bartender) => [$names[$bartender] ?? 'Not set', $group->count(), $group->sum(fn (Record $tab) => (int) $tab->value('_drinks')), $this->money($group->where('status', 'closed')->sum('amount')), $this->money($group->where('status', 'unpaid')->sum('amount'))])->values()->all();

        $drinks = $tabs->flatMap(fn (Record $tab) => $tab->value('_lines') ?? [])->groupBy(fn (array $line) => mb_strtolower($line['item']))
            ->map(fn (Collection $group) => [$group->first()['item'], $group->sum('quantity'), $this->money($group->sum(fn (array $line) => $line['quantity'] * $line['price']))])
            ->sortByDesc(fn (array $row) => $row[1])->take(20)->values()->all();

        $vip = $this->dated('bookings', $from, $to)->where('status', 'completed')->get()
            ->map(fn (Record $booking) => [$booking->title, $booking->occurs_on?->format('d M Y'), $this->money($booking->value('minimum_spend')), $this->money($booking->value('_spent')), $this->money($booking->value('_shortfall'))])->values()->all();

        return [
            ['title' => 'Takings by payment', 'columns' => ['Paid by', 'Tabs', 'Amount'], 'rows' => $byPayment],
            ['title' => 'Bartenders', 'columns' => ['Bartender', 'Tabs', 'Drinks', 'Taken', 'Left unpaid'], 'rows' => $byBartender],
            ['title' => 'Best sellers', 'columns' => ['Drink', 'Sold', 'Sales'], 'rows' => $drinks],
            ['title' => 'VIP minimum spend', 'columns' => ['Guest', 'Date', 'Minimum', 'Spent', 'Shortfall'], 'rows' => $vip],
        ];
    }
}
