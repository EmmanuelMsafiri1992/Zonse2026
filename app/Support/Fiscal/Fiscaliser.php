<?php

namespace App\Support\Fiscal;

use App\Models\FiscalDocument;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Models\Invoice;
use Throwable;

/**
 * Reports invoices to the tax authority. An invoice is fiscalised the moment it leaves draft; a
 * fiscalised invoice that is cancelled gets a credit note. Each document is numbered and chained in
 * one locked step, then sent once the surrounding transaction commits. Documents the authority
 * could not be reached for stay "pending" and are sent again by `zonseo:fiscal-retry`.
 */
class Fiscaliser
{
    public function __construct(protected TestFiscalGateway $gateway) {}

    /** Called whenever an invoice is saved; does nothing unless the status change needs reporting. */
    public function invoiceSaved(Invoice $invoice): void
    {
        if (! $invoice->wasRecentlyCreated && ! $invoice->wasChanged('status')) {
            return;
        }
        $workspace = Workspace::query()->find($invoice->workspace_id);
        if (! $workspace || ! FiscalSettings::enabled($workspace)) {
            return;
        }

        $issued = $this->documentFor($invoice, 'invoice');
        if ($invoice->status === 'cancelled') {
            if ($issued && ! $this->documentFor($invoice, 'credit_note')) {
                $this->issue($invoice, 'credit_note', $issued);
            }
        } elseif ($invoice->status !== 'draft' && ! $issued) {
            $this->issue($invoice, 'invoice');
        }
    }

    public function documentFor(Invoice $invoice, string $type): ?FiscalDocument
    {
        return FiscalDocument::query()->forWorkspace($invoice->workspace_id)->where('invoice_id', $invoice->id)->where('type', $type)->first();
    }

    /** Number, chain and store the document, then send it after the current transaction commits. */
    public function issue(Invoice $invoice, string $type, ?FiscalDocument $original = null): FiscalDocument
    {
        $document = DB::transaction(function () use ($invoice, $type, $original) {
            $workspace = Workspace::query()->whereKey($invoice->workspace_id)->lockForUpdate()->firstOrFail();
            $settings = FiscalSettings::for($workspace);
            $previous = FiscalDocument::query()->forWorkspace($workspace)->orderByDesc('counter')->first();
            $counter = ($previous?->counter ?? 0) + 1;
            $fiscalNumber = strtoupper($settings['authority']).'-'.($settings['device_id'] ? strtoupper($settings['device_id']).'-' : '').str_pad((string) $counter, 8, '0', STR_PAD_LEFT);
            $payload = $this->payload($invoice, $workspace, $settings, $type, $counter, $fiscalNumber, $original);
            $hash = self::hashFor($payload, $previous?->hash);

            return FiscalDocument::query()->create([
                'workspace_id' => $workspace->id, 'invoice_id' => $invoice->id, 'type' => $type, 'authority' => $settings['authority'],
                'counter' => $counter, 'fiscal_number' => $fiscalNumber, 'verification_code' => self::verificationCode($hash),
                'status' => 'pending', 'payload' => $payload, 'previous_hash' => $previous?->hash, 'hash' => $hash,
            ]);
        });

        DB::afterCommit(fn () => $this->submit($document));

        return $document;
    }

    /** Send one document to the authority and record the answer. */
    public function submit(FiscalDocument $document): FiscalDocument
    {
        if ($document->status !== 'pending') {
            return $document;
        }
        $workspace = Workspace::query()->find($document->workspace_id);
        $settings = $workspace ? FiscalSettings::for($workspace) : FiscalSettings::DEFAULTS;

        try {
            $answer = $this->gateway->submit($document, $settings['simulate_outage']);
        } catch (Throwable $exception) {
            $document->forceFill(['attempts' => $document->attempts + 1, 'last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();

            return $document;
        }

        $document->forceFill([
            'attempts' => $document->attempts + 1,
            'status' => $answer['accepted'] ? 'signed' : 'rejected',
            'authority_reference' => $answer['reference'],
            'last_error' => $answer['message'],
            'signed_at' => $answer['accepted'] ? now() : null,
        ])->save();

        return $document;
    }

    /**
     * Send every document still waiting, oldest first, so the authority sees the counter in order.
     *
     * @return array{signed: int, waiting: int}
     */
    public function retryPending(?Workspace $workspace = null): array
    {
        $query = FiscalDocument::query()->allWorkspaces()->where('status', 'pending')->orderBy('workspace_id')->orderBy('counter');
        if ($workspace) {
            $query->where('workspace_id', $workspace->id);
        }

        $results = ['signed' => 0, 'waiting' => 0];
        foreach ($query->get() as $document) {
            $this->submit($document)->isSigned() ? $results['signed']++ : $results['waiting']++;
        }

        return $results;
    }

    /**
     * Recompute every hash and link in the workspace's chain.
     *
     * @return Collection<int, string> one line per problem; empty when the chain is intact
     */
    public function verifyChain(Workspace $workspace): Collection
    {
        $problems = collect();
        $previous = null;
        foreach (FiscalDocument::query()->forWorkspace($workspace)->orderBy('counter')->cursor() as $document) {
            $expectedCounter = ($previous?->counter ?? 0) + 1;
            if ($document->counter !== $expectedCounter) {
                $problems->push('Document '.$expectedCounter.' is missing before '.$document->fiscal_number.'.');
            }
            if ($document->previous_hash !== $previous?->hash) {
                $problems->push($document->fiscal_number.' does not link to the document before it.');
            }
            if (! hash_equals($document->hash, self::hashFor($document->payload, $document->previous_hash))) {
                $problems->push($document->fiscal_number.' has been changed since it was issued.');
            }
            $previous = $document;
        }

        return $problems;
    }

    /** @param  array<string, mixed>  $payload */
    public static function hashFor(array $payload, ?string $previousHash): string
    {
        return hash('sha256', ($previousHash ?? '').'|'.json_encode(self::canonical($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function verificationCode(string $hash): string
    {
        return implode('-', str_split(strtoupper(substr($hash, 0, 16)), 4));
    }

    /**
     * Keys sorted at every level, so the hash does not depend on how the database stored the JSON.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    protected static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? self::canonical($item) : $item, $value);
    }

    /**
     * Everything the authority is told about the document. Amounts are strings so they survive the
     * JSON round trip exactly.
     *
     * @param  array{enabled: bool, authority: ?string, taxpayer_id: ?string, device_id: ?string, mode: string, simulate_outage: bool}  $settings
     * @return array<string, mixed>
     */
    protected function payload(Invoice $invoice, Workspace $workspace, array $settings, string $type, int $counter, string $fiscalNumber, ?FiscalDocument $original): array
    {
        $invoice->load(['lines', 'contact']);
        $amount = fn ($value) => number_format((float) $value, 2, '.', '');

        return [
            'type' => $type,
            'authority' => $settings['authority'],
            'mode' => $settings['mode'],
            'counter' => $counter,
            'fiscal_number' => $fiscalNumber,
            'original_fiscal_number' => $original?->fiscal_number,
            'issued_at' => now()->toIso8601String(),
            'invoice_number' => $invoice->number,
            'invoice_date' => $invoice->issue_date?->toDateString(),
            'currency' => $invoice->currency_code,
            'seller' => ['name' => $workspace->name, 'tax_id' => $settings['taxpayer_id'], 'device_id' => $settings['device_id']],
            'buyer' => ['name' => $invoice->contact?->displayName(), 'tax_id' => $invoice->contact?->tax_number],
            'lines' => $invoice->lines->map(fn ($line) => [
                'description' => $line->description,
                'quantity' => rtrim(rtrim(number_format((float) $line->quantity, 3, '.', ''), '0'), '.'),
                'unit_price' => $amount($line->unit_price),
                'tax_rate' => rtrim(rtrim(number_format((float) $line->tax_rate, 3, '.', ''), '0'), '.'),
                'tax_amount' => $amount($line->tax_amount),
                'line_total' => $amount($line->line_total),
            ])->values()->all(),
            'subtotal' => $amount($invoice->subtotal),
            'discount' => $amount($invoice->discount_amount),
            'tax_total' => $amount($invoice->tax_total),
            'total' => $amount($invoice->total),
        ];
    }
}
