<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Product catalogue & price lists: each product code is used once and every item has a list price. A
 * price list's discount is between 0 and 100%, it ends after it starts, and a customer group has only one
 * active list at a time. Lists past their end date expire each night. Every item shows its price on each
 * active list, and every list shows what its discount does to the catalogue.
 */
class CatalogueLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'items') {
            $code = mb_strtolower(trim((string) ($data['code'] ?? '')));
            if ($code !== '' && ($twin = $this->records('items')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->first(fn (Record $item) => mb_strtolower(trim((string) $item->value('code'))) === $code))) {
                $errors['data.code'] = $twin->title.' already uses code '.$data['code'].'.';
            }
            if ((float) ($data['list_price'] ?? 0) <= 0) {
                $errors['data.list_price'] = 'Set the list price.';
            }
        }
        if ($entity->key === 'price_lists') {
            $discount = (float) ($data['discount_percent'] ?? 0);
            if ($discount < 0 || $discount > 100) {
                $errors['data.discount_percent'] = 'The discount must be between 0 and 100%.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The list must end after it starts.';
            }
            $group = mb_strtolower(trim((string) ($data['customer_group'] ?? '')));
            if ($payload['status'] === 'active' && ($other = $this->records('price_lists')->where('status', 'active')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $list) => mb_strtolower(trim((string) $list->value('customer_group'))) === $group))) {
                $errors['data.customer_group'] = ($group === '' ? 'Everyone' : $data['customer_group']).' already has an active price list: '.$other->title.'.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'price_lists' && $record->status === 'active' && $record->due_on && $record->due_on->lt(today())) {
            $record->status = 'expired';
        }
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('price_lists')->where('status', 'active')->whereDate('due_on', '<', today()->toDateString())->get()
            ->each(fn (Record $list) => $list->save())->count();
    }

    /**
     * An item's price on a price list.
     */
    public function priceOn(Record $item, Record $list): float
    {
        return round($this->number($item, 'list_price') * (1 - $this->number($list, 'discount_percent') / 100), 2);
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'items') {
            $lists = $this->records('price_lists')->where('status', 'active')->orderBy('title')->get();

            return [['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Prices', 'icon' => 'tags', 'empty' => 'No active price lists.',
                'rows' => [['label' => 'List price', 'value' => $this->money($this->number($record, 'list_price'))],
                    ...$lists->map(fn (Record $list) => ['label' => $list->title, 'sub' => $list->value('customer_group'), 'value' => $this->money($this->priceOn($record, $list)), 'href' => $list->url()])->all()],
            ]]];
        }
        $items = $this->records('items')->where('status', 'active')->orderBy('title')->limit(15)->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Prices on this list', 'icon' => 'tags', 'empty' => 'The catalogue has no active items.',
            'rows' => $items->map(fn (Record $item) => ['label' => $item->title, 'sub' => $item->value('code').' · list '.$this->money($this->number($item, 'list_price')), 'value' => $this->money($this->priceOn($item, $record)), 'href' => $item->url()])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $ending = $this->records('price_lists')->where('status', 'active')->whereNotNull('due_on')->whereDate('due_on', '<=', today()->addDays(14)->toDateString())->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Price lists ending soon', 'icon' => 'calendar-clock', 'empty' => 'No price list ends in the next 14 days.',
            'rows' => $ending->map(fn (Record $list) => ['label' => $list->title, 'sub' => $list->value('customer_group'), 'value' => $list->due_on->format('d M'), 'href' => $list->url(), 'tone' => 'warning'])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $items = $this->records('items')->get();

        return [
            ['title' => 'Price lists', 'columns' => ['Price list', 'Customer group', 'Discount', 'Status', 'Valid from', 'Valid to'], 'rows' => $this->records('price_lists')->orderBy('title')->get()
                ->map(fn (Record $list) => [$list->title, $list->value('customer_group') ?: 'Everyone', $this->number($list, 'discount_percent').'%', ucfirst($list->status), $list->occurs_on?->format('d M Y') ?? '—', $list->due_on?->format('d M Y') ?? '—'])->all()],
            ['title' => 'Catalogue by brand', 'columns' => ['Brand', 'Active items', 'Discontinued', 'Average list price'], 'rows' => $items
                ->groupBy(fn (Record $item) => (string) ($item->value('brand') ?: 'No brand'))->sortKeys()
                ->map(fn ($group, string $brand) => [$brand, $group->where('status', 'active')->count(), $group->where('status', 'discontinued')->count(), $this->money($group->avg(fn (Record $item) => $this->number($item, 'list_price')))])
                ->values()->all()],
        ];
    }
}
