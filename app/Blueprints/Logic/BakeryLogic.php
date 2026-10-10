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
 * Bakery & confectionery: a custom cake order is confirmed once it has a price, a collection date at
 * least two days out and a deposit of at least half, and then moves forward through baking,
 * decorating and ready to collected. The balance after the deposit is kept and must be paid on
 * collection. Each day, cakes left ready past their collection date are flagged. A bake batch never
 * sells or wastes more than was baked, and goes sold out when nothing is left.
 */
class BakeryLogic extends AppLogic
{
    /**
     * The order of a cake's steps.
     *
     * @var list<string>
     */
    protected const STEPS = ['enquiry', 'confirmed', 'baking', 'decorating', 'ready', 'collected'];

    /**
     * Days needed between confirming a cake and collecting it.
     */
    protected const LEAD_DAYS = 2;

    /**
     * Share of the price taken as a deposit to confirm.
     */
    protected const DEPOSIT_SHARE = 0.5;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'production') {
            $quantity = (int) ($data['quantity'] ?? 0);
            if ((int) ($data['sold'] ?? 0) < 0 || (int) ($data['wasted'] ?? 0) < 0) {
                $errors['data.sold'] = 'Sold and wasted cannot be negative.';
            } elseif ((int) ($data['sold'] ?? 0) + (int) ($data['wasted'] ?? 0) > $quantity) {
                $errors['data.sold'] = 'Sold and wasted come to more than the '.$quantity.' baked.';
            }

            return $errors;
        }

        $status = $payload['status'];
        if ($existing && in_array($existing->status, ['collected', 'cancelled'], true) && $status !== $existing->status) {
            $errors['status'] = 'This order is '.$existing->status.'.';
        }
        if ($existing && $status !== 'cancelled' && array_search($status, self::STEPS, true) < array_search($existing->status, self::STEPS, true)) {
            $errors['status'] = 'An order cannot go back a step.';
        }
        if (filled($data['deposit'] ?? null) && filled($payload['amount'] ?? null) && (float) $data['deposit'] > (float) $payload['amount']) {
            $errors['data.deposit'] = 'The deposit cannot be more than the price.';
        }
        $ordered = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : ($existing?->occurs_on ?? today());
        if (filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt($ordered->copy()->startOfDay())) {
            $errors['due_on'] = 'Collection cannot be before the order.';
        }
        if ($status === 'confirmed' && (! $existing || $existing->status !== 'confirmed')) {
            $problem = $this->confirmProblem(filled($payload['amount'] ?? null) ? (float) $payload['amount'] : null, (float) ($data['deposit'] ?? 0), filled($payload['due_on'] ?? null) ? Carbon::parse($payload['due_on']) : null);
            if ($problem) {
                $errors[$problem[0] === 'deposit' ? 'data.deposit' : $problem[0]] = $problem[1];
            }
        }

        return $errors;
    }

    /**
     * What stops a cake being confirmed, as [field, message].
     *
     * @return array{0: string, 1: string}|null
     */
    protected function confirmProblem(?float $price, float $deposit, ?Carbon $collection): ?array
    {
        if (! $price || $price <= 0) {
            return ['amount', 'Price the cake before confirming.'];
        }
        if (! $collection) {
            return ['due_on', 'Set the collection date before confirming.'];
        }
        if ($collection->lt(today()->addDays(self::LEAD_DAYS))) {
            return ['due_on', 'Custom cakes need '.self::LEAD_DAYS.' days; the earliest collection is '.today()->addDays(self::LEAD_DAYS)->format('d M Y').'.'];
        }
        if ($deposit + 0.001 < $price * self::DEPOSIT_SHARE) {
            return ['deposit', 'Take a deposit of at least '.$this->money($price * self::DEPOSIT_SHARE).' to confirm.'];
        }

        return null;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'production') {
            $record->occurs_on ??= today();
            $quantity = (int) $record->value('quantity');
            $left = max(0, $quantity - (int) $record->value('sold') - (int) $record->value('wasted'));
            $this->put($record, ['_left' => $left, '_waste' => $quantity > 0 ? round((int) $record->value('wasted') / $quantity * 100, 1) : 0]);
            if ($record->status !== 'planned' && $quantity > 0) {
                $record->status = $left === 0 ? 'sold_out' : 'baked';
            }

            return;
        }

        $record->occurs_on ??= today();
        $this->put($record, ['_balance' => round(max(0, (float) $record->amount - $this->number($record, 'deposit')), 2)]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'production') {
            return $record->status === 'planned' ? ['bake' => ['label' => 'Baked', 'icon' => 'croissant']] : ($record->status === 'baked' ? [
                'sell' => ['label' => 'Record sales', 'icon' => 'shopping-basket', 'fields' => [
                    ['name' => 'sold', 'label' => 'Sold so far', 'type' => 'number', 'value' => (int) $record->value('sold')],
                    ['name' => 'wasted', 'label' => 'Wasted so far', 'type' => 'number', 'value' => (int) $record->value('wasted')],
                ]],
            ] : []);
        }

        $cancel = ['cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this cake? The deposit is kept unless you refund it.']];

        return match ($record->status) {
            'enquiry' => ['confirm' => ['label' => 'Confirm', 'icon' => 'check', 'fields' => [['name' => 'deposit', 'label' => 'Deposit received', 'type' => 'number', 'value' => $record->value('deposit')]]], ...$cancel],
            'confirmed' => ['bake' => ['label' => 'Start baking', 'icon' => 'flame'], ...$cancel],
            'baking' => ['decorate' => ['label' => 'Decorating', 'icon' => 'paintbrush']],
            'decorating' => ['ready' => ['label' => 'Ready', 'icon' => 'cake']],
            'ready' => ['collect' => ['label' => 'Collected', 'icon' => 'hand', 'fields' => [['name' => 'paid', 'label' => 'Balance paid', 'type' => 'number', 'value' => $record->value('_balance')]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'production') {
            if ($action === 'bake') {
                $record->update(['status' => 'baked']);

                return (int) $record->value('quantity').' '.$record->title.' baked.';
            }
            $counts = $request->validate(['sold' => ['required', 'integer', 'min:0'], 'wasted' => ['nullable', 'integer', 'min:0']]);
            if ((int) $counts['sold'] + (int) ($counts['wasted'] ?? 0) > (int) $record->value('quantity')) {
                throw ValidationException::withMessages(['sold' => 'Sold and wasted come to more than the '.(int) $record->value('quantity').' baked.']);
            }
            $record->update(['data' => [...$record->data, 'sold' => (int) $counts['sold'], 'wasted' => (int) ($counts['wasted'] ?? 0)]]);

            return $record->title.': '.(int) $record->value('_left').' left'.($record->status === 'sold_out' ? ', sold out.' : '.');
        }

        switch ($action) {
            case 'confirm':
                $deposit = (float) $request->validate(['deposit' => ['required', 'numeric', 'min:0']])['deposit'];
                if ($problem = $this->confirmProblem($record->amount !== null ? (float) $record->amount : null, $deposit, $record->due_on)) {
                    throw ValidationException::withMessages([$problem[0] => $problem[1]]);
                }
                $record->update(['status' => 'confirmed', 'data' => [...$record->data, 'deposit' => $deposit]]);

                return $record->title.' confirmed for '.$record->due_on->format('d M Y').'; '.$this->money($record->value('_balance')).' due on collection.';
            case 'collect':
                $paid = (float) $request->validate(['paid' => ['required', 'numeric', 'min:0']])['paid'];
                if ($paid + 0.001 < (float) $record->value('_balance')) {
                    throw ValidationException::withMessages(['paid' => 'The balance of '.$this->money($record->value('_balance')).' must be paid on collection.']);
                }
                $record->update(['status' => 'collected', 'data' => [...$record->data, '_collected_on' => today()->toDateString(), '_late' => false]]);

                return $record->title.' collected.';
            case 'cancel':
                $record->update(['status' => 'cancelled']);

                return $record->title.' cancelled'.((float) $record->value('deposit') > 0 ? '; '.$this->money($record->value('deposit')).' deposit kept.' : '.');
            default:
                $record->update(['status' => ['bake' => 'baking', 'decorate' => 'decorating', 'ready' => 'ready'][$action]]);

                return $record->title.' is '.$record->status.'.';
        }
    }

    public function daily(Workspace $workspace): int
    {
        $late = $this->records('orders')->where('status', 'ready')->whereNotNull('due_on')->where('due_on', '<', today()->startOfDay())->get()
            ->reject(fn (Record $order) => (bool) $order->value('_late'));
        $late->each(fn (Record $order) => $order->update(['data' => [...$order->data, '_late' => true]]));

        return $late->count();
    }

    public function homeCards(): array
    {
        $due = $this->records('orders')->whereIn('status', ['confirmed', 'baking', 'decorating', 'ready'])->whereNotNull('due_on')
            ->where('due_on', '<=', today()->addDays(3)->endOfDay())->orderBy('due_on')->get();
        $today = $this->records('production')->whereDate('occurs_on', today())->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Cakes due', 'icon' => 'cake', 'empty' => 'No cakes due in the next three days.',
                'rows' => $due->map(fn (Record $order) => [
                    'label' => $order->title, 'sub' => trim($order->value('size').' '.$order->value('flavour')).' · '.($order->value('delivery') ? 'deliver ' : 'collect ').$order->due_on->format('D d M'),
                    'value' => $order->value('_late') ? 'Not collected' : ucfirst($order->status), 'href' => $order->url(),
                    'tone' => $order->value('_late') || ($order->due_on->lte(today()) && $order->status !== 'ready') ? 'danger' : ($order->status === 'ready' ? 'success' : 'warning'),
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today\'s bake', 'icon' => 'croissant', 'stats' => [
                ['label' => 'Baked', 'value' => (string) $today->sum(fn (Record $batch) => (int) $batch->value('quantity'))],
                ['label' => 'Sold', 'value' => (string) $today->sum(fn (Record $batch) => (int) $batch->value('sold'))],
                ['label' => 'Left', 'value' => (string) $today->sum(fn (Record $batch) => (int) $batch->value('_left'))],
                ['label' => 'Wasted', 'value' => (string) $today->sum(fn (Record $batch) => (int) $batch->value('wasted'))],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $cakes = $this->dated('orders', $from, $to)->get();
        $sold = $cakes->whereIn('status', ['confirmed', 'baking', 'decorating', 'ready', 'collected']);

        $byMonth = collect($this->months($from, $to))->map(fn (string $label, string $month) => [$label, $sold->filter(fn (Record $order) => $order->occurs_on?->format('Y-m') === $month)->count(), $this->money($this->sumByMonth($sold)[$month] ?? 0)])->values()->all();

        $byFlavour = $sold->groupBy(fn (Record $order) => ucfirst(mb_strtolower(trim((string) $order->value('flavour')))) ?: '—')->sortKeys()
            ->map(fn (Collection $group, string $flavour) => [$flavour, $group->count(), $this->money($group->sum('amount'))])->values()->all();

        $waste = $this->dated('production', $from, $to)->where('status', '!=', 'planned')->get()
            ->groupBy(fn (Record $batch) => mb_strtolower($batch->title))->sortKeys()
            ->map(function (Collection $group) {
                $baked = $group->sum(fn (Record $batch) => (int) $batch->value('quantity'));
                $wasted = $group->sum(fn (Record $batch) => (int) $batch->value('wasted'));

                return [$group->first()->title, $baked, $group->sum(fn (Record $batch) => (int) $batch->value('sold')), $wasted, $baked ? round($wasted / $baked * 100, 1).'%' : '—'];
            })->values()->all();

        return [
            ['title' => 'Cake orders by month', 'columns' => ['Month', 'Cakes', 'Value'], 'rows' => $byMonth],
            ['title' => 'Cakes by flavour', 'columns' => ['Flavour', 'Cakes', 'Value'], 'rows' => $byFlavour],
            ['title' => 'Production and waste', 'columns' => ['Product', 'Baked', 'Sold', 'Wasted', 'Waste'], 'rows' => $waste],
        ];
    }
}
