<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Car wash: a wash charges its package's price unless another amount is given, and earns the washer the
 * package's commission, which can't be more than the price. Only active packages can be sold. Registrations
 * are stored in capitals. A wash is paid with a payment method, and the report shows each washer's commission.
 */
class CarWashLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'packages') {
            if ((float) ($data['commission'] ?? 0) > (float) ($data['price'] ?? 0)) {
                $errors['data.commission'] = 'The commission can\'t be more than the price.';
            }

            return $errors;
        }
        if (! $existing && filled($data['package'] ?? null) && ($package = $this->records('packages')->find($data['package'])) && $package->status !== 'active') {
            $errors['data.package'] = $package->title.' is retired.';
        }
        if ($payload['status'] === 'paid' && blank($data['payment'] ?? null)) {
            $errors['data.payment'] = 'Give the payment method.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'washes') {
            return;
        }
        $record->title = strtoupper(preg_replace('/\s+/', ' ', trim((string) $record->title)));
        $record->occurs_on ??= today();
        $package = $this->parent($record, 'package');
        if (! $package) {
            return;
        }
        $changed = $record->exists && $this->previousParent($record, 'package') !== null;
        if (! $record->amount || $changed) {
            $record->amount = $this->number($package, 'price');
        }
        if (! $record->exists || $changed || $record->value('_commission') === null) {
            $this->put($record, ['_commission' => $this->number($package, 'commission')]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'washes') {
            return [];
        }

        return match ($record->status) {
            'waiting' => ['start' => ['label' => 'Start washing', 'icon' => 'droplets']],
            'washing' => ['done' => ['label' => 'Done', 'icon' => 'check']],
            'done' => ['pay' => ['label' => 'Paid', 'icon' => 'banknote', 'fields' => [['name' => 'payment', 'label' => 'Payment', 'type' => 'select', 'value' => 'cash', 'options' => ['cash' => 'Cash', 'card' => 'Card', 'mobile_money' => 'Mobile money', 'account' => 'Account']]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'start':
                $washer = $record->value('washer') ?: $request->user()->id;
                $record->update(['status' => 'washing', 'data' => [...$record->data, 'washer' => $washer]]);

                return $record->title.' is being washed by '.User::query()->whereKey($washer)->value('name').'.';
            case 'done':
                $record->update(['status' => 'done']);

                return $record->title.' is done.';
            default:
                $payment = $request->validate(['payment' => ['required', 'in:cash,card,mobile_money,account']], ['payment.required' => 'Give the payment method.'])['payment'];
                if (! $record->amount) {
                    throw ValidationException::withMessages(['payment' => 'This wash has no amount to pay.']);
                }
                $record->update(['status' => 'paid', 'data' => [...$record->data, 'payment' => $payment]]);

                return $this->money($record->amount).' paid for '.$record->title.' by '.str_replace('_', ' ', $payment).'.';
        }
    }

    public function homeCards(): array
    {
        $today = $this->records('washes')->whereDate('occurs_on', today()->toDateString())->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today', 'icon' => 'droplets', 'stats' => [
            ['label' => 'Waiting', 'value' => $today->where('status', 'waiting')->count()],
            ['label' => 'Washing', 'value' => $today->where('status', 'washing')->count()],
            ['label' => 'Done, not paid', 'value' => $today->where('status', 'done')->count()],
            ['label' => 'Taken', 'value' => $this->money($today->where('status', 'paid')->sum('amount'))],
        ]]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $washes = $this->dated('washes', $from, $to)->whereIn('status', ['done', 'paid'])->get();
        $names = User::query()->whereIn('id', $washes->map(fn (Record $wash) => $wash->value('washer'))->filter()->unique())->pluck('name', 'id');
        $packages = $this->records('packages')->pluck('title', 'id');

        return [
            ['title' => 'Washer commissions', 'columns' => ['Washer', 'Washes', 'Takings', 'Commission'], 'rows' => $washes
                ->groupBy(fn (Record $wash) => $names[$wash->value('washer')] ?? 'No washer')->sortKeys()
                ->map(fn ($group, string $washer) => [$washer, $group->count(), $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $wash) => $this->number($wash, '_commission')))])
                ->values()->all()],
            ['title' => 'Washes by package', 'columns' => ['Package', 'Washes', 'Takings'], 'rows' => $washes
                ->groupBy(fn (Record $wash) => $packages[$wash->value('package')] ?? 'No package')->sortKeys()
                ->map(fn ($group, string $package) => [$package, $group->count(), $this->money($group->sum('amount'))])
                ->values()->all()],
        ];
    }
}
