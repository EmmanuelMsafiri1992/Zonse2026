<?php

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\OnlinePayment;
use Modules\Invoicing\Payments\GatewayException;
use Modules\Invoicing\Payments\PaymentGateways;

/**
 * Customers paying a shared invoice online. No sign-in: the invoice is found by its UUID and the
 * attempt by its own UUID. Money is recorded only after the gateway confirms it, either when the
 * customer comes back or when the gateway notifies us, whichever happens first.
 */
class OnlinePaymentController extends Controller
{
    public function __construct(protected PaymentGateways $gateways) {}

    public function start(string $uuid, string $gateway, WorkspaceContext $context): RedirectResponse
    {
        $invoice = Invoice::allWorkspaces()->where('uuid', $uuid)->with(['workspace', 'contact'])->firstOrFail();
        $context->set($invoice->workspace);
        $driver = $this->gateways->find($gateway);

        if (! $driver || ! $context->hasModule('invoicing') || ! $this->gateways->isEnabled($invoice->workspace, $driver)) {
            abort(404);
        }
        if (! $invoice->isOpen() || $invoice->balance <= 0) {
            return $this->backToInvoice($invoice, 'info', 'This invoice has nothing left to pay.');
        }

        $attempt = $this->gateways->begin($invoice, $driver);
        try {
            $url = $driver->start($attempt, $invoice, $this->gateways->credentials($invoice->workspace, $driver));
        } catch (GatewayException $e) {
            $attempt->markFailed('failed', $e->getMessage());
            Log::warning('Online payment could not start', ['attempt' => $attempt->uuid, 'gateway' => $gateway, 'error' => $e->getMessage()]);

            return $this->backToInvoice($invoice, 'danger', 'Online payment is not available right now. Please try again later or use another way to pay.');
        }

        return redirect()->away($url);
    }

    /** The customer is back from the gateway: check with it, then show the invoice with the outcome. */
    public function complete(Request $request, string $attempt, WorkspaceContext $context): RedirectResponse
    {
        $attempt = OnlinePayment::allWorkspaces()->where('uuid', $attempt)->firstOrFail();
        $invoice = $attempt->invoice()->with('workspace')->firstOrFail();
        $context->set($invoice->workspace);

        $status = $this->gateways->settle($attempt);
        if ($status === 'pending' && $request->boolean('cancelled')) {
            $attempt->markFailed('cancelled', 'The customer left the checkout page.');
            $status = 'cancelled';
        }

        return match ($status) {
            'paid' => $this->backToInvoice($invoice, 'success', 'Thank you. Your payment of '.$attempt->money().' has been received.'),
            'pending' => $this->backToInvoice($invoice, 'info', 'Your payment is being confirmed by '.ucfirst($attempt->gateway).'. This page will show it as paid once it is.'),
            'cancelled' => $this->backToInvoice($invoice, 'info', 'Payment cancelled. Nothing was charged.'),
            default => $this->backToInvoice($invoice, 'danger', 'The payment did not go through. '.($attempt->failure_reason ?? '').' You can try again.'),
        };
    }

    /** Server-to-server notification from a gateway. Always answers quickly; the gateway retries on errors. */
    public function webhook(Request $request, string $gateway, Workspace $workspace, WorkspaceContext $context): Response
    {
        $driver = $this->gateways->find($gateway) ?? abort(404);
        $context->set($workspace);

        try {
            $notice = $driver->notification($request, $this->gateways->credentials($workspace, $driver));
        } catch (GatewayException $e) {
            Log::warning('Rejected payment notification', ['gateway' => $gateway, 'workspace' => $workspace->id, 'error' => $e->getMessage()]);

            return response('Invalid notification.', 400);
        }

        $attempt = $notice ? OnlinePayment::query()->where('uuid', $notice['reference'])->where('gateway', $gateway)->first() : null;
        if ($attempt) {
            $this->gateways->settle($attempt, $notice['result']);
        }

        return response('OK');
    }

    protected function backToInvoice(Invoice $invoice, string $type, string $message): RedirectResponse
    {
        return redirect()->route('invoices.public', $invoice->uuid)->with('flash', ['type' => $type, 'message' => $message]);
    }
}
