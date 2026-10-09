<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Purchasing: a requisition is approved, then turned into a purchase order in one click (the
 * requisition becomes "ordered"); an RFQ names its winning supplier when awarded; and the
 * home screen shows what waits for approval and which deliveries are late.
 */
class PurchasingLogic extends AppLogic
{
    public const OPEN_ORDERS = ['sent', 'part_received'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key === 'rfqs' && $payload['status'] === 'awarded' && blank($payload['data']['awarded_to'] ?? null)) {
            return ['data.awarded_to' => 'Name the supplier the quotation was awarded to.'];
        }

        return [];
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'requisitions' && $record->status === 'approved') {
            return ['raise_order' => ['label' => 'Raise purchase order', 'icon' => 'shopping-bag', 'confirm' => 'Create a draft purchase order from this requisition?']];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        return 'Purchase order '.$this->raiseOrder($record)->number.' created as a draft. Add the supplier and prices, then send it.';
    }

    /** A draft purchase order carrying the requisition's items and estimate; the requisition is marked ordered. */
    public function raiseOrder(Record $requisition): Record
    {
        $order = Record::create([
            'workspace_id' => $requisition->workspace_id, 'blueprint' => $this->app->key, 'entity' => 'orders',
            'title' => $requisition->title, 'status' => 'draft', 'amount' => $requisition->amount, 'currency' => $requisition->currency,
            'occurs_on' => today(), 'due_on' => $requisition->due_on, 'assignee_id' => $requisition->assignee_id,
            'data' => ['requisition' => $requisition->id, 'items' => $requisition->value('items')],
        ]);
        $requisition->update(['status' => 'ordered']);

        return $order;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'requisitions') {
            return [];
        }

        $orders = $this->linked('orders', 'requisition', $record)->get();
        $rfqs = $this->linked('rfqs', 'requisition', $record)->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Quotations and orders', 'icon' => 'shopping-bag', 'empty' => $record->status === 'approved' ? 'Approved. Raise a purchase order when ready.' : 'Nothing raised yet.',
            'rows' => $rfqs->concat($orders)->map(fn (Record $linked) => [
                'label' => $linked->number.' · '.$linked->title, 'sub' => $linked->definition()->label.' · '.($linked->definition()->statuses[$linked->status] ?? $linked->status),
                'value' => $linked->amount !== null ? $this->money($linked->amount) : null, 'href' => $linked->url(),
            ])->values()->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $waiting = $this->records('requisitions')->where('status', 'submitted')->orderBy('due_on')->get();
        $late = $this->records('orders')->whereIn('status', self::OPEN_ORDERS)->whereDate('due_on', '<', today())->orderBy('due_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Awaiting approval', 'icon' => 'clipboard-list', 'empty' => 'No requisitions waiting.',
                'rows' => $waiting->map(fn (Record $requisition) => [
                    'label' => $requisition->title, 'sub' => trim($requisition->number.' · '.$requisition->value('department'), ' ·'),
                    'value' => $this->money($requisition->amount), 'href' => $requisition->url(), 'tone' => $requisition->value('priority') === 'urgent' ? 'danger' : null,
                ])->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Late deliveries', 'icon' => 'truck', 'empty' => 'No deliveries are late.',
                'rows' => $late->map(fn (Record $order) => [
                    'label' => $order->title, 'sub' => $order->number.' · expected '.$order->due_on->format('d M Y'),
                    'value' => $order->due_on->diffInDays(today()).' days late', 'href' => $order->url(), 'tone' => 'danger',
                ])->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $orders = $this->dated('orders', $from, $to)->whereNotIn('status', ['draft', 'cancelled'])->with('contact')->get();
        $bySupplier = $orders->groupBy(fn (Record $order) => $order->contact?->name ?? 'No supplier named')
            ->sortByDesc(fn ($group) => $group->sum('amount'))
            ->map(fn ($group, $supplier) => [$supplier, $group->count(), $this->money($group->sum('amount'))])->values()->all();
        $byMonth = $this->sumByMonth($orders);

        return [
            ['title' => 'Spend by supplier', 'columns' => ['Supplier', 'Orders', 'Total'], 'rows' => $bySupplier, 'note' => 'Purchase orders sent or received, dated in the period.'],
            ['title' => 'Spend by month', 'columns' => ['Month', 'Total'], 'rows' => collect($this->months($from, $to))->map(fn (string $label, string $month) => [$label, $this->money($byMonth[$month] ?? 0)])->values()->all()],
        ];
    }
}
