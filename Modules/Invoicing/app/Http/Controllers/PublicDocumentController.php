<?php

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Quote;
use Modules\Invoicing\Payments\PaymentGateways;

/**
 * Share links. A customer can open an invoice or quote by its unguessable UUID
 * without signing in; the workspace context is set from the document itself.
 * An open invoice also offers the workspace's online payment gateways.
 */
class PublicDocumentController extends Controller
{
    public function invoice(string $uuid, WorkspaceContext $context, PaymentGateways $gateways): View
    {
        $invoice = Invoice::allWorkspaces()->where('uuid', $uuid)->with('workspace')->firstOrFail();
        $context->set($invoice->workspace);
        $invoice->load(['contact', 'branch', 'lines', 'payments']);
        $payable = $invoice->isOpen() && $invoice->balance > 0 && $context->hasModule('invoicing');

        return view('invoicing::invoices.print', [
            'invoice' => $invoice, 'document' => $invoice, 'kind' => 'invoice', 'public' => true,
            'gateways' => $payable ? $gateways->enabledFor($invoice->workspace) : [],
        ]);
    }

    public function quote(string $uuid, WorkspaceContext $context): View
    {
        $quote = Quote::allWorkspaces()->where('uuid', $uuid)->with('workspace')->firstOrFail();
        $context->set($quote->workspace);
        $quote->load(['contact', 'branch', 'lines']);

        return view('invoicing::quotes.print', ['quote' => $quote, 'document' => $quote, 'kind' => 'quote', 'public' => true]);
    }
}
