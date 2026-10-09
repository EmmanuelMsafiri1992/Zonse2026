<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentTemplateRequest;
use App\Models\DocumentTemplate;
use App\Support\Audit;
use App\Support\DocumentTemplates;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Designing the workspace's letters, certificates and receipts. */
class DocumentTemplateController extends Controller
{
    public function __construct(protected WorkspaceContext $context) {}

    public function index(): View
    {
        return view('settings.document-templates.index', [
            'templates' => DocumentTemplate::query()->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $starters = DocumentTemplates::starters();

        return view('settings.document-templates.create', [
            'starters' => $starters,
            'subjects' => DocumentTemplates::subjects($this->context->getOrFail()),
            'starter' => isset($starters[$request->query('starter')]) ? $request->query('starter') : 'letter',
        ]);
    }

    public function store(DocumentTemplateRequest $request): RedirectResponse
    {
        $template = DocumentTemplate::create($request->template());
        Audit::log('settings', 'document-template-created', 'Created the document template "'.$template->name.'"', $template);

        return redirect()->route('settings.document-templates.edit', $template)
            ->with('flash', ['type' => 'success', 'message' => 'Template created. Change the wording and layout, then save.']);
    }

    public function edit(DocumentTemplate $documentTemplate): View
    {
        return view('settings.document-templates.edit', [
            'template' => $documentTemplate,
            'tags' => DocumentTemplates::tags($documentTemplate->subject),
            'available' => DocumentTemplates::allowsSubject($this->context->getOrFail(), $documentTemplate->subject),
        ]);
    }

    public function update(DocumentTemplateRequest $request, DocumentTemplate $documentTemplate): RedirectResponse
    {
        $documentTemplate->update($request->template());
        Audit::log('settings', 'document-template-updated', 'Updated the document template "'.$documentTemplate->name.'"', $documentTemplate);

        return redirect()->route('settings.document-templates.edit', $documentTemplate)->with('flash', ['type' => 'success', 'message' => 'Template saved.']);
    }

    public function destroy(DocumentTemplate $documentTemplate): RedirectResponse
    {
        $documentTemplate->delete();
        Audit::log('settings', 'document-template-deleted', 'Deleted the document template "'.$documentTemplate->name.'"');

        return redirect()->route('settings.document-templates.index')->with('flash', ['type' => 'success', 'message' => '"'.$documentTemplate->name.'" deleted.']);
    }

    /**
     * The page as it will print, with each tag shown by name. The designer posts its unsaved
     * changes here to refresh the preview as you type; anything invalid falls back to what is saved.
     */
    public function preview(Request $request, DocumentTemplate $documentTemplate): View
    {
        $draft = $documentTemplate->replicate();
        $draft->setRelation('workspace', $documentTemplate->workspace);

        if ($request->isMethod('post')) {
            $input = $request->only(array_keys(DocumentTemplateRequest::designRules()));
            $input += ['name' => $documentTemplate->name, 'is_active' => true];
            $validator = Validator::make($input, DocumentTemplateRequest::designRules());
            $valid = array_diff_key($input, $validator->errors()->messages());
            $draft->fill(array_intersect_key(DocumentTemplateRequest::design($valid + $documentTemplate->only(['kind', 'body', 'paper', 'orientation', 'font', 'align', 'border', 'color'])), $valid));
        }

        return view('documents.preview', DocumentTemplates::page($draft, null, $request->user()));
    }

    /** A PDF with each tag shown by name, to check the layout before printing real ones. */
    public function sample(Request $request, DocumentTemplate $documentTemplate): Response
    {
        return response(DocumentTemplates::render($documentTemplate, null, $request->user()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="sample-'.DocumentTemplates::filename($documentTemplate).'"',
        ]);
    }
}
