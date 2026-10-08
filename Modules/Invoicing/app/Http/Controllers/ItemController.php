<?php

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Http\Requests\ItemRequest;
use Modules\Invoicing\Models\Item;
use Modules\Invoicing\Models\TaxRate;

class ItemController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Item::class);

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'type' => $request->query('type') ?: null,
            'status' => (string) $request->query('status', 'active'),
        ];

        $items = Item::query()->with('taxRate')->search($filters['q'])
            ->when($filters['type'], fn (Builder $q, string $type) => $q->where('type', $type))
            ->when($filters['status'] === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('invoicing::items.index', [
            'items' => $items,
            'filters' => $filters,
            'types' => Item::TYPES,
            'taxRates' => TaxRate::query()->active()->orderBy('rate')->pluck('name', 'id'),
        ]);
    }

    public function store(ItemRequest $request): RedirectResponse
    {
        $this->authorize('create', Item::class);

        $item = Item::create($request->payload());

        return redirect()->route('items.index')->with('flash', ['type' => 'success', 'message' => $item->name.' was added.']);
    }

    public function update(ItemRequest $request, Item $item): RedirectResponse
    {
        $this->authorize('update', $item);

        $item->update($request->payload());

        return redirect()->route('items.index')->with('flash', ['type' => 'success', 'message' => $item->name.' was updated.']);
    }

    public function destroy(Item $item): RedirectResponse
    {
        $this->authorize('delete', $item);

        $item->delete();

        return redirect()->route('items.index')->with('flash', ['type' => 'success', 'message' => $item->name.' was deleted. Existing invoice lines keep their details.']);
    }
}
