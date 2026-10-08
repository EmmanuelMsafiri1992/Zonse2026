<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\InvoiceResource;
use App\Http\Resources\V1\PaymentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Payment;

/** Read-only: invoices are built and sent from the app, where totals and numbering are checked. */
class InvoiceController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Invoice::class);

        $invoices = Invoice::query()->search($request->string('q')->toString())
            ->status($request->string('status')->toString() ?: null)
            ->forContact($request->input('contact_id'))
            ->latest('id')->paginate($this->perPage($request))->withQueryString();

        return InvoiceResource::collection($invoices);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        $this->authorize('view', $invoice);

        return new InvoiceResource($invoice->load('lines'));
    }

    public function payments(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Invoice::class);

        $payments = Payment::query()
            ->when($request->input('invoice_id'), fn ($query, $invoiceId) => $query->where('invoice_id', $invoiceId))
            ->latest('id')->paginate($this->perPage($request))->withQueryString();

        return PaymentResource::collection($payments);
    }
}
