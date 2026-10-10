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
 * LPG cylinder distribution: every cylinder has one serial number, and one past its pressure test
 * can't be filled or sent out. Selling an exchange or a new cylinder hands full cylinders of that
 * size to the customer, so there must be enough in stock; an exchange takes back the empties the
 * customer holds. Cancelling a sale puts the cylinders back. Condemned cylinders stay out of use.
 */
class GasCylinderLogic extends AppLogic
{
    /**
     * Guards against re-entering the cylinder hand-over.
     */
    protected static bool $handing = false;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($entity->key === 'cylinders') {
            $serial = $this->serial((string) $payload['title']);
            if ($this->records('cylinders')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $cylinder) => $this->serial($cylinder->title) === $serial)) {
                $errors['title'] = 'Cylinder '.$serial.' is already registered.';
            }
            if ($existing?->status === 'condemned' && $status !== 'condemned') {
                $errors['status'] = 'This cylinder is condemned.';
            }
            if (in_array($status, ['full', 'with_customer'], true) && $status !== $existing?->status && $this->testOverdue($data['test_due'] ?? null)) {
                $errors['data.test_due'] = 'The pressure test is overdue; test the cylinder before filling it.';
            }
            if ($status === 'with_customer' && blank($payload['contact_id'] ?? null)) {
                $errors['contact_id'] = 'Say which customer holds the cylinder.';
            }

            return $errors;
        }

        $quantity = filled($data['quantity'] ?? null) ? (int) $data['quantity'] : 1;
        if ($quantity < 1) {
            $errors['data.quantity'] = 'Sell at least one cylinder.';
        }
        if ((float) ($payload['amount'] ?? 0) <= 0 && $status !== 'cancelled') {
            $errors['amount'] = 'Enter the sale total.';
        }
        if ($existing && $existing->status === 'cancelled' && $status !== 'cancelled') {
            $errors['status'] = 'This sale is cancelled.';
        }
        if ($existing && $status === 'cancelled' && $existing->status !== 'cancelled') {
            $errors['status'] = 'Use "Cancel" so the cylinders are taken back.';
        }
        $changed = $existing && (($data['type'] ?? null) !== $existing->value('type') || ($data['size'] ?? null) !== $existing->value('size') || $quantity !== (int) $existing->value('quantity'));
        if ($changed && $existing->status !== 'cancelled') {
            $errors['data.quantity'] = 'Cancel the sale and record a new one to change what was sold.';
        }
        if (! $existing && in_array($data['type'] ?? null, ['exchange', 'new_cylinder'], true)) {
            if (blank($payload['contact_id'] ?? null)) {
                $errors['contact_id'] = 'Say which customer is taking the cylinders.';
            }
            $full = $this->available((string) ($data['size'] ?? ''))->count();
            if ($full < $quantity) {
                $errors['data.quantity'] = 'Only '.$full.' full '.($data['size'] ?? '').' '.str('cylinder')->plural($full).' in stock.';
            }
        }

        return $errors;
    }

    /**
     * A serial number in one form however it was typed.
     */
    protected function serial(string $serial): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $serial));
    }

    /**
     * Whether a pressure test date has passed.
     */
    protected function testOverdue(mixed $due): bool
    {
        return filled($due) && Carbon::parse($due)->lt(today());
    }

    /**
     * Full cylinders of a size that are fit to hand over.
     *
     * @return Collection<int, Record>
     */
    protected function available(string $size): Collection
    {
        return $this->records('cylinders')->where('status', 'full')->where('data->size', $size)->orderBy('id')->get()
            ->reject(fn (Record $cylinder) => $this->testOverdue($cylinder->value('test_due')))->values();
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'cylinders') {
            $record->title = $this->serial($record->title);
            if ($record->status !== 'with_customer') {
                $record->contact_id = null;
            }

            return;
        }
        $record->occurs_on ??= today();
        $this->put($record, ['quantity' => (int) ($record->value('quantity') ?: 1), '_kg' => (int) $record->value('size') * (int) ($record->value('quantity') ?: 1)]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'sales' || ! $record->wasRecentlyCreated || static::$handing || ! in_array($record->value('type'), ['exchange', 'new_cylinder'], true)) {
            return;
        }
        $quantity = (int) $record->value('quantity');
        $out = $this->available((string) $record->value('size'))->take($quantity);
        $back = $record->value('type') === 'exchange'
            ? $this->records('cylinders')->where('status', 'with_customer')->where('contact_id', $record->contact_id)->where('data->size', $record->value('size'))->orderBy('id')->limit($quantity)->get()
            : collect();

        static::$handing = true;
        try {
            $out->each(fn (Record $cylinder) => $cylinder->update(['status' => 'with_customer', 'contact_id' => $record->contact_id]));
            $back->each(fn (Record $cylinder) => $cylinder->update(['status' => 'empty']));
            $this->put($record, ['_out' => $out->pluck('id')->all(), '_back' => $back->pluck('id')->all()]);
            $record->saveQuietly();
        } finally {
            static::$handing = false;
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'sales') {
            return match ($record->status) {
                'completed' => [
                    'deliver' => ['label' => 'Delivered', 'icon' => 'truck'],
                    'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]],
                ],
                'delivered' => ['cancel' => ['label' => 'Cancel', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]],
                default => [],
            };
        }

        return match ($record->status) {
            'empty' => [
                'fill' => ['label' => 'Filled', 'icon' => 'flame'],
                'condemn' => ['label' => 'Condemn', 'icon' => 'ban', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]],
                'tested' => ['label' => 'Pressure tested', 'icon' => 'badge-check', 'fields' => [['name' => 'next_due', 'label' => 'Next test due', 'type' => 'date', 'value' => today()->addYears(5)->toDateString()]]],
            ],
            'full' => ['condemn' => ['label' => 'Condemn', 'icon' => 'ban', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]],
            'with_customer' => ['collect' => ['label' => 'Collected empty', 'icon' => 'undo-2']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'deliver':
                $record->update(['status' => 'delivered', 'data' => [...$record->data, 'delivered' => true]]);

                return $record->title.' delivered.';
            case 'cancel':
                $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
                $this->records('cylinders')->whereIn('id', $record->value('_out') ?? [])->where('status', 'with_customer')->get()
                    ->each(fn (Record $cylinder) => $cylinder->update(['status' => 'full']));
                $this->records('cylinders')->whereIn('id', $record->value('_back') ?? [])->where('status', 'empty')->get()
                    ->each(fn (Record $cylinder) => $cylinder->update(['status' => 'with_customer', 'contact_id' => $record->contact_id]));
                $record->update(['status' => 'cancelled', 'data' => [...$record->data, '_cancel_reason' => $reason]]);

                return $record->title.' cancelled; cylinders put back.';
            case 'fill':
                if ($this->testOverdue($record->value('test_due'))) {
                    throw ValidationException::withMessages(['test_due' => $record->title.' is past its pressure test; test it before filling.']);
                }
                $record->update(['status' => 'full']);

                return $record->title.' filled.';
            case 'tested':
                $next = $request->validate(['next_due' => ['required', 'date', 'after:today']])['next_due'];
                $record->update(['data' => [...$record->data, 'test_due' => Carbon::parse($next)->toDateString(), '_tested_on' => today()->toDateString()]]);

                return $record->title.' tested; next test due '.Carbon::parse($next)->format('d M Y').'.';
            case 'condemn':
                $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
                $record->update(['status' => 'condemned', 'data' => [...$record->data, '_condemned_reason' => $reason]]);

                return $record->title.' condemned: '.$reason.'.';
            default:
                $record->update(['status' => 'empty']);

                return $record->title.' collected empty.';
        }
    }

    public function homeCards(): array
    {
        $cylinders = $this->records('cylinders')->where('status', '!=', 'condemned')->get();
        $testDue = $cylinders->filter(fn (Record $cylinder) => filled($cylinder->value('test_due')) && Carbon::parse($cylinder->value('test_due'))->lte(today()->addDays(30)))
            ->sortBy(fn (Record $cylinder) => $cylinder->value('test_due'));

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Full cylinders by size', 'icon' => 'cylinder', 'empty' => 'No cylinders registered.',
                'rows' => $cylinders->groupBy(fn (Record $cylinder) => (string) $cylinder->value('size'))->sortKeysUsing(fn ($a, $b) => (int) $a <=> (int) $b)
                    ->map(fn (Collection $group, string $size) => ['label' => $size, 'sub' => $group->where('status', 'empty')->count().' empty · '.$group->where('status', 'with_customer')->count().' with customers', 'value' => $group->where('status', 'full')->count().' full', 'tone' => $group->where('status', 'full')->isEmpty() ? 'danger' : null])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Pressure tests due', 'icon' => 'badge-check', 'empty' => 'No tests due in the next 30 days.',
                'rows' => $testDue->map(fn (Record $cylinder) => ['label' => $cylinder->title, 'sub' => $cylinder->value('size').' · '.str_replace('_', ' ', $cylinder->status), 'value' => Carbon::parse($cylinder->value('test_due'))->format('d M Y'), 'href' => $cylinder->url(), 'tone' => $this->testOverdue($cylinder->value('test_due')) ? 'danger' : 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $sales = $this->dated('sales', $from, $to)->where('status', '!=', 'cancelled')->get();
        $bySize = $sales->groupBy(fn (Record $sale) => (string) $sale->value('size'))->sortKeysUsing(fn ($a, $b) => (int) $a <=> (int) $b)
            ->map(fn (Collection $group, string $size) => [
                $size, $group->where('data.type', 'refill')->sum(fn (Record $sale) => (int) $sale->value('quantity')), $group->where('data.type', 'exchange')->sum(fn (Record $sale) => (int) $sale->value('quantity')),
                $group->where('data.type', 'new_cylinder')->sum(fn (Record $sale) => (int) $sale->value('quantity')), number_format($group->sum(fn (Record $sale) => (int) $sale->value('_kg'))).' kg', $this->money($group->sum('amount')),
            ])->values()->all();

        $cylinders = $this->records('cylinders')->get();

        return [
            ['title' => 'Sales by size', 'columns' => ['Size', 'Refills', 'Exchanges', 'New cylinders', 'Gas sold', 'Takings'], 'rows' => $bySize],
            ['title' => 'Cylinder stock', 'columns' => ['Status', 'Cylinders'], 'rows' => collect(['full', 'empty', 'with_customer', 'condemned'])->map(fn (string $status) => [ucfirst(str_replace('_', ' ', $status)), $cylinders->where('status', $status)->count()])->all()],
        ];
    }
}
