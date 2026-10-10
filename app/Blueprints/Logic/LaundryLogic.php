<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Laundry & dry-cleaning: an order is ready two days after it comes in (dry cleaning and duvets three),
 * or the next day when express; express costs half as much again. An order must have at least one piece,
 * and it can't be handed over until it is paid for.
 */
class LaundryLogic extends AppLogic
{
    /**
     * Days to turn an order round, by service.
     *
     * @var array<string, int>
     */
    public const TURNAROUND_DAYS = ['wash_and_fold' => 2, 'ironing' => 2, 'mixed' => 2, 'dry_clean' => 3, 'duvet' => 3];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ((int) ($data['pieces'] ?? 0) < 1) {
            $errors['data.pieces'] = 'An order needs at least one piece.';
        }
        if ($payload['status'] === 'collected' && empty($data['paid']) && (! $existing || $existing->status !== 'collected')) {
            $errors['status'] = 'Take payment before the order is collected.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if (! $record->exists && ! $record->due_on) {
            $record->due_on = $record->occurs_on->copy()->addDays($record->value('express') ? 1 : (self::TURNAROUND_DAYS[$record->value('service')] ?? 2));
        }
        if (! $record->exists && $record->value('express') && $record->amount > 0) {
            $record->amount = round($record->amount * 1.5, 2);
        }
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'received' => ['wash' => ['label' => 'Start washing', 'icon' => 'waves']],
            'washing' => ['ready' => ['label' => 'Ready', 'icon' => 'check']],
            'ready' => [
                ...($record->value('paid') ? [] : ['pay' => ['label' => 'Paid', 'icon' => 'banknote']]),
                'collect' => ['label' => 'Collected', 'icon' => 'hand'],
            ],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'wash':
                $record->update(['status' => 'washing']);

                return $record->title.' is being washed.';
            case 'ready':
                $record->update(['status' => 'ready']);

                return $record->title.' is ready'.($record->due_on && $record->due_on->lt(today()) ? ', '.($late = (int) $record->due_on->diffInDays(today())).' '.str('day')->plural($late).' late' : '').'.';
            case 'pay':
                $this->put($record, ['paid' => true]);
                $record->save();

                return $this->money($record->amount).' paid for '.$record->title.'.';
            default:
                if (! $record->value('paid')) {
                    throw ValidationException::withMessages(['status' => 'Take payment of '.$this->money($record->amount).' before the order is collected.']);
                }
                $record->update(['status' => 'collected']);

                return $record->title.' collected.';
        }
    }

    public function homeCards(): array
    {
        $orders = $this->records('orders')->whereIn('status', ['received', 'washing', 'ready'])->get();
        $late = $orders->where('status', '!=', 'ready')->filter(fn (Record $order) => $order->due_on && $order->due_on->lt(today()));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'On the rail', 'icon' => 'shirt', 'stats' => [
                ['label' => 'In process', 'value' => $orders->where('status', '!=', 'ready')->count()],
                ['label' => 'Due today', 'value' => $orders->where('status', '!=', 'ready')->filter(fn (Record $order) => $order->due_on?->isToday())->count()],
                ['label' => 'Ready, not collected', 'value' => $orders->where('status', 'ready')->count()],
                ['label' => 'Unpaid', 'value' => $this->money($orders->filter(fn (Record $order) => ! $order->value('paid'))->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Late orders', 'icon' => 'alarm-clock', 'empty' => 'Nothing late.',
                'rows' => $late->sortBy('due_on')->map(fn (Record $order) => ['label' => $order->title, 'sub' => (int) $order->value('pieces').' pieces', 'value' => 'Due '.$order->due_on->format('d M'), 'href' => $order->url(), 'tone' => 'danger'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $orders = $this->dated('orders', $from, $to)->where('status', '!=', 'cancelled')->get();

        return [['title' => 'Orders by service', 'columns' => ['Service', 'Orders', 'Pieces', 'Express', 'Takings'], 'rows' => $orders
            ->groupBy(fn (Record $order) => ucfirst(str_replace('_', ' ', (string) ($order->value('service') ?: 'mixed'))))->sortKeys()
            ->map(fn ($group, string $service) => [$service, $group->count(), (int) $group->sum(fn (Record $order) => $this->number($order, 'pieces')), $group->filter(fn (Record $order) => $order->value('express'))->count(), $this->money($group->sum('amount'))])
            ->values()->all()]];
    }
}
