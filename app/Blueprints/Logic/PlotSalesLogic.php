<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Land & plot sales: a plot is sold to one buyer at a time, and a sale defaults to the plot's price. The
 * deposit and what is paid can't be more than the price. A sale is reserved until money comes in, paying
 * while instalments run and fully paid once covered. The plot follows the sale: reserved, sold,
 * transferred, or available again if the sale is cancelled. Transfer needs full payment and a title deed.
 */
class PlotSalesLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'plots' && (float) ($data['price'] ?? 0) <= 0) {
            $errors['data.price'] = 'Set the plot price.';
        }
        if ($entity->key !== 'sales' || blank($data['plot'] ?? null)) {
            return $errors;
        }
        $plot = $this->records('plots')->find($data['plot']);
        $other = $this->linked('sales', 'plot', (int) $data['plot'])->where('status', '!=', 'cancelled')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first();
        if ($other && $payload['status'] !== 'cancelled') {
            $errors['data.plot'] = ($plot?->title ?? 'This plot').' is already sold to '.$other->title.'.';
        }
        $price = (float) ($payload['amount'] ?? 0) ?: $this->number($plot ?? new Record, 'price');
        if ((float) ($data['deposit'] ?? 0) > $price || (float) ($data['paid_to_date'] ?? 0) > $price) {
            $errors['data.paid_to_date'] = 'Payments cannot be more than the price of '.$this->money($price).'.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'sales') {
            return;
        }
        $record->occurs_on ??= today();
        if ((float) $record->amount <= 0 && ($plot = $this->parent($record, 'plot'))) {
            $record->amount = $this->number($plot, 'price');
        }
        if (! $record->exists && $this->number($record, 'paid_to_date') < $this->number($record, 'deposit')) {
            $this->put($record, ['paid_to_date' => $this->number($record, 'deposit')]);
        }
        $left = round(max(0, (float) $record->amount - $this->number($record, 'paid_to_date')), 2);
        $instalment = $this->number($record, 'instalment');
        $this->put($record, ['_balance' => $left, '_months_left' => $left > 0 && $instalment > 0 ? (int) ceil($left / $instalment) : null]);
        if (in_array($record->status, ['reserved', 'paying', 'fully_paid'], true)) {
            $record->status = match (true) {
                $left <= 0 => 'fully_paid',
                $this->number($record, 'paid_to_date') > 0 => 'paying',
                default => 'reserved',
            };
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'sales') {
            return;
        }
        if ($plot = $this->parent($record, 'plot')) {
            $status = ['reserved' => 'reserved', 'paying' => 'sold', 'fully_paid' => 'sold', 'transferred' => 'transferred', 'cancelled' => 'available'][$record->status];
            if ($plot->status !== $status) {
                $plot->update(['status' => $status]);
            }
        }
        if (($previous = $this->previousParent($record, 'plot')) && $previous->status !== 'available') {
            $previous->update(['status' => 'available']);
        }
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'sales' && in_array($record->status, ['reserved', 'paying'], true) => ['receive' => ['label' => 'Payment received', 'icon' => 'banknote', 'fields' => [['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'value' => min($this->number($record, 'instalment') ?: $this->number($record, '_balance'), $this->number($record, '_balance'))]]], 'cancel' => ['label' => 'Cancel sale', 'icon' => 'x']],
            $record->entity === 'sales' && $record->status === 'fully_paid' => ['transfer' => ['label' => 'Transfer', 'icon' => 'stamp', 'fields' => [['name' => 'title_deed', 'label' => 'Title deed number', 'type' => 'text', 'value' => $this->parent($record, 'plot')?->value('title_deed')]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'receive':
                $amount = (float) $request->validate(['amount' => ['required', 'numeric', 'gt:0']])['amount'];
                if ($amount > $this->number($record, '_balance')) {
                    throw ValidationException::withMessages(['amount' => 'Only '.$this->money($this->number($record, '_balance')).' is still owed.']);
                }
                $record->update(['data' => [...$record->data, 'paid_to_date' => round($this->number($record, 'paid_to_date') + $amount, 2)]]);

                return 'Received '.$this->money($amount).' from '.$record->title.'; '.($record->status === 'fully_paid' ? 'the plot is fully paid.' : $this->money($this->number($record, '_balance')).' to go.');
            case 'transfer':
                $deed = trim((string) ($request->validate(['title_deed' => ['nullable', 'string', 'max:100']])['title_deed'] ?? ''));
                if ($deed === '') {
                    throw ValidationException::withMessages(['title_deed' => 'Give the title deed number.']);
                }
                $plot = $this->parent($record, 'plot');
                $plot?->update(['data' => [...$plot->data, 'title_deed' => $deed]]);
                $record->update(['status' => 'transferred']);

                return ($plot?->title ?? 'The plot').' transferred to '.$record->title.' under title deed '.$deed.'.';
            default:
                $record->update(['status' => 'cancelled']);

                return $record->title.'\'s sale cancelled; '.($this->parent($record, 'plot')?->title ?? 'the plot').' is available again.';
        }
    }

    public function homeCards(): array
    {
        $plots = $this->records('plots')->get();
        $sales = $this->records('sales')->whereIn('status', ['reserved', 'paying'])->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Plots', 'icon' => 'map', 'stats' => [
            ['label' => 'Available', 'value' => $plots->where('status', 'available')->count().' of '.$plots->count()],
            ['label' => 'Reserved', 'value' => $plots->where('status', 'reserved')->count()],
            ['label' => 'Still to collect', 'value' => $this->money($sales->sum(fn (Record $sale) => $this->number($sale, '_balance')))],
            ['label' => 'Value unsold', 'value' => $this->money($plots->where('status', 'available')->sum(fn (Record $plot) => $this->number($plot, 'price')))],
        ]]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $plots = $this->records('plots')->get();

        return [['title' => 'Plots by development', 'columns' => ['Development', 'Plots', 'Available', 'Reserved', 'Sold or transferred', 'Value sold'], 'rows' => $plots
            ->groupBy(fn (Record $plot) => (string) $plot->value('development'))->sortKeys()
            ->map(fn ($group, string $development) => [$development, $group->count(), $group->where('status', 'available')->count(), $group->where('status', 'reserved')->count(),
                $group->whereIn('status', ['sold', 'transferred'])->count(), $this->money($group->whereIn('status', ['sold', 'transferred'])->sum(fn (Record $plot) => $this->number($plot, 'price')))])
            ->values()->all()]];
    }
}
