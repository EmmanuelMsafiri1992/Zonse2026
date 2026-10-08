<?php

namespace Modules\Invoicing\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders an invoice or quote as an A4 PDF in the workspace's document design.
 * The renderer never fetches remote files; the logo is embedded instead.
 */
class DocumentPdf
{
    public static function render(Model $document, string $kind): string
    {
        return Pdf::loadView('invoicing::pdf', [
            'document' => $document,
            'kind' => $kind,
            'design' => DocumentDesign::for($document->workspace),
            'pdf' => true,
        ])->setPaper('a4')->setOption(['default_font' => 'dejavu sans', 'is_remote_enabled' => false, 'is_font_subsetting_enabled' => true])->output();
    }

    public static function filename(Model $document, string $kind): string
    {
        return Str::slug(DocumentDesign::for($document->workspace)->title($kind).' '.$document->number).'.pdf';
    }

    /** Shown inline in the browser, with a sensible name if the customer saves it. */
    public static function response(Model $document, string $kind): Response
    {
        return response(self::render($document, $kind), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.self::filename($document, $kind).'"',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
