<?php

namespace App\Http\Controllers;

use App\Models\DocumentTemplate;
use App\Support\DocumentTemplates;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Printing a document template for a contact, payment or app record (or on its own) as a PDF. */
class DocumentController extends Controller
{
    public function __construct(protected WorkspaceContext $context) {}

    public function show(Request $request, DocumentTemplate $documentTemplate, ?int $subject = null): Response
    {
        abort_unless($documentTemplate->is_active && DocumentTemplates::allowsSubject($this->context->getOrFail(), $documentTemplate->subject), 404);

        $model = DocumentTemplates::subjectModel($documentTemplate, $subject);

        return DocumentTemplates::response($documentTemplate, $model, $request->user());
    }
}
