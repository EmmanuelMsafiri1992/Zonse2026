<?php

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Hardware\Barcodes;
use App\Support\Hardware\QrCode;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Invoicing\Http\Requests\ItemRequest;
use Modules\Invoicing\Models\Item;
use Modules\Invoicing\Models\TaxRate;

class ItemController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected WorkspaceContext $context) {}

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

    /** What a barcode scanner just read: the item and how many (scale labels carry a weight or price). */
    public function scan(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Item::class);
        $code = (string) $request->query('code', '');
        $found = Barcodes::lookup($this->context->getOrFail(), $code);

        if (! $found) {
            return response()->json(['message' => 'No item has the code '.Str::limit($code, 64).'.'], 404);
        }

        return response()->json([
            'item' => [...$found['item']->toPickerRow(), 'sku' => $found['item']->sku, 'barcode' => $found['item']->barcode],
            'quantity' => $found['quantity'],
        ]);
    }

    /** Give an item without a barcode the next in-store code, ready for printing on labels. */
    public function barcode(Item $item): RedirectResponse
    {
        $this->authorize('update', $item);

        if ($item->barcode) {
            return back()->with('flash', ['type' => 'info', 'message' => $item->name.' already has a barcode.']);
        }

        $item->update(['barcode' => Barcodes::nextInStoreCode($this->context->getOrFail())]);

        return back()->with('flash', ['type' => 'success', 'message' => $item->name.' now has barcode '.$item->barcode.'.']);
    }

    /** Shelf or product labels with name, price and barcode (or a QR code for other codes). */
    public function labels(Request $request): View
    {
        $this->authorize('viewAny', Item::class);
        $validated = $request->validate([
            'items' => ['required', 'array', 'max:200'],
            'items.*' => ['integer'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:50'],
        ], ['items.required' => 'Tick the items to print labels for.']);

        $items = Item::query()->whereKey($validated['items'])->orderBy('name')->get();
        abort_if($items->isEmpty(), 404);

        return view('invoicing::items.labels', [
            'labels' => $items->flatMap(fn (Item $item) => array_fill(0, (int) ($validated['copies'] ?? 1), $item)),
            'qrCodes' => $items->mapWithKeys(fn (Item $item) => [
                $item->id => ! $item->barcodeSvg() && ($item->barcode || $item->sku) ? QrCode::svg((string) ($item->barcode ?: $item->sku), 90) : null,
            ]),
        ]);
    }

    public function destroy(Item $item): RedirectResponse
    {
        $this->authorize('delete', $item);

        $item->delete();

        return redirect()->route('items.index')->with('flash', ['type' => 'success', 'message' => $item->name.' was deleted. Existing invoice lines keep their details.']);
    }
}
