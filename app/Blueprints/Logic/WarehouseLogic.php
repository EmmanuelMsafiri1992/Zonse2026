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
 * Warehouse management: bin codes are unique within a warehouse and a bin is in use while it has
 * contents, unless it is blocked. Pick lists move forward from to pick to dispatched, can only be packed
 * with at least one package, and only use active warehouses. A transfer goes between two different
 * active warehouses: draft, then in transit, then received, and can be cancelled until it arrives. A
 * warehouse is closed only once its bins are empty and nothing is being picked or transferred there.
 */
class WarehouseLogic extends AppLogic
{
    /**
     * Pick list steps in order.
     */
    protected const PICK_STEPS = ['to_pick', 'picking', 'packed', 'dispatched'];

    /**
     * Allowed transfer status moves.
     */
    protected const TRANSFER_MOVES = [
        'draft' => ['in_transit', 'cancelled'],
        'in_transit' => ['received', 'cancelled'],
        'received' => [],
        'cancelled' => [],
    ];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($entity->key === 'warehouses') {
            if ($status === 'closed' && $existing && $existing->status !== 'closed' && ($problem = $this->cannotClose($existing))) {
                $errors['status'] = $problem;
            }

            return $errors;
        }

        if ($entity->key === 'bins') {
            $code = mb_strtolower(trim((string) $payload['title']));
            $taken = $this->records('bins')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $bin) => (int) $bin->value('warehouse') === (int) ($data['warehouse'] ?? 0) && mb_strtolower(trim($bin->title)) === $code);
            if ($taken) {
                $errors['title'] = 'Bin '.$taken->title.' already exists in this warehouse.';
            }
            if ($existing?->status === 'blocked' && $status === 'blocked' && trim((string) ($data['contents'] ?? '')) !== trim((string) $existing->value('contents'))) {
                $errors['data.contents'] = 'This bin is blocked; unblock it before putting stock away.';
            }

            return $errors;
        }

        if ($entity->key === 'picks') {
            if ($existing && array_search($status, self::PICK_STEPS, true) < array_search($existing->status, self::PICK_STEPS, true)) {
                $errors['status'] = 'A pick list cannot go back to '.str_replace('_', ' ', $status).'.';
            }
            if (in_array($status, ['packed', 'dispatched'], true) && (int) ($data['packages'] ?? 0) < 1) {
                $errors['data.packages'] = 'Enter how many packages were packed.';
            }
            if (! $existing && ($problem = $this->inactive($data['warehouse'] ?? null))) {
                $errors['data.warehouse'] = $problem;
            }

            return $errors;
        }

        if ($existing && $status !== $existing->status && ! in_array($status, self::TRANSFER_MOVES[$existing->status] ?? [], true)) {
            $errors['status'] = 'A '.str_replace('_', ' ', $existing->status).' transfer cannot become '.str_replace('_', ' ', $status).'.';
        }
        if (filled($data['from_warehouse'] ?? null) && (int) $data['from_warehouse'] === (int) ($data['to_warehouse'] ?? 0)) {
            $errors['data.to_warehouse'] = 'A transfer must go to a different warehouse.';
        }
        if (! $existing || in_array($existing->status, ['draft'], true)) {
            foreach (['from_warehouse', 'to_warehouse'] as $field) {
                if ($problem = $this->inactive($data[$field] ?? null)) {
                    $errors['data.'.$field] = $problem;
                }
            }
        }

        return $errors;
    }

    /**
     * Why a warehouse can't take new work, if it can't.
     */
    protected function inactive(mixed $warehouseId): ?string
    {
        $warehouse = filled($warehouseId) ? $this->records('warehouses')->find($warehouseId) : null;

        return $warehouse && $warehouse->status === 'closed' ? $warehouse->title.' is closed.' : null;
    }

    /**
     * Why the warehouse can't be closed yet, if it can't.
     */
    protected function cannotClose(Record $warehouse): ?string
    {
        $full = $this->linked('bins', 'warehouse', $warehouse)->where('status', 'in_use')->count();
        if ($full) {
            return $full.' '.str('bin')->plural($full).' still '.($full === 1 ? 'has' : 'have').' stock.';
        }
        $picks = $this->linked('picks', 'warehouse', $warehouse)->whereIn('status', ['to_pick', 'picking', 'packed'])->count();
        if ($picks) {
            return $picks.' pick '.str('list')->plural($picks).' still open.';
        }
        $transfers = $this->records('transfers')->whereIn('status', ['draft', 'in_transit'])->get()
            ->filter(fn (Record $transfer) => in_array($warehouse->id, [(int) $transfer->value('from_warehouse'), (int) $transfer->value('to_warehouse')], true))->count();

        return $transfers ? $transfers.' '.str('transfer')->plural($transfers).' still open.' : null;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'bins') {
            $record->title = strtoupper(trim($record->title));
            if ($record->status !== 'blocked') {
                $record->status = trim((string) $record->value('contents')) === '' ? 'empty' : 'in_use';
            }

            return;
        }
        if (in_array($record->entity, ['picks', 'transfers'], true)) {
            $record->occurs_on ??= today();
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $record->value($record->entity === 'picks' ? 'lines' : 'items')) ?: [])));
            $this->put($record, ['_line_count' => count($lines)]);
            if ($record->isDirty('status')) {
                $this->put($record, ['_'.$record->status.'_at' => now()->toDateTimeString()]);
            }
        }
    }

    public function actions(Record $record): array
    {
        return match ($record->entity) {
            'bins' => $record->status === 'blocked'
                ? ['unblock' => ['label' => 'Unblock', 'icon' => 'lock-open']]
                : ['block' => ['label' => 'Block', 'icon' => 'lock', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]],
            'picks' => match ($record->status) {
                'to_pick' => ['start' => ['label' => 'Start picking', 'icon' => 'play']],
                'picking' => ['pack' => ['label' => 'Packed', 'icon' => 'package', 'fields' => [['name' => 'packages', 'label' => 'Packages', 'type' => 'number', 'value' => $record->value('packages') ?: 1]]]],
                'packed' => ['dispatch' => ['label' => 'Dispatch', 'icon' => 'truck']],
                default => [],
            },
            'transfers' => match ($record->status) {
                'draft' => ['send' => ['label' => 'Send', 'icon' => 'send'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
                'in_transit' => ['receive' => ['label' => 'Received', 'icon' => 'package-check'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
                default => [],
            },
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'block':
                $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
                $record->update(['status' => 'blocked', 'data' => [...$record->data, '_block_reason' => $reason]]);

                return 'Bin '.$record->title.' blocked: '.$reason.'.';
            case 'unblock':
                $record->update(['status' => 'empty', 'data' => [...$record->data, '_block_reason' => null]]);

                return 'Bin '.$record->title.' is '.str_replace('_', ' ', $record->status).'.';
            case 'start':
                $record->update(['status' => 'picking']);

                return $record->title.' is being picked.';
            case 'pack':
                $packages = (int) $request->validate(['packages' => ['required', 'integer', 'min:1']])['packages'];
                $record->update(['status' => 'packed', 'data' => [...$record->data, 'packages' => $packages]]);

                return $record->title.' packed in '.$packages.' '.str('package')->plural($packages).'.';
            case 'dispatch':
                $record->update(['status' => 'dispatched']);

                return $record->title.' dispatched.';
            case 'send':
                foreach (['from_warehouse', 'to_warehouse'] as $field) {
                    if ($problem = $this->inactive($record->value($field))) {
                        throw ValidationException::withMessages([$field => $problem]);
                    }
                }
                $record->update(['status' => 'in_transit']);

                return $record->number.' sent to '.$this->parent($record, 'to_warehouse')?->title.'.';
            case 'receive':
                $record->update(['status' => 'received']);

                return $record->number.' received at '.$this->parent($record, 'to_warehouse')?->title.'.';
            default:
                $record->update(['status' => 'cancelled']);

                return $record->number.' cancelled.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'warehouses') {
            return [];
        }
        $bins = $this->linked('bins', 'warehouse', $record)->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Bins', 'icon' => 'grid-3x3', 'stats' => [
            ['label' => 'In use', 'value' => (string) $bins->where('status', 'in_use')->count()],
            ['label' => 'Empty', 'value' => (string) $bins->where('status', 'empty')->count(), 'tone' => 'success'],
            ['label' => 'Blocked', 'value' => (string) $bins->where('status', 'blocked')->count(), 'tone' => $bins->contains('status', 'blocked') ? 'warning' : null],
            ['label' => 'Occupancy', 'value' => $bins->isEmpty() ? '—' : round($bins->where('status', 'in_use')->count() / $bins->count() * 100).'%'],
        ]]]];
    }

    public function homeCards(): array
    {
        $names = $this->records('warehouses')->pluck('title', 'id');
        $picks = $this->records('picks')->whereIn('status', ['to_pick', 'picking', 'packed'])->orderBy('occurs_on')->get();
        $transit = $this->records('transfers')->where('status', 'in_transit')->orderBy('occurs_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Open pick lists', 'icon' => 'list-checks', 'empty' => 'Nothing to pick.',
                'rows' => $picks->map(fn (Record $pick) => ['label' => $pick->title, 'sub' => ($names[(int) $pick->value('warehouse')] ?? '—').' · '.$pick->value('_line_count').' lines', 'value' => ucfirst(str_replace('_', ' ', $pick->status)), 'href' => $pick->url(), 'tone' => $pick->occurs_on?->lt(today()) ? 'warning' : null])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'In transit', 'icon' => 'arrow-right-left', 'empty' => 'No transfers on the road.',
                'rows' => $transit->map(fn (Record $transfer) => ['label' => $transfer->number, 'sub' => ($names[(int) $transfer->value('from_warehouse')] ?? '—').' → '.($names[(int) $transfer->value('to_warehouse')] ?? '—'), 'value' => $transfer->occurs_on?->format('d M'), 'href' => $transfer->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $names = $this->records('warehouses')->pluck('title', 'id');
        $picks = $this->dated('picks', $from, $to)->get();
        $transfers = $this->dated('transfers', $from, $to)->get();

        $byWarehouse = $picks->groupBy(fn (Record $pick) => (int) $pick->value('warehouse'))
            ->map(fn (Collection $group, int $warehouse) => [$names[$warehouse] ?? '—', $group->count(), $group->where('status', 'dispatched')->count(), $group->sum(fn (Record $pick) => (int) $pick->value('_line_count')), $group->sum(fn (Record $pick) => (int) $pick->value('packages'))])
            ->sortBy(fn (array $row) => $row[0])->values()->all();

        $lanes = $transfers->whereIn('status', ['in_transit', 'received'])->groupBy(fn (Record $transfer) => ($names[(int) $transfer->value('from_warehouse')] ?? '—').' → '.($names[(int) $transfer->value('to_warehouse')] ?? '—'))->sortKeys()
            ->map(fn (Collection $group, string $lane) => [$lane, $group->count(), $group->where('status', 'received')->count()])->values()->all();

        return [
            ['title' => 'Picking by warehouse', 'columns' => ['Warehouse', 'Pick lists', 'Dispatched', 'Lines', 'Packages'], 'rows' => $byWarehouse],
            ['title' => 'Transfers by route', 'columns' => ['Route', 'Sent', 'Received'], 'rows' => $lanes],
        ];
    }
}
