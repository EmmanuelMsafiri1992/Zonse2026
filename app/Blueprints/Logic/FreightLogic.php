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
 * Freight forwarding: shipments move forward from booked to delivered, need different origin and
 * destination ports, an ETA after departure, and a bill of lading once in transit. A shipment is
 * released only when its customs entry is released. Containers belong to sea shipments, carry valid
 * ISO 6346 numbers with a check digit, need a seal once loaded, and run up demurrage each day they
 * are kept past their return-by date. Customs entries go lodged → assessed → paid → released; a
 * query sends the entry back to lodged, and releasing it releases the shipment.
 */
class FreightLogic extends AppLogic
{
    /**
     * Shipment steps in order.
     */
    protected const STEPS = ['booked', 'in_transit', 'at_port', 'clearing', 'released', 'delivered'];

    /**
     * Customs entry steps in order.
     */
    protected const ENTRY_STEPS = ['lodged', 'assessed', 'paid', 'released'];

    /**
     * Demurrage charged per container per day past its return-by date.
     */
    public const DEMURRAGE_PER_DAY = 75;

    /**
     * Guards against re-entering shipment release from its customs entry.
     */
    protected static bool $releasing = false;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($entity->key === 'shipments') {
            if (filled($data['origin'] ?? null) && $this->normalise((string) $data['origin']) === $this->normalise((string) ($data['destination'] ?? ''))) {
                $errors['data.destination'] = 'The destination must differ from the origin.';
            }
            if (filled($payload['due_on'] ?? null) && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The ETA cannot be before departure.';
            }
            if ($status !== 'booked' && blank($data['bill_of_lading'] ?? null)) {
                $errors['data.bill_of_lading'] = 'Enter the bill of lading or air waybill once the shipment moves.';
            }
            if ($existing && in_array($status, self::STEPS, true) && array_search($status, self::STEPS, true) < array_search($existing->status, self::STEPS, true)) {
                $errors['status'] = 'A shipment cannot go back to '.str_replace('_', ' ', $status).'.';
            }
            if (in_array($status, ['released', 'delivered'], true) && ! in_array($existing?->status, ['released', 'delivered'], true) && (! $existing || $this->linked('clearances', 'shipment', $existing)->where('status', 'released')->doesntExist())) {
                $errors['status'] = 'A shipment is released once its customs entry is released.';
            }
            if ($existing && ($data['mode'] ?? null) !== 'sea' && $this->linked('containers', 'shipment', $existing)->exists()) {
                $errors['data.mode'] = 'This shipment has containers, so it goes by sea.';
            }

            return $errors;
        }

        if ($entity->key === 'containers') {
            $number = strtoupper(preg_replace('/\s+/', '', (string) ($payload['title'] ?? '')));
            if (! $this->validContainer($number)) {
                $errors['title'] = 'Enter a valid container number: 4 letters ending in U, J or Z, 6 digits and a check digit (like MSCU1234566).';
            } elseif ($this->records('containers')->whereIn('status', ['empty', 'loaded', 'in_transit'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $container) => strtoupper(preg_replace('/\s+/', '', $container->title)) === $number)) {
                $errors['title'] = 'Container '.$number.' is already in use on another shipment.';
            }
            $shipment = filled($data['shipment'] ?? null) ? $this->records('shipments')->find($data['shipment']) : null;
            if ($shipment && $shipment->value('mode') !== 'sea') {
                $errors['data.shipment'] = $shipment->title.' goes by '.$shipment->value('mode').', not sea.';
            }
            if (in_array($status, ['loaded', 'in_transit'], true) && blank($data['seal_number'] ?? null)) {
                $errors['data.seal_number'] = 'Enter the seal number once the container is loaded.';
            }

            return $errors;
        }

        $entry = trim((string) ($data['entry_number'] ?? ''));
        if ($entry !== '' && $this->records('clearances')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $clearance) => strcasecmp(trim((string) $clearance->value('entry_number')), $entry) === 0)) {
            $errors['data.entry_number'] = 'Entry number '.$entry.' is already recorded.';
        }
        if ($status !== 'lodged' && $status !== 'queried' && $entry === '') {
            $errors['data.entry_number'] = 'Enter the entry number customs gave.';
        }
        if ((float) ($data['duty'] ?? 0) < 0) {
            $errors['data.duty'] = 'Duty cannot be negative.';
        }
        $from = $existing?->status;
        if ($from === 'released' && $status !== 'released') {
            $errors['status'] = 'This entry is released.';
        } elseif ($from && in_array($from, self::ENTRY_STEPS, true) && in_array($status, self::ENTRY_STEPS, true) && array_search($status, self::ENTRY_STEPS, true) !== array_search($from, self::ENTRY_STEPS, true) && array_search($status, self::ENTRY_STEPS, true) !== array_search($from, self::ENTRY_STEPS, true) + 1) {
            $errors['status'] = 'A customs entry goes lodged, assessed, paid, then released.';
        } elseif ($from === 'queried' && ! in_array($status, ['queried', 'lodged'], true)) {
            $errors['status'] = 'Answer the query and lodge the entry again.';
        } elseif (! $from && ! in_array($status, ['lodged'], true)) {
            $errors['status'] = 'A new customs entry starts as lodged.';
        }

        return $errors;
    }

    /**
     * Whether the container number passes the ISO 6346 check digit.
     */
    public function validContainer(string $number): bool
    {
        if (! preg_match('/^[A-Z]{3}[UJZ]\d{7}$/', $number)) {
            return false;
        }
        $values = [];
        $value = 10;
        foreach (range('A', 'Z') as $letter) {
            if ($value % 11 === 0) {
                $value++;
            }
            $values[$letter] = $value++;
        }
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += ($values[$number[$i]] ?? (int) $number[$i]) * (2 ** $i);
        }

        return $sum % 11 % 10 === (int) $number[10];
    }

    /**
     * A place name that matches however it was typed.
     */
    protected function normalise(string $place): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($place)));
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'containers') {
            $record->title = strtoupper(preg_replace('/\s+/', '', $record->title));
            $this->put($record, ['_demurrage_days' => $this->demurrageDays($record)]);
        }
        if ($record->entity === 'clearances') {
            $record->amount = $record->value('duty') !== null && $record->value('duty') !== '' ? $this->number($record, 'duty') : $record->amount;
            if ($record->isDirty('status')) {
                $this->put($record, ['_'.$record->status.'_on' => today()->toDateString()]);
            }
        }
    }

    /**
     * Days the container has been kept past its return-by date.
     */
    protected function demurrageDays(Record $container): int
    {
        if (! $container->due_on || $container->status === 'returned') {
            return (int) $container->value('_demurrage_days');
        }

        return max(0, (int) $container->due_on->copy()->startOfDay()->diffInDays(today(), false));
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'clearances' || $record->status !== 'released' || ! $record->wasChanged('status') && ! $record->wasRecentlyCreated || static::$releasing) {
            return;
        }
        $shipment = $this->parent($record, 'shipment');
        if ($shipment && ! in_array($shipment->status, ['released', 'delivered'], true)) {
            static::$releasing = true;
            try {
                $shipment->update(['status' => 'released', 'data' => [...$shipment->data, '_released_on' => today()->toDateString()]]);
            } finally {
                static::$releasing = false;
            }
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'clearances') {
            return match ($record->status) {
                'lodged' => ['assess' => ['label' => 'Assessed', 'icon' => 'calculator', 'fields' => [['name' => 'duty', 'label' => 'Duties & taxes', 'type' => 'number', 'value' => $record->value('duty')]]], 'query' => ['label' => 'Queried', 'icon' => 'circle-help', 'fields' => [['name' => 'reason', 'label' => 'Query', 'type' => 'text']]]],
                'assessed' => ['pay' => ['label' => 'Duty paid', 'icon' => 'banknote'], 'query' => ['label' => 'Queried', 'icon' => 'circle-help', 'fields' => [['name' => 'reason', 'label' => 'Query', 'type' => 'text']]]],
                'paid' => ['release' => ['label' => 'Released', 'icon' => 'check']],
                'queried' => ['relodge' => ['label' => 'Lodge again', 'icon' => 'send']],
                default => [],
            };
        }
        if ($record->entity === 'containers') {
            return $record->status !== 'returned' ? ['return' => ['label' => 'Returned', 'icon' => 'undo-2']] : [];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'return') {
            $days = $this->demurrageDays($record);
            $record->update(['status' => 'returned', 'data' => [...$record->data, '_demurrage_days' => $days, '_demurrage' => $days * self::DEMURRAGE_PER_DAY, '_returned_on' => today()->toDateString()]]);

            return $record->title.' returned'.($days > 0 ? ' '.$days.' '.str('day')->plural($days).' late: '.$this->money($days * self::DEMURRAGE_PER_DAY).' demurrage.' : ' on time.');
        }
        if (in_array($action, ['assess', 'pay', 'release'], true) && blank($record->value('entry_number'))) {
            throw ValidationException::withMessages(['entry_number' => 'Enter the entry number customs gave.']);
        }

        switch ($action) {
            case 'assess':
                $duty = (float) $request->validate(['duty' => ['required', 'numeric', 'min:0']])['duty'];
                $record->update(['status' => 'assessed', 'data' => [...$record->data, 'duty' => $duty]]);

                return $record->title.' assessed at '.$this->money($duty).'.';
            case 'pay':
                $record->update(['status' => 'paid']);

                return $record->title.' paid.';
            case 'release':
                $record->update(['status' => 'released']);
                $shipment = $this->parent($record, 'shipment');

                return $record->title.' released'.($shipment ? '; '.$shipment->title.' can be collected.' : '.');
            case 'query':
                $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
                $record->update(['status' => 'queried', 'data' => [...$record->data, '_query' => $reason]]);

                return $record->title.' queried: '.$reason.'.';
            default:
                $record->update(['status' => 'lodged', 'data' => [...$record->data, '_relodged' => (int) $record->value('_relodged') + 1]]);

                return $record->title.' lodged again.';
        }
    }

    public function daily(Workspace $workspace): int
    {
        $late = $this->records('containers')->where('status', '!=', 'returned')->whereNotNull('due_on')->where('due_on', '<', today()->startOfDay())->get();
        $late->each(fn (Record $container) => $container->update(['data' => [...$container->data, '_demurrage_days' => $this->demurrageDays($container)]]));

        return $late->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'shipments') {
            return [];
        }
        $containers = $this->linked('containers', 'shipment', $record)->orderBy('title')->get();
        $clearances = $this->linked('clearances', 'shipment', $record)->orderBy('id')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Containers', 'icon' => 'container', 'empty' => $record->value('mode') === 'sea' ? 'No containers yet.' : 'Containers are for sea shipments.',
                'rows' => $containers->map(fn (Record $container) => ['label' => $container->title, 'sub' => str_replace('_', ' ', (string) $container->value('size')).' · '.str_replace('_', ' ', $container->status), 'value' => $this->demurrageDays($container) > 0 ? $this->demurrageDays($container).' days late' : ($container->due_on?->format('d M') ?? '—'), 'href' => $container->url(), 'tone' => $this->demurrageDays($container) > 0 ? 'danger' : null])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Customs', 'icon' => 'file-check', 'empty' => 'No customs entry lodged.',
                'rows' => $clearances->map(fn (Record $clearance) => ['label' => $clearance->value('entry_number') ?: $clearance->title, 'sub' => ucfirst($clearance->status), 'value' => $this->money($clearance->amount), 'href' => $clearance->url(), 'tone' => match ($clearance->status) {
                    'released' => 'success', 'queried' => 'danger', default => null,
                }])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $arriving = $this->records('shipments')->whereIn('status', ['booked', 'in_transit'])->whereNotNull('due_on')->where('due_on', '<=', today()->addDays(7)->endOfDay())->orderBy('due_on')->get();
        $late = $this->records('containers')->where('status', '!=', 'returned')->whereNotNull('due_on')->where('due_on', '<', today()->startOfDay())->orderBy('due_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Arriving in 7 days', 'icon' => 'ship', 'empty' => 'Nothing due to arrive.',
                'rows' => $arriving->map(fn (Record $shipment) => ['label' => $shipment->title, 'sub' => $shipment->value('origin').' → '.$shipment->value('destination').' · '.$shipment->value('mode'), 'value' => $shipment->due_on->format('d M'), 'href' => $shipment->url(), 'tone' => $shipment->due_on->lt(today()) ? 'danger' : null])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Containers past return-by', 'icon' => 'container', 'empty' => 'No containers running up demurrage.',
                'rows' => $late->map(fn (Record $container) => ['label' => $container->title, 'sub' => $this->demurrageDays($container).' days late', 'value' => $this->money($this->demurrageDays($container) * self::DEMURRAGE_PER_DAY), 'href' => $container->url(), 'tone' => 'danger'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $shipments = $this->dated('shipments', $from, $to)->get();
        $clearances = $this->dated('clearances', $from, $to)->get();
        $containers = $this->records('containers')->get();

        $byMode = $shipments->groupBy(fn (Record $shipment) => $shipment->value('mode') ?: 'unknown')->sortKeys()
            ->map(fn (Collection $group, string $mode) => [ucfirst($mode), $group->count(), round($group->sum(fn (Record $shipment) => $this->number($shipment, 'weight'))).' kg', $this->money($group->sum('amount'))])->values()->all();

        $duties = $clearances->groupBy('status')->sortKeys()
            ->map(fn (Collection $group, string $status) => [ucfirst($status), $group->count(), $this->money($group->sum('amount'))])->values()->all();

        $demurrage = $containers->filter(fn (Record $container) => $this->demurrageDays($container) > 0)->sortByDesc(fn (Record $container) => $this->demurrageDays($container))
            ->map(fn (Record $container) => [$container->title, ucfirst(str_replace('_', ' ', $container->status)), $this->demurrageDays($container), $this->money($this->demurrageDays($container) * self::DEMURRAGE_PER_DAY)])->values()->all();

        return [
            ['title' => 'Shipments by mode', 'columns' => ['Mode', 'Shipments', 'Weight', 'Charges'], 'rows' => $byMode],
            ['title' => 'Duties and taxes', 'columns' => ['Status', 'Entries', 'Duties & taxes'], 'rows' => $duties],
            ['title' => 'Demurrage', 'columns' => ['Container', 'Status', 'Days late', 'Demurrage'], 'rows' => $demurrage],
        ];
    }
}
