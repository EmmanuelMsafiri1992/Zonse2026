<?php

namespace App\Http\Controllers;

use App\Models\FiscalDocument;
use App\Support\Hardware\QrCode;
use Illuminate\View\View;

/**
 * The page a fiscal QR code opens: anyone holding an invoice or receipt can confirm it was reported
 * and that the amounts match.
 */
class FiscalVerificationController extends Controller
{
    public function show(string $code): View
    {
        $document = FiscalDocument::query()->allWorkspaces()->with(['workspace', 'invoice'])
            ->where('verification_code', strtoupper($code))->firstOrFail();
        $creditNote = $document->type === 'invoice'
            ? FiscalDocument::query()->allWorkspaces()->where('invoice_id', $document->invoice_id)->where('type', 'credit_note')->first()
            : null;

        return view('fiscal.verify', [
            'document' => $document,
            'creditNote' => $creditNote,
            'qr' => QrCode::svg($document->qrData(), 140),
        ]);
    }
}
