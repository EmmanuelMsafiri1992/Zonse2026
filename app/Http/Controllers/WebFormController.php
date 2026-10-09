<?php

namespace App\Http\Controllers;

use App\Http\Requests\WebFormRequest;
use App\Models\WebForm;
use App\Support\Audit;
use App\Support\WebForms;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Building the workspace's public forms, sharing them and reading what visitors sent. */
class WebFormController extends Controller
{
    public function __construct(protected WorkspaceContext $context) {}

    public function index(): View
    {
        return view('forms.index', [
            'forms' => WebForm::query()->latest('id')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $targets = WebForms::targets($this->context->getOrFail());

        return view('forms.create', [
            'targets' => $targets,
            'target' => collect($targets)->contains(fn (array $group) => isset($group[$request->query('target')])) ? $request->query('target') : null,
        ]);
    }

    public function store(WebFormRequest $request): RedirectResponse
    {
        $form = WebForm::create($request->form());
        Audit::log('settings', 'form-created', 'Created the form "'.$form->name.'"', $form);

        return redirect()->route('forms.edit', $form)->with('flash', ['type' => 'success', 'message' => 'Form created. Add, order and label the questions, then save.']);
    }

    public function show(WebForm $webForm): View
    {
        return view('forms.show', [
            'form' => $webForm,
            'submissions' => $webForm->submissions()->with('subject')->latest('id')->paginate(25),
            'available' => WebForms::targets($this->context->getOrFail()) !== [] && WebForms::allowsTarget($this->context->getOrFail(), $webForm->target),
        ]);
    }

    public function edit(WebForm $webForm): View
    {
        return view('forms.edit', [
            'form' => $webForm,
            'available' => collect(WebForms::available($webForm->target))
                ->map(fn (array $field, string $key) => ['key' => $key, 'label' => $field['label'], 'type' => $field['type'], 'must' => $field['must']])
                ->values()->all(),
        ]);
    }

    public function update(WebFormRequest $request, WebForm $webForm): RedirectResponse
    {
        $webForm->update($request->form());
        Audit::log('settings', 'form-updated', 'Updated the form "'.$webForm->name.'"', $webForm);

        return redirect()->route('forms.show', $webForm)->with('flash', ['type' => 'success', 'message' => 'Form saved.']);
    }

    public function destroy(WebForm $webForm): RedirectResponse
    {
        $webForm->delete();
        Audit::log('settings', 'form-deleted', 'Deleted the form "'.$webForm->name.'"');

        return redirect()->route('forms.index')->with('flash', ['type' => 'success', 'message' => '"'.$webForm->name.'" deleted. Records it created are kept.']);
    }
}
