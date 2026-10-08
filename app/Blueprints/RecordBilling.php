<?php

namespace App\Blueprints;

use App\Models\Record;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Payment;

/**
 * Turns app records into Invoicing invoices and takes payments against them, so a clinic
 * visit, a school fee or a month's rent ends up in the same receivables as every other sale.
 */
class RecordBilling
{
    public function __construct(protected WorkspaceContext $context) {}

    /** Billing needs the Invoicing module switched on for the workspace. */
    public function available(): bool
    {
        return $this->context->hasModule('invoicing');
    }

    /**
     * Raise an invoice for a record.
     *
     * @param  list<array{description: string, quantity: float|int, unit_price: float|int|string, item_id?: int|null}>|null  $lines  defaults to the app's invoiceLines()
     * @param  string|null  $period  e.g. "2026-10" for recurring charges, so the same period is never billed twice
     *
     * @throws ValidationException when billing is off, the record has nothing to bill, or the period is already billed
     */
    public function invoice(Record $record, ?array $lines = null, ?string $period = null, bool $send = true, ?Carbon $issueDate = null, ?Carbon $dueDate = null): Invoice
    {
        if (! $this->available()) {
            throw ValidationException::withMessages(['billing' => 'Switch on the Invoicing app to bill from here.']);
        }
        if ($period !== null && $record->invoices()->where('period', $period)->exists()) {
            throw ValidationException::withMessages(['billing' => 'This '.strtolower($record->definition()->label).' has already been billed for '.$period.'.']);
        }

        if ($period === null && ($open = $record->openInvoice())) {
            throw ValidationException::withMessages(['billing' => 'Invoice '.$open->number.' is still open. Take payment against it, or cancel it, before invoicing again.']);
        }

        $lines = array_values(array_filter(
            $lines ?? $record->blueprintDefinition()->logic()->invoiceLines($record),
            fn (array $line) => (float) $line['unit_price'] * (float) ($line['quantity'] ?? 1) > 0,
        ));
        if ($lines === []) {
            throw ValidationException::withMessages(['billing' => 'There is nothing to bill yet. Add an amount first.']);
        }

        return DB::transaction(function () use ($record, $lines, $period, $send, $issueDate, $dueDate) {
            $issueDate ??= today();
            $invoice = Invoice::create(array_filter([
                'contact_id' => $this->payingContact($record)->id,
                'branch_id' => $record->branch_id,
                'reference' => $record->number,
                'record_id' => $record->id,
                'period' => $period,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
            ], fn ($value) => $value !== null));
            $invoice->syncLines($lines);

            if ($send) {
                $invoice->markSent();
            }

            return $invoice->refresh();
        });
    }

    /**
     * Take a payment against an invoice. The invoice's status, and through it the record's, follows automatically.
     *
     * @throws ValidationException when the amount is more than the balance
     */
    public function pay(Invoice $invoice, float $amount, string $method = 'cash', ?string $paidOn = null, ?string $reference = null): Payment
    {
        $amount = Money::round($amount);
        if ($amount <= 0 || $amount > Money::round($invoice->balance)) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount between 0.01 and the balance of '.$invoice->money($invoice->balance).'.']);
        }
        if ($invoice->status === 'draft') {
            $invoice->markSent();
        }

        return Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'method' => array_key_exists($method, Payment::METHODS) ? $method : 'other',
            'paid_on' => $paidOn ?: today()->toDateString(),
            'reference' => $reference,
        ]);
    }

    /**
     * Who pays for a record: its own contact, else the paying record's contact (a visit's patient),
     * else a new contact made from the paying record, which is then linked so it is reused next time.
     */
    public function payingContact(Record $record): Contact
    {
        $logic = $record->blueprintDefinition()->logic();
        if ($fixed = $logic->billingContact($record)) {
            return $fixed;
        }
        if ($record->contact) {
            return $record->contact;
        }

        $payer = $logic->payer($record);
        if ($payer->contact) {
            return $payer->contact;
        }

        $details = $logic->newContactDetails($payer);
        $contact = Contact::create([
            'type' => 'customer',
            'kind' => 'person',
            'name' => $details['name'],
            'phone' => $details['phone'],
            'currency_code' => $this->context->get()?->currency_code,
        ]);

        if ($payer->definition()->hasContact()) {
            $payer->forceFill(['contact_id' => $contact->id])->saveQuietly();
        }
        if ($payer->isNot($record) && $record->definition()->hasContact()) {
            $record->forceFill(['contact_id' => $contact->id])->saveQuietly();
        }

        return $contact;
    }
}
