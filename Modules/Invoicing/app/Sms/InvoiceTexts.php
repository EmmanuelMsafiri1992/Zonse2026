<?php

namespace Modules\Invoicing\Sms;

use App\Models\SmsMessage;
use App\Models\Workspace;
use App\Sms\SmsService;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Payment;

/** The wording of invoice-related text messages, and sending them to the invoice's customer. */
class InvoiceTexts
{
    public function __construct(protected SmsService $sms) {}

    public function invoice(Invoice $invoice): string
    {
        return SmsService::prefix($this->workspaceOf($invoice)).'Invoice '.$invoice->number.' for '.$invoice->money($invoice->balance > 0 ? $invoice->balance : $invoice->total)
            .' is due '.$invoice->due_date->format('j M Y').'. View and pay: '.$invoice->publicUrl();
    }

    public function overdue(Invoice $invoice): string
    {
        return SmsService::prefix($this->workspaceOf($invoice)).'Reminder: invoice '.$invoice->number.' ('.$invoice->money($invoice->balance).' outstanding) was due '
            .$invoice->due_date->format('j M Y').'. View and pay: '.$invoice->publicUrl();
    }

    public function receipt(Payment $payment): string
    {
        $invoice = $payment->invoice;
        $text = SmsService::prefix($this->workspaceOf($invoice)).'Thank you. We received '.$payment->money().' for invoice '.$invoice->number.'.';

        return $text.($invoice->balance > 0 ? ' Balance: '.$invoice->money($invoice->balance).'.' : ' Paid in full.');
    }

    /**
     * Text a message about an invoice to its customer.
     *
     * @param  'invoice'|'overdue'|'receipt'  $purpose
     */
    public function sendFor(Invoice $invoice, string $purpose, string $body): ?SmsMessage
    {
        $contact = $invoice->contact_id ? Contact::allWorkspaces()->find($invoice->contact_id) : null;

        return $contact ? $this->sms->sendToContact($contact, $body, ['purpose' => $purpose, 'subject' => $invoice]) : null;
    }

    /** Called whenever a payment is recorded: texts a receipt when the workspace has switched receipts on. */
    public function paymentRecorded(Payment $payment): void
    {
        $invoice = $payment->invoice()->withoutGlobalScope('workspace')->first();
        $workspace = $invoice ? $this->workspaceOf($invoice) : null;
        if (! $invoice || ! $workspace || ! $this->sms->wants($workspace, 'receipts')) {
            return;
        }
        $payment->setRelation('invoice', $invoice->refresh());

        $this->sendFor($invoice, 'receipt', $this->receipt($payment));
    }

    protected function workspaceOf(Invoice $invoice): Workspace
    {
        return $invoice->relationLoaded('workspace') ? $invoice->workspace : $invoice->workspace()->firstOrFail();
    }
}
