<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Field & van sales: an active route needs a rep, and only active routes take new visits and loads. A
 * visit where an order was taken needs its sales value, and planned visits left past their date are
 * marked missed each night. A route has one load out at a time. When a load is reconciled, the cash
 * collected is checked against the orders taken on that route that day, and any shortfall is shown.
 */
class VanSalesLogic extends AppLogic
{
    /**
     * Load statuses that are still out with the van.
     *
     * @var list<string>
     */
    public const OUT = ['loaded', 'on_route'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'routes') {
            if ($payload['status'] === 'active' && blank($data['rep'] ?? null)) {
                $errors['data.rep'] = 'Give the rep who drives this route.';
            }

            return $errors;
        }
        $route = filled($data['route'] ?? null) ? $this->records('routes')->find($data['route']) : null;
        if (! $existing && $route && $route->status !== 'active') {
            $errors['data.route'] = 'Route '.$route->title.' is inactive.';
        }
        if ($entity->key === 'visits') {
            if ($payload['status'] === 'visited' && ! empty($data['order_taken']) && (float) ($payload['amount'] ?? 0) <= 0) {
                $errors['amount'] = 'Give the sales value of the order.';
            }

            return $errors;
        }
        if ($route && in_array($payload['status'], self::OUT, true)) {
            $open = $this->linked('loads', 'route', $route)->whereIn('status', self::OUT)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first();
            if ($open) {
                $errors['data.route'] = 'Route '.$route->title.' already has load '.$open->title.' out.';
            }
        }
        if ($payload['status'] === 'reconciled' && blank($data['cash_collected'] ?? null)) {
            $errors['data.cash_collected'] = 'Give the cash collected.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'visits') {
            if ((float) $record->amount > 0 && $record->status === 'visited') {
                $this->put($record, ['order_taken' => true]);
            }
            if ($record->status === 'planned' && $record->occurs_on->lt(today())) {
                $record->status = 'missed';
            }

            return;
        }
        if ($record->entity === 'loads' && $record->status === 'reconciled') {
            $expected = $this->expected($record);
            $this->put($record, ['_expected' => $expected, '_variance' => round($this->number($record, 'cash_collected') - $expected, 2)]);
        }
    }

    /**
     * The value of orders taken on a load's route on the load's date.
     */
    protected function expected(Record $load): float
    {
        if (! $load->value('route')) {
            return 0;
        }

        return round((float) $this->linked('visits', 'route', (int) $load->value('route'))->where('status', 'visited')
            ->whereDate('occurs_on', $load->occurs_on->toDateString())->sum('amount'), 2);
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('visits')->where('status', 'planned')->whereDate('occurs_on', '<', today()->toDateString())->get()
            ->each(fn (Record $visit) => $visit->save())->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'visits') {
            return $record->status === 'planned' ? [
                'visited' => ['label' => 'Visited', 'icon' => 'map-pin', 'fields' => [['name' => 'amount', 'label' => 'Order value (0 if none)', 'type' => 'number', 'value' => '']]],
                'missed' => ['label' => 'Missed', 'icon' => 'x'],
            ] : [];
        }
        if ($record->entity !== 'loads') {
            return [];
        }

        return match ($record->status) {
            'loaded' => ['depart' => ['label' => 'Left the depot', 'icon' => 'truck']],
            'on_route' => ['return' => ['label' => 'Back at the depot', 'icon' => 'warehouse', 'fields' => [['name' => 'items_returned', 'label' => 'Items returned', 'type' => 'textarea', 'value' => '']]]],
            'returned' => ['reconcile' => ['label' => 'Reconcile cash', 'icon' => 'banknote', 'fields' => [['name' => 'cash_collected', 'label' => 'Cash collected', 'type' => 'number', 'value' => $record->value('cash_collected')]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'visited':
                $amount = (float) ($request->validate(['amount' => ['nullable', 'numeric', 'min:0']])['amount'] ?? 0);
                $record->update(['status' => 'visited', 'amount' => $amount, 'data' => [...$record->data, 'order_taken' => $amount > 0]]);

                return $record->title.' visited'.($amount > 0 ? '; order of '.$this->money($amount).'.' : '; no order.');
            case 'missed':
                $record->update(['status' => 'missed']);

                return $record->title.' missed.';
            case 'depart':
                $record->update(['status' => 'on_route']);

                return $record->title.' is on the route.';
            case 'return':
                $returned = trim((string) ($request->validate(['items_returned' => ['nullable', 'string']])['items_returned'] ?? ''));
                $record->update(['status' => 'returned', 'data' => [...$record->data, 'items_returned' => $returned]]);

                return $record->title.' is back at the depot.';
            default:
                $cash = $request->validate(['cash_collected' => ['nullable', 'numeric', 'min:0']])['cash_collected'] ?? null;
                if ($cash === null || $cash === '') {
                    throw ValidationException::withMessages(['cash_collected' => 'Give the cash collected.']);
                }
                $record->update(['status' => 'reconciled', 'data' => [...$record->data, 'cash_collected' => (float) $cash]]);
                $variance = $this->number($record, '_variance');

                return $record->title.' reconciled; '.match (true) {
                    $variance < 0 => 'cash short by '.$this->money(-$variance),
                    $variance > 0 => 'cash over by '.$this->money($variance),
                    default => 'cash matches the orders',
                }.'.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'loads') {
            $expected = $record->status === 'reconciled' ? $this->number($record, '_expected') : $this->expected($record);

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Cash check', 'icon' => 'banknote', 'stats' => [
                ['label' => 'Orders taken', 'value' => $this->money($expected)],
                ['label' => 'Cash collected', 'value' => $this->money($this->number($record, 'cash_collected'))],
                ['label' => 'Difference', 'value' => $record->status === 'reconciled' ? $this->money($this->number($record, '_variance')) : '—'],
            ]]]];
        }
        if ($record->entity !== 'routes') {
            return [];
        }
        $visits = $this->linked('visits', 'route', $record)->orderByDesc('occurs_on')->limit(10)->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Recent visits', 'icon' => 'map-pin', 'empty' => 'No visits on this route yet.',
            'rows' => $visits->map(fn (Record $visit) => ['label' => $visit->title, 'sub' => ucfirst($visit->status).' · '.$visit->occurs_on->format('d M'), 'value' => $this->money($visit->amount), 'href' => $visit->url(), 'tone' => $visit->status === 'missed' ? 'danger' : null])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $today = $this->records('visits')->whereDate('occurs_on', today()->toDateString())->get();
        $routes = $this->records('routes')->get()->keyBy('id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today on the road', 'icon' => 'route', 'stats' => [
                ['label' => 'Visits planned', 'value' => $today->count()],
                ['label' => 'Visited', 'value' => $today->where('status', 'visited')->count()],
                ['label' => 'Sales', 'value' => $this->money($today->where('status', 'visited')->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Loads out', 'icon' => 'truck', 'empty' => 'No vans are out.',
                'rows' => $this->records('loads')->whereIn('status', [...self::OUT, 'returned'])->get()
                    ->map(fn (Record $load) => ['label' => $load->title, 'sub' => $routes->get((int) $load->value('route'))?->title ?? 'No route', 'value' => ucfirst(str_replace('_', ' ', $load->status)), 'href' => $load->url(), 'tone' => $load->status === 'returned' ? 'warning' : null])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $routes = $this->records('routes')->get()->keyBy('id');
        $reps = User::query()->whereIn('id', $routes->map(fn (Record $route) => $route->value('rep'))->filter()->unique())->pluck('name', 'id');
        $cash = $this->dated('loads', $from, $to)->where('status', 'reconciled')->get()->groupBy(fn (Record $load) => (int) $load->value('route'));

        return [['title' => 'Sales by route', 'columns' => ['Route', 'Rep', 'Visits', 'Visited', 'Orders', 'Sales', 'Cash collected'], 'rows' => $this->dated('visits', $from, $to)->get()
            ->groupBy(fn (Record $visit) => (int) $visit->value('route'))
            ->map(function ($group, int $id) use ($routes, $reps, $cash) {
                $visited = $group->where('status', 'visited');

                return [$routes->get($id)?->title ?? 'No route', $reps[$routes->get($id)?->value('rep')] ?? '—', $group->count(), $visited->count(), $visited->filter(fn (Record $visit) => (bool) $visit->value('order_taken'))->count(), $this->money($visited->sum('amount')), $this->money(($cash->get($id) ?? collect())->sum(fn (Record $load) => $this->number($load, 'cash_collected')))];
            })->sortBy(0)->values()->all()]];
    }
}
