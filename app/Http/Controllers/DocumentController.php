<?php

namespace App\Http\Controllers;

use App\Models\DocumentTemplate;
use App\Models\Record;
use App\Support\DocumentTemplates;
use App\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Payment;
use Symfony\Component\HttpFoundation\Response;

/** Printing a document template for a contact, payment or app record (or on its own) as a PDF. */
class DocumentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected WorkspaceContext $context) {}

    public function show(Request $request, DocumentTemplate $documentTemplate, ?int $subject = null): Response
    {
        abort_unless($documentTemplate->is_active && DocumentTemplates::allowsSubject($this->context->getOrFail(), $documentTemplate->subject), 404);

        $model = $this->subject($documentTemplate, $subject);

        return DocumentTemplates::response($documentTemplate, $model, $request->user());
    }

    /** The contact, payment or record to fill the template from, checked against what this person may see. */
    protected function subject(DocumentTemplate $template, ?int $id): ?Model
    {
        if ($template->subject === 'none') {
            abort_if($id !== null, 404);

            return null;
        }
        abort_if($id === null, 404);

        if ($template->subject === 'contact') {
            $model = Contact::query()->findOrFail($id);
            $this->authorize('view', $model);
        } elseif ($template->subject === 'payment') {
            $model = Payment::query()->with(['invoice', 'contact'])->findOrFail($id);
            $this->authorize('viewAny', Payment::class);
        } else {
            [$blueprint, $entity] = explode('.', $template->subject, 2);
            $model = Record::query()->ofEntity($blueprint, $entity)->with('contact')->findOrFail($id);
            $this->authorize('view', $model);
        }

        return $model;
    }
}
