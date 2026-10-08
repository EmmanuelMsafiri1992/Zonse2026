<?php

namespace App\Http\Controllers\Apps;

use App\Blueprints\BlueprintRegistry;
use App\Blueprints\Logic\PosLogic;
use App\Http\Controllers\Controller;
use App\Models\Record;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Item;

/** The point-of-sale till: pick items, take payment, print the receipt. */
class PosController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected BlueprintRegistry $blueprints, protected WorkspaceContext $context) {}

    public function till(): View
    {
        $this->authorize('create', Record::class);

        return view('apps.pos.till', [
            'app' => $this->blueprints->get('pos'),
            'workspace' => $this->context->getOrFail(),
            'items' => Item::query()->active()->with('taxRate')->orderBy('name')->get()->map(fn (Item $item) => [
                ...$item->toPickerRow(), 'sku' => $item->sku, 'stock' => $item->tracksStock() ? (float) $item->stock_qty : null,
            ])->values(),
            'tills' => Record::query()->ofEntity('pos', 'tills')->where('status', 'active')->orderBy('title')->pluck('title', 'id'),
            'contacts' => Contact::query()->where('is_active', true)->where('name', '!=', PosLogic::WALK_IN_CUSTOMER)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function sell(Request $request): RedirectResponse
    {
        $this->authorize('create', Record::class);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:100000'],
            'payment_method' => ['required', Rule::in(array_keys(PosLogic::PAYMENT_METHODS))],
            'tendered' => ['nullable', 'numeric', 'min:0'],
            'till' => ['nullable', 'integer', Rule::exists('records', 'id')->where('blueprint', 'pos')->where('entity', 'tills')->where('workspace_id', $this->context->id())],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('workspace_id', $this->context->id())],
        ], [], ['lines' => 'items']);

        /** @var PosLogic $logic */
        $logic = $this->blueprints->get('pos')->logic();
        $sale = $logic->sell(
            $validated['lines'], $validated['payment_method'],
            isset($validated['tendered']) ? (float) $validated['tendered'] : null,
            $validated['till'] ?? null, $validated['contact_id'] ?? null, $request->user(),
        );

        return redirect()->route('apps.pos.till', ['till' => $validated['till'] ?? null])
            ->with('flash', ['type' => 'success', 'message' => 'Sale '.$sale->number.' recorded. Change: '.number_format((float) $sale->value('change'), 2).'.'])
            ->with('lastSale', ['number' => $sale->number, 'receipt' => route('apps.records.document', ['pos', 'sales', $sale->id, 'receipt']), 'change' => (float) $sale->value('change')]);
    }
}
