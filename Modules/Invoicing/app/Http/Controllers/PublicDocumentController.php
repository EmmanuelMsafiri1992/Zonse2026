<?php

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Approvals;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Modules\Invoicing\Documents\DocumentPdf;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Quote;
use Modules\Invoicing\Payments\PaymentGateways;
use Symfony\Component\HttpFoundation\Response;

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
        abort_if(Approvals::blocking($invoice, 'invoice.send') !== null, 404);
        $invoice->load(['contact', 'branch', 'lines', 'payments', 'fiscalDocuments']);
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
        abort_if(Approvals::blocking($quote, 'quote.send') !== null, 404);
        $quote->load(['contact', 'branch', 'lines']);

        return view('invoicing::quotes.print', ['quote' => $quote, 'document' => $quote, 'kind' => 'quote', 'public' => true]);
    }

    public function invoicePdf(string $uuid, WorkspaceContext $context): Response
    {
        $invoice = Invoice::allWorkspaces()->where('uuid', $uuid)->with('workspace')->firstOrFail();
        $context->set($invoice->workspace);
        abort_if(Approvals::blocking($invoice, 'invoice.send') !== null, 404);
        $invoice->load(['contact', 'branch', 'lines', 'payments', 'fiscalDocuments']);

        return DocumentPdf::response($invoice, 'invoice');
    }

    public function quotePdf(string $uuid, WorkspaceContext $context): Response
    {
        $quote = Quote::allWorkspaces()->where('uuid', $uuid)->with('workspace')->firstOrFail();
        $context->set($quote->workspace);
        abort_if(Approvals::blocking($quote, 'quote.send') !== null, 404);
        $quote->load(['contact', 'branch', 'lines']);

        return DocumentPdf::response($quote, 'quote');
    }
}
