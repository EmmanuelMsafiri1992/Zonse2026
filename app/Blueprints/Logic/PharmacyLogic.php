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
 * Pharmacy: a drug's stock status follows its stock on hand and reorder level, and receiving stock
 * moves its nearest expiry. Dispensing takes the quantity off the shelf (and puts it back when it is
 * returned or deleted), never more than is in stock, and prescription and controlled drugs need a
 * prescriber and script number. Every controlled-drug movement lands in the register with its
 * running balance, and destroying controlled stock needs a witness other than the person recording it.
 */
class PharmacyLogic extends AppLogic
{
    /** @var list<string> */
    public const INCOMING = ['received', 'returned'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'drugs') {
            if ((float) ($data['quantity'] ?? 0) < 0) {
                $errors['data.quantity'] = 'Stock cannot be negative.';
            }

            return $errors;
        }

        $drug = filled($data['drug'] ?? null) ? $this->records('drugs')->find($data['drug']) : null;
        $quantity = (float) ($data['quantity'] ?? 0);
        if ($quantity <= 0) {
            $errors['data.quantity'] = 'Enter a quantity above zero.';
        }
        if (! $drug) {
            return $errors;
        }

        if ($entity->key === 'dispensings') {
            if ($drug->status === 'discontinued' && ! $existing) {
                $errors['data.drug'] = $drug->title.' is discontinued.';
            }
            $alreadyTaken = $existing && (int) $existing->value('drug') === $drug->id ? (float) $existing->value('_deducted') : 0;
            if ($payload['status'] !== 'returned' && $quantity > $this->number($drug, 'quantity') + $alreadyTaken) {
                $errors['data.quantity'] = 'Only '.$this->stock($drug).' of '.$drug->title.' in stock.';
            }
            if (in_array($drug->value('schedule'), ['prescription', 'controlled'], true)) {
                foreach (['prescriber' => 'Who prescribed it?', 'script_number' => 'Enter the script number.'] as $field => $message) {
                    if (blank($data[$field] ?? null)) {
                        $errors['data.'.$field] = $drug->title.' needs a prescription. '.$message;
                    }
                }
            }
            if ($drug->due_on?->lt(today()) && ! $existing) {
                $errors['data.drug'] = $drug->title.' stock expired on '.$drug->due_on->format('d M Y').'.';
            }

            return $errors;
        }

        if ($drug->value('schedule') !== 'controlled') {
            $errors['data.drug'] = $drug->title.' is not a controlled drug.';
        }
        if (! $existing && ! in_array($data['movement'] ?? null, self::INCOMING, true) && $quantity > $this->balance($drug)) {
            $errors['data.quantity'] = 'The register only holds '.$this->balance($drug).' of '.$drug->title.'.';
        }
        if (($data['movement'] ?? null) === 'destroyed') {
            if (blank($data['witness'] ?? null)) {
                $errors['data.witness'] = 'Destroying a controlled drug needs a witness.';
            } elseif ((int) $data['witness'] === (int) ($payload['assignee_id'] ?? auth()->id())) {
                $errors['data.witness'] = 'The witness must be someone else.';
            }
        }

        return $errors;
    }

    /**
     * Stock on hand as a whole number where it is one.
     */
    protected function stock(Record $drug): string
    {
        return (string) (0 + $this->number($drug, 'quantity'));
    }

    /**
     * The controlled-drug register's current balance for a drug.
     */
    protected function balance(Record $drug): float
    {
        return (float) ($this->linked('controlled', 'drug', $drug)->orderByDesc('id')->first()?->value('balance') ?? 0);
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'drugs') {
            if ($record->status !== 'discontinued') {
                $quantity = $this->number($record, 'quantity');
                $record->status = match (true) {
                    $quantity <= 0 => 'out_of_stock',
                    $quantity <= $this->number($record, 'reorder_level') => 'low_stock',
                    default => 'in_stock',
                };
            }
            $this->put($record, ['schedule' => $record->value('schedule') ?: 'otc', '_expired' => (bool) $record->due_on?->lt(today())]);

            return;
        }

        $record->occurs_on ??= today();
        if ($record->entity === 'dispensings') {
            $this->put($record, ['_deducted' => $record->status === 'returned' ? 0 : $this->number($record, 'quantity')]);

            return;
        }

        if (! $record->exists && $drug = $this->parent($record, 'drug')) {
            $quantity = $this->number($record, 'quantity');
            $this->put($record, ['balance' => $this->balance($drug) + (in_array($record->value('movement'), self::INCOMING, true) ? $quantity : -$quantity)]);
        }
        $record->assignee_id ??= auth()->id();
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'dispensings') {
            return;
        }
        $before = (array) ($record->getOriginal('data') ?? []);
        $previous = $record->wasRecentlyCreated ? null : $this->previousParent($record, 'drug');
        $drug = $this->parent($record, 'drug');

        if ($previous && $previous->id !== $drug?->id) {
            $this->restock($previous, (float) ($before['_deducted'] ?? 0));
            $this->restock($drug, -$this->number($record, '_deducted'));
        } else {
            $this->restock($drug, (float) ($before['_deducted'] ?? 0) - $this->number($record, '_deducted'));
        }

        if ($record->wasRecentlyCreated && $drug?->value('schedule') === 'controlled' && $record->status !== 'returned') {
            Record::create([
                'workspace_id' => $record->workspace_id,
                'blueprint' => $record->blueprint,
                'entity' => 'controlled',
                'title' => 'Dispensed to '.$record->title.($record->value('script_number') ? ' ('.$record->value('script_number').')' : ''),
                'status' => 'recorded',
                'occurs_on' => $record->occurs_on,
                'assignee_id' => $record->assignee_id ?? auth()->id(),
                'data' => ['drug' => $drug->id, 'movement' => 'dispensed', 'quantity' => $this->number($record, 'quantity')],
            ]);
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'dispensings') {
            $this->restock($this->parent($record, 'drug'), $this->number($record, '_deducted'));
        }
    }

    /**
     * Put stock back on (or, when negative, take it off) a drug's shelf.
     */
    protected function restock(?Record $drug, float $quantity): void
    {
        if ($drug && $quantity != 0) {
            $drug->update(['data' => [...$drug->data, 'quantity' => $this->number($drug, 'quantity') + $quantity]]);
        }
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'drugs' && $record->status !== 'discontinued' => ['receive' => ['label' => 'Receive stock', 'icon' => 'package-plus', 'fields' => [
                ['name' => 'quantity', 'label' => 'Quantity received', 'type' => 'number'],
                ['name' => 'expiry', 'label' => 'Expiry date', 'type' => 'date'],
                ['name' => 'supplier', 'label' => 'Supplier / invoice', 'type' => 'text'],
            ]]],
            $record->entity === 'dispensings' && $record->status !== 'returned' => ['return' => ['label' => 'Returned', 'icon' => 'undo-2']],
            $record->entity === 'controlled' && $record->status === 'recorded' => ['verify' => ['label' => 'Verify', 'icon' => 'shield-check', 'fields' => [['name' => 'witness', 'label' => 'Witness', 'type' => 'select', 'options' => $this->members($record)]]]],
            default => [],
        };
    }

    /**
     * Workspace members who can witness the register.
     *
     * @return array<int, string>
     */
    protected function members(Record $record): array
    {
        return Workspace::query()->find($record->workspace_id)?->members()->pluck('users.name', 'users.id')->all() ?? [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'receive') {
            $input = $request->validate(['quantity' => ['required', 'numeric', 'gt:0'], 'expiry' => ['required', 'date', 'after:today'], 'supplier' => ['nullable', 'string']]);
            $quantity = (float) $input['quantity'];
            $expiry = Carbon::parse($input['expiry']);
            $nearest = $record->due_on && $record->due_on->gte(today()) && $this->number($record, 'quantity') > 0 ? $record->due_on->min($expiry) : $expiry;
            $record->update(['due_on' => $nearest, 'data' => [...$record->data, 'quantity' => $this->number($record, 'quantity') + $quantity]]);

            if ($record->value('schedule') === 'controlled') {
                Record::create([
                    'workspace_id' => $record->workspace_id,
                    'blueprint' => $record->blueprint,
                    'entity' => 'controlled',
                    'title' => 'Received'.(filled($input['supplier'] ?? null) ? ' from '.$input['supplier'] : ''),
                    'status' => 'recorded',
                    'occurs_on' => today(),
                    'assignee_id' => $request->user()?->id,
                    'data' => ['drug' => $record->id, 'movement' => 'received', 'quantity' => $quantity],
                ]);
            }

            return (0 + $quantity).' '.$record->title.' received; '.$this->stock($record->fresh()).' in stock.';
        }

        if ($action === 'return') {
            $record->update(['status' => 'returned']);

            return ($this->parent($record, 'drug')?->title ?? 'Stock').' returned to stock.';
        }

        $witness = (int) $request->validate(['witness' => ['required', 'integer']])['witness'];
        if ($witness === (int) $record->assignee_id || ! array_key_exists($witness, $this->members($record))) {
            throw ValidationException::withMessages(['witness' => 'Choose another member of the team as the witness.']);
        }
        $record->update(['status' => 'verified', 'data' => [...$record->data, 'witness' => $witness]]);

        return 'Register entry verified.';
    }

    public function homeCards(): array
    {
        $drugs = $this->records('drugs')->get();
        $reorder = $drugs->whereIn('status', ['low_stock', 'out_of_stock'])->sortBy(fn (Record $drug) => $this->number($drug, 'quantity'));
        $expiring = $drugs->filter(fn (Record $drug) => $drug->due_on && $drug->due_on->lte(today()->addDays(90)) && $this->number($drug, 'quantity') > 0)->sortBy('due_on');
        $today = $this->records('dispensings')->whereDate('occurs_on', today())->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Pharmacy', 'icon' => 'pill', 'stats' => [
                ['label' => 'Dispensed today', 'value' => (string) $today->where('status', '!=', 'returned')->count()],
                ['label' => 'Takings today', 'value' => $this->money($today->where('status', '!=', 'returned')->sum('amount'))],
                ['label' => 'To reorder', 'value' => (string) $reorder->count(), 'tone' => $reorder->where('status', 'out_of_stock')->isNotEmpty() ? 'danger' : ($reorder->isNotEmpty() ? 'warning' : null)],
                ['label' => 'Expired on the shelf', 'value' => (string) $drugs->filter(fn (Record $drug) => $drug->value('_expired') && $this->number($drug, 'quantity') > 0)->count(), 'tone' => $drugs->contains(fn (Record $drug) => $drug->value('_expired') && $this->number($drug, 'quantity') > 0) ? 'danger' : null],
                ['label' => 'Register entries to verify', 'value' => (string) $this->records('controlled')->where('status', 'recorded')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Reorder', 'icon' => 'package-search', 'empty' => 'Everything is above its reorder level.',
                'rows' => $reorder->take(10)->map(fn (Record $drug) => [
                    'label' => trim($drug->title.' '.$drug->value('strength')), 'sub' => 'Reorder at '.(0 + $this->number($drug, 'reorder_level')), 'value' => $this->stock($drug), 'href' => $drug->url(), 'tone' => $drug->status === 'out_of_stock' ? 'danger' : 'warning',
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Expiring soon', 'icon' => 'calendar-x', 'empty' => 'Nothing expires in the next 90 days.',
                'rows' => $expiring->take(10)->map(fn (Record $drug) => [
                    'label' => trim($drug->title.' '.$drug->value('strength')), 'sub' => $this->stock($drug).' in stock', 'value' => $drug->due_on->format('d M Y'), 'href' => $drug->url(), 'tone' => $drug->value('_expired') ? 'danger' : 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $drugs = $this->records('drugs')->get()->keyBy('id');
        $dispensed = $this->dated('dispensings', $from, $to)->where('status', '!=', 'returned')->get();
        $byDrug = $dispensed->groupBy(fn (Record $dispensing) => $drugs->get($dispensing->value('drug'))?->title ?? 'Unknown')->sortKeys()->map(fn (Collection $group, string $drug) => [
            $drug, $group->count(), 0 + $group->sum(fn (Record $dispensing) => $this->number($dispensing, 'quantity')), $this->money($group->sum('amount')),
        ])->values()->all();

        $register = $this->dated('controlled', $from, $to)->get();
        $controlled = $drugs->filter(fn (Record $drug) => $drug->value('schedule') === 'controlled')->sortBy('title')->map(function (Record $drug) use ($register) {
            $entries = $register->filter(fn (Record $entry) => (int) $entry->value('drug') === $drug->id);
            $moved = fn (string $movement) => 0 + $entries->filter(fn (Record $entry) => $entry->value('movement') === $movement)->sum(fn (Record $entry) => $this->number($entry, 'quantity'));

            return [$drug->title, $moved('received'), $moved('dispensed'), $moved('returned'), $moved('destroyed'), 0 + $this->balance($drug), $entries->where('status', 'recorded')->count()];
        })->values()->all();

        $stock = $drugs->sortBy('title')->map(fn (Record $drug) => [trim($drug->title.' '.$drug->value('strength')), ucfirst(str_replace('_', ' ', $drug->status)), $this->stock($drug), 0 + $this->number($drug, 'reorder_level'), $drug->due_on?->format('d M Y') ?? '—', $this->money($this->number($drug, 'quantity') * $this->number($drug, 'selling_price'))])->values()->all();

        return [
            ['title' => 'Dispensing by drug', 'columns' => ['Drug', 'Dispensings', 'Quantity', 'Charged'], 'rows' => $byDrug],
            ['title' => 'Controlled-drug register', 'columns' => ['Drug', 'Received', 'Dispensed', 'Returned', 'Destroyed', 'Balance', 'Unverified'], 'rows' => $controlled],
            ['title' => 'Stock on hand', 'columns' => ['Drug', 'Status', 'On hand', 'Reorder level', 'Nearest expiry', 'Value at selling price'], 'rows' => $stock],
        ];
    }
}
