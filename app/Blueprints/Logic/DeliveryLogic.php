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
 * Delivery & courier: every parcel gets a unique tracking number and moves forward from booked to
 * delivered. Delivering needs the name of who received it, and a cash-on-delivery parcel needs the
 * full amount collected. A failed attempt needs a reason, and after three failed attempts the parcel
 * goes back to the sender. A driver has one open run a day; starting it sends their parcels out for
 * delivery, and it can't be completed while any are still out.
 */
class DeliveryLogic extends AppLogic
{
    /**
     * Parcel steps in order.
     */
    protected const STEPS = ['booked', 'collected', 'in_transit', 'out_for_delivery', 'delivered'];

    /**
     * Failed attempts before a parcel goes back to the sender.
     */
    protected const MAX_ATTEMPTS = 3;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($entity->key === 'runs') {
            $driver = (string) ($data['driver'] ?? '');
            $day = Carbon::parse($payload['occurs_on'] ?? today())->toDateString();
            $clash = $driver === '' || $status === 'completed' ? null : $this->records('runs')->whereIn('status', ['planned', 'on_the_road'])->whereDate('occurs_on', $day)
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $run) => (string) $run->value('driver') === $driver);
            if ($clash) {
                $errors['data.driver'] = 'This driver already has '.$clash->title.' that day.';
            }
            if ($existing && $status === 'completed' && $existing->status !== 'completed' && ($out = $this->outOnRun($existing)->count())) {
                $errors['status'] = $out.' '.str('parcel')->plural($out).' still out for delivery.';
            }
            if ($existing?->status === 'completed' && $status !== 'completed') {
                $errors['status'] = 'This run is completed.';
            }

            return $errors;
        }

        $tracking = trim((string) ($data['tracking_number'] ?? ''));
        if ($tracking !== '' && $this->records('parcels')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $parcel) => strcasecmp((string) $parcel->value('tracking_number'), $tracking) === 0)) {
            $errors['data.tracking_number'] = 'Tracking number '.$tracking.' is already in use.';
        }
        if ((float) ($data['cod_amount'] ?? 0) < 0) {
            $errors['data.cod_amount'] = 'Cash on delivery cannot be negative.';
        }
        $from = $existing?->status;
        if (in_array($from, ['delivered', 'returned'], true) && $status !== $from) {
            $errors['status'] = 'This parcel was '.$from.'.';
        } elseif ($from && in_array($status, self::STEPS, true) && in_array($from, self::STEPS, true) && array_search($status, self::STEPS, true) < array_search($from, self::STEPS, true)) {
            $errors['status'] = 'A parcel cannot go back to '.str_replace('_', ' ', $status).'.';
        } elseif ($status === 'failed' && $from !== 'failed') {
            $errors['status'] = 'Use "Failed attempt" so the reason is recorded.';
        } elseif ($status === 'returned' && $from !== 'returned') {
            $errors['status'] = 'Use "Return to sender" so the reason is recorded.';
        }
        if ($status === 'delivered' && $from !== 'delivered') {
            if (blank($data['received_by'] ?? null)) {
                $errors['data.received_by'] = 'Record who received the parcel.';
            }
            if ((float) ($data['cod_amount'] ?? 0) > 0) {
                $errors['status'] = 'Use "Deliver" so the cash collected is recorded.';
            }
        }

        return $errors;
    }

    /**
     * The run's parcels still out for delivery.
     */
    protected function outOnRun(Record $run)
    {
        return $this->records('parcels')->where('status', 'out_for_delivery')->whereIn('id', $run->value('_parcels') ?? []);
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity !== 'parcels' || filled($record->value('tracking_number'))) {
            return;
        }
        $taken = $this->records('parcels')->get()->map(fn (Record $parcel) => strtoupper((string) $parcel->value('tracking_number')))->filter()->flip();
        do {
            $tracking = 'ZN'.$record->occurs_on->format('ymd').random_int(10000, 99999);
        } while ($taken->has($tracking));
        $this->put($record, ['tracking_number' => $tracking]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'runs') {
            return match ($record->status) {
                'planned' => ['start' => ['label' => 'Start run', 'icon' => 'play']],
                'on_the_road' => ['complete' => ['label' => 'Complete run', 'icon' => 'check']],
                default => [],
            };
        }

        return match ($record->status) {
            'booked' => ['collect' => ['label' => 'Collected', 'icon' => 'package']],
            'collected' => ['transit' => ['label' => 'In transit', 'icon' => 'truck']],
            'in_transit', 'failed' => ['out' => ['label' => 'Out for delivery', 'icon' => 'route'], 'return' => ['label' => 'Return to sender', 'icon' => 'undo-2', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]],
            'out_for_delivery' => [
                'deliver' => ['label' => 'Deliver', 'icon' => 'check', 'fields' => [
                    ['name' => 'received_by', 'label' => 'Received by', 'type' => 'text'],
                    ...($this->number($record, 'cod_amount') > 0 ? [['name' => 'collected', 'label' => 'Cash collected', 'type' => 'number', 'value' => $record->value('cod_amount')]] : []),
                ]],
                'fail' => ['label' => 'Failed attempt', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]],
            ],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $stamp = fn (string $status, array $extra = []) => $record->update(['status' => $status, 'data' => [...$record->data, ...$extra, '_'.$status.'_at' => now()->toDateTimeString()]]);

        switch ($action) {
            case 'start':
                $driver = (string) $record->value('driver');
                $parcels = $this->records('parcels')->where('assignee_id', $driver)->whereIn('status', ['collected', 'in_transit', 'failed'])->get();
                $parcels->each(fn (Record $parcel) => $parcel->update(['status' => 'out_for_delivery', 'data' => [...$parcel->data, '_run' => $record->id]]));
                $stamp('on_the_road', ['stops' => $parcels->count(), '_parcels' => $parcels->pluck('id')->all()]);

                return $record->title.' started with '.$parcels->count().' '.str('parcel')->plural($parcels->count()).'.';
            case 'complete':
                if ($out = $this->outOnRun($record)->count()) {
                    throw ValidationException::withMessages(['status' => $out.' '.str('parcel')->plural($out).' still out for delivery.']);
                }
                $parcels = $this->records('parcels')->whereIn('id', $record->value('_parcels') ?? [])->get();
                $stamp('completed', ['_delivered' => $parcels->where('status', 'delivered')->count()]);

                return $record->title.' completed: '.$parcels->where('status', 'delivered')->count().' of '.$parcels->count().' delivered.';
            case 'collect':
                $stamp('collected');

                return $record->title.' collected.';
            case 'transit':
                $stamp('in_transit');

                return $record->title.' in transit.';
            case 'out':
                $stamp('out_for_delivery');

                return $record->title.' out for delivery.';
            case 'deliver':
                $cod = $this->number($record, 'cod_amount');
                $values = $request->validate(['received_by' => ['required', 'string', 'max:255'], 'collected' => [$cod > 0 ? 'required' : 'nullable', 'numeric', 'min:0']]);
                if ($cod > 0 && round((float) $values['collected'], 2) !== round($cod, 2)) {
                    throw ValidationException::withMessages(['collected' => 'Collect the full '.$this->money($cod).' before handing over the parcel.']);
                }
                $stamp('delivered', ['received_by' => trim($values['received_by']), '_collected' => $cod > 0 ? $cod : null]);

                return $record->title.' delivered to '.trim($values['received_by']).($cod > 0 ? '; '.$this->money($cod).' collected.' : '.');
            case 'fail':
                $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
                $attempts = (int) $record->value('_attempts') + 1;
                $returned = $attempts >= self::MAX_ATTEMPTS;
                $stamp($returned ? 'returned' : 'failed', ['_attempts' => $attempts, '_fail_reason' => $reason]);

                return $returned ? $record->title.' failed '.$attempts.' times and goes back to the sender.' : 'Attempt '.$attempts.' failed: '.$reason.'.';
            default:
                $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
                $stamp('returned', ['_return_reason' => $reason]);

                return $record->title.' goes back to the sender.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'runs') {
            return [];
        }
        $parcels = $this->records('parcels')->whereIn('id', $record->value('_parcels') ?? [])->orderBy('id')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Stops', 'icon' => 'map-pin', 'empty' => 'Start the run to load the driver\'s parcels.',
            'rows' => $parcels->map(fn (Record $parcel) => ['label' => $parcel->value('recipient'), 'sub' => $parcel->value('address'), 'value' => str_replace('_', ' ', $parcel->status), 'href' => $parcel->url(), 'tone' => match ($parcel->status) {
                'delivered' => 'success', 'failed', 'returned' => 'danger', default => null,
            }])->values()->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $out = $this->records('parcels')->where('status', 'out_for_delivery')->orderBy('due_on')->get();
        $drivers = User::query()->whereIn('id', $out->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');
        $cod = $out->sum(fn (Record $parcel) => $this->number($parcel, 'cod_amount'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today', 'icon' => 'truck', 'stats' => [
                ['label' => 'Out for delivery', 'value' => $out->count()],
                ['label' => 'Delivered today', 'value' => $this->records('parcels')->where('status', 'delivered')->get()->filter(fn (Record $parcel) => str_starts_with((string) $parcel->value('_delivered_at'), today()->toDateString()))->count(), 'tone' => 'success'],
                ['label' => 'Cash on the road', 'value' => $this->money($cod), 'tone' => $cod > 0 ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Out for delivery', 'icon' => 'route', 'empty' => 'No parcels out.',
                'rows' => $out->map(fn (Record $parcel) => ['label' => $parcel->value('recipient'), 'sub' => $parcel->value('tracking_number').' · '.($drivers[$parcel->assignee_id] ?? 'No driver'), 'value' => $this->number($parcel, 'cod_amount') > 0 ? 'COD '.$this->money($parcel->value('cod_amount')) : '—', 'href' => $parcel->url(), 'tone' => $parcel->due_on?->lt(today()) ? 'danger' : null])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $parcels = $this->dated('parcels', $from, $to)->get();
        $drivers = User::query()->whereIn('id', $parcels->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');

        $byDriver = $parcels->whereIn('status', ['delivered', 'returned', 'failed'])->groupBy(fn (Record $parcel) => (int) $parcel->assignee_id)
            ->map(function (Collection $group, int $driver) use ($drivers) {
                $delivered = $group->where('status', 'delivered')->count();
                $attempts = $group->sum(fn (Record $parcel) => (int) $parcel->value('_attempts'));

                return [$drivers[$driver] ?? 'Unassigned', $group->count(), $delivered, $attempts, round($delivered / $group->count() * 100, 1).'%'];
            })->sortBy(fn (array $row) => $row[0])->values()->all();

        $cod = $parcels->filter(fn (Record $parcel) => $this->number($parcel, 'cod_amount') > 0);
        $codRows = [
            ['Cash-on-delivery parcels', $cod->count()],
            ['Collected', $this->money($cod->sum(fn (Record $parcel) => $this->number($parcel, '_collected')))],
            ['Still to collect', $this->money($cod->whereNotIn('status', ['delivered', 'returned'])->sum(fn (Record $parcel) => $this->number($parcel, 'cod_amount')))],
            ['Returned uncollected', $this->money($cod->where('status', 'returned')->sum(fn (Record $parcel) => $this->number($parcel, 'cod_amount')))],
        ];

        return [
            ['title' => 'Success rate by driver', 'columns' => ['Driver', 'Finished parcels', 'Delivered', 'Failed attempts', 'Success rate'], 'rows' => $byDriver],
            ['title' => 'Cash on delivery', 'columns' => ['Measure', 'Value'], 'rows' => $codRows],
            ['title' => 'Parcels by status', 'columns' => ['Status', 'Parcels', 'Fees'], 'rows' => $parcels->groupBy('status')->sortKeys()->map(fn (Collection $group, string $status) => [ucfirst(str_replace('_', ' ', $status)), $group->count(), $this->money($group->sum('amount'))])->values()->all()],
        ];
    }
}
