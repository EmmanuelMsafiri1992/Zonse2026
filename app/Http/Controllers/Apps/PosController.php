<?php

namespace App\Http\Controllers\Apps;

use App\Blueprints\BlueprintRegistry;
use App\Blueprints\Logic\PosLogic;
use App\Http\Controllers\Controller;
use App\Models\Record;
use App\Support\Hardware\CardTerminal;
use App\Support\Hardware\HardwareSettings;
use App\Support\Hardware\QrCode;
use App\Support\Hardware\Receipt;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Item;

/** The point-of-sale till: pick items, take payment, print the receipt. */
class PosController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected BlueprintRegistry $blueprints, protected WorkspaceContext $context, protected CardTerminal $terminal) {}

    public function till(): View
    {
        $this->authorize('create', Record::class);

        return view('apps.pos.till', [
            'app' => $this->blueprints->get('pos'),
            'workspace' => $this->context->getOrFail(),
            'items' => Item::query()->active()->with('taxRate')->orderBy('name')->get()->map(fn (Item $item) => [
                ...$item->toPickerRow(), 'sku' => $item->sku, 'barcode' => $item->barcode, 'stock' => $item->tracksStock() ? (float) $item->stock_qty : null,
            ])->values(),
            'cardTerminal' => $this->terminal->linked($this->context->getOrFail()),
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
            ->with('lastSale', [
                'number' => $sale->number, 'change' => (float) $sale->value('change'),
                'receipt' => route('apps.pos.receipt', $sale), 'escpos' => route('apps.pos.receipt.escpos', $sale),
            ]);
    }

    /** A slip sized for the receipt printer's paper, printed straight from the browser. */
    public function receipt(Record $sale): View
    {
        $this->authorizeSale($sale);
        $workspace = $this->context->getOrFail();
        $receipt = Receipt::forSale($sale, $workspace);

        return view('apps.pos.receipt', [
            'receipt' => $receipt,
            'paperWidth' => HardwareSettings::for($workspace)['receipt_width'],
            'qr' => $receipt['qr'] ? QrCode::svg($receipt['qr'], 120) : null,
        ]);
    }

    /** The receipt as ESC/POS bytes, for sending straight to a thermal printer. */
    public function escPos(Record $sale): Response
    {
        $this->authorizeSale($sale);
        $workspace = $this->context->getOrFail();
        $settings = HardwareSettings::for($workspace);

        return response(Receipt::escPos(Receipt::forSale($sale, $workspace), $settings['receipt_width'], $settings['open_drawer']), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$sale->number.'.bin"',
            'Cache-Control' => 'no-store',
        ]);
    }

    protected function authorizeSale(Record $sale): void
    {
        abort_unless($sale->blueprint === 'pos' && $sale->entity === 'sales', 404);
        $this->authorize('view', $sale);
    }
}
