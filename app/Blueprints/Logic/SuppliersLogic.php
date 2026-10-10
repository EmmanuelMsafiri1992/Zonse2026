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
 * Suppliers & vendors: a supplier is approved only with a tax number and bank details, tax numbers are
 * unique, and blocking needs a reason. A supplier keeps one current price per item: a new current
 * price replaces the old one. Prices must be above zero, blocked suppliers can't have current prices,
 * and prices past their valid-until date expire each day. The price comparison shows the cheapest
 * current supplier for every item.
 */
class SuppliersLogic extends AppLogic
{
    /**
     * Guards against re-entering price replacement.
     */
    protected static bool $replacing = false;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'suppliers') {
            $tax = $this->normalise((string) ($data['tax_number'] ?? ''));
            $taken = $tax === '' ? null : $this->records('suppliers')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $supplier) => $this->normalise((string) $supplier->value('tax_number')) === $tax);
            if ($taken) {
                $errors['data.tax_number'] = 'Tax number '.$data['tax_number'].' belongs to '.$taken->title.'.';
            }
            if ($payload['status'] === 'approved') {
                if ($tax === '') {
                    $errors['data.tax_number'] = 'A supplier needs a tax number to be approved.';
                }
                if (blank($data['bank_details'] ?? null)) {
                    $errors['data.bank_details'] = 'A supplier needs bank details to be approved.';
                }
            }
            if ($payload['status'] === 'blocked' && $existing?->status !== 'blocked') {
                $errors['status'] = 'Use "Block" so the reason is recorded.';
            }

            return $errors;
        }

        if ((float) ($data['unit_price'] ?? 0) <= 0) {
            $errors['data.unit_price'] = 'Enter a price above zero.';
        }
        if ((float) ($data['lead_time'] ?? 0) < 0) {
            $errors['data.lead_time'] = 'Lead time cannot be negative.';
        }
        $supplier = filled($data['supplier'] ?? null) ? $this->records('suppliers')->find($data['supplier']) : null;
        if ($supplier?->status === 'blocked' && $payload['status'] === 'current') {
            $errors['data.supplier'] = $supplier->title.' is blocked.';
        }
        if ($payload['status'] === 'current' && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(today())) {
            $errors['due_on'] = 'This price has already expired.';
        }

        return $errors;
    }

    /**
     * A code in a form that matches however it was typed.
     */
    protected function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'prices' || $record->status !== 'current' || static::$replacing) {
            return;
        }
        $item = mb_strtolower(trim($record->title));
        static::$replacing = true;
        try {
            $this->linked('prices', 'supplier', (int) $record->value('supplier'))->where('status', 'current')->whereKeyNot($record->id)->get()
                ->filter(fn (Record $price) => mb_strtolower(trim($price->title)) === $item)
                ->each(fn (Record $price) => $price->update(['status' => 'expired', 'data' => [...$price->data, '_replaced_by' => $record->id]]));
        } finally {
            static::$replacing = false;
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'suppliers') {
            return [];
        }

        return [
            ...($record->status === 'pending' ? ['approve' => ['label' => 'Approve', 'icon' => 'check']] : []),
            ...($record->status !== 'blocked'
                ? ['block' => ['label' => 'Block', 'icon' => 'ban', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]]
                : ['unblock' => ['label' => 'Unblock', 'icon' => 'check']]),
            'rate' => ['label' => 'Rate', 'icon' => 'star', 'fields' => [['name' => 'rating', 'label' => 'Rating (1–5)', 'type' => 'number', 'value' => $record->value('rating')]]],
        ];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'approve':
                $missing = array_keys(array_filter(['tax number' => blank($record->value('tax_number')), 'bank details' => blank($record->value('bank_details'))]));
                if ($missing) {
                    throw ValidationException::withMessages(['status' => $record->title.' needs '.implode(' and ', $missing).' to be approved.']);
                }
                $record->update(['status' => 'approved']);

                return $record->title.' approved.';
            case 'block':
                $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
                $record->update(['status' => 'blocked', 'data' => [...$record->data, '_block_reason' => $reason]]);
                $expired = $this->linked('prices', 'supplier', $record)->where('status', 'current')->get();
                $expired->each(fn (Record $price) => $price->update(['status' => 'expired']));

                return $record->title.' blocked'.($expired->isNotEmpty() ? '; '.$expired->count().' '.str('price')->plural($expired->count()).' withdrawn.' : '.');
            case 'unblock':
                $record->update(['status' => 'pending', 'data' => [...$record->data, '_block_reason' => null]]);

                return $record->title.' unblocked and waiting for approval.';
            default:
                $rating = (int) $request->validate(['rating' => ['required', 'integer', 'between:1,5']])['rating'];
                $record->update(['data' => [...$record->data, 'rating' => (string) $rating]]);

                return $record->title.' rated '.$rating.' out of 5.';
        }
    }

    public function daily(Workspace $workspace): int
    {
        $lapsed = $this->records('prices')->where('status', 'current')->whereNotNull('due_on')->where('due_on', '<', today()->startOfDay())->get();
        $lapsed->each(fn (Record $price) => $price->update(['status' => 'expired']));

        return $lapsed->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'suppliers') {
            return [];
        }
        $prices = $this->linked('prices', 'supplier', $record)->where('status', 'current')->orderBy('title')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Price list', 'icon' => 'tags', 'empty' => 'No current prices.',
            'rows' => $prices->map(fn (Record $price) => ['label' => $price->title, 'sub' => trim(($price->value('lead_time') !== null ? $price->value('lead_time').' days' : '').($price->value('minimum_order') ? ' · min '.$price->value('minimum_order') : ''), ' ·'), 'value' => $this->money($price->value('unit_price')), 'href' => $price->url()])->values()->all(),
        ]]];
    }

    /**
     * Current prices grouped by item, cheapest first.
     *
     * @return Collection<string, Collection<int, Record>>
     */
    protected function comparison(): Collection
    {
        return $this->records('prices')->where('status', 'current')->get()
            ->groupBy(fn (Record $price) => mb_strtolower(trim($price->title)))
            ->map(fn (Collection $group) => $group->sortBy(fn (Record $price) => $this->number($price, 'unit_price'))->values())
            ->sortKeys();
    }

    public function homeCards(): array
    {
        $names = $this->records('suppliers')->pluck('title', 'id');
        $expiring = $this->records('prices')->where('status', 'current')->whereNotNull('due_on')->where('due_on', '<=', today()->addDays(14)->endOfDay())->orderBy('due_on')->get();
        $pending = $this->records('suppliers')->where('status', 'pending')->orderBy('title')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Waiting for approval', 'icon' => 'user-check', 'empty' => 'No suppliers waiting.',
                'rows' => $pending->map(fn (Record $supplier) => ['label' => $supplier->title, 'sub' => $supplier->value('category'), 'value' => blank($supplier->value('tax_number')) || blank($supplier->value('bank_details')) ? 'Details missing' : 'Ready', 'href' => $supplier->url(), 'tone' => blank($supplier->value('tax_number')) || blank($supplier->value('bank_details')) ? 'warning' : 'success'])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Prices expiring in 14 days', 'icon' => 'calendar-clock', 'empty' => 'No prices expiring soon.',
                'rows' => $expiring->map(fn (Record $price) => ['label' => $price->title, 'sub' => $names[(int) $price->value('supplier')] ?? '—', 'value' => $price->due_on->format('d M'), 'href' => $price->url(), 'tone' => 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $names = $this->records('suppliers')->pluck('title', 'id');

        $comparison = $this->comparison()->map(function (Collection $prices) use ($names) {
            $best = $prices->first();
            $dearest = $prices->last();

            return [
                $best->title, $prices->count(), $names[(int) $best->value('supplier')] ?? '—', $this->money($best->value('unit_price')),
                $best->value('lead_time') !== null ? $best->value('lead_time').' days' : '—',
                $prices->count() > 1 ? round(($this->number($dearest, 'unit_price') - $this->number($best, 'unit_price')) / $this->number($dearest, 'unit_price') * 100, 1).'%' : '—',
            ];
        })->values()->all();

        $suppliers = $this->records('suppliers')->orderBy('title')->get()
            ->map(fn (Record $supplier) => [$supplier->title, ucfirst($supplier->status), $supplier->value('rating') ? $supplier->value('rating').' / 5' : '—', $this->linked('prices', 'supplier', $supplier)->where('status', 'current')->count(), $supplier->value('payment_terms') ?: '—'])
            ->values()->all();

        return [
            ['title' => 'Price comparison', 'columns' => ['Item', 'Suppliers', 'Cheapest', 'Price', 'Lead time', 'Saving vs dearest'], 'rows' => $comparison],
            ['title' => 'Supplier register', 'columns' => ['Supplier', 'Status', 'Rating', 'Current prices', 'Terms'], 'rows' => $suppliers],
        ];
    }
}
