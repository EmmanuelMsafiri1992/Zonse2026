<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomFieldRequest;
use App\Models\CustomField;
use App\Support\Audit;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The workspace's own extra fields on contacts, tasks, tickets and appointments. */
class CustomFieldController extends Controller
{
    public function __construct(protected WorkspaceContext $context) {}

    public function index(): View
    {
        $workspace = $this->context->getOrFail();

        return view('settings.custom-fields.index', [
            'entities' => CustomField::ENTITIES,
            'fields' => CustomField::query()->orderBy('position')->orderBy('id')->get()->groupBy('entity'),
            'enabled' => collect(CustomField::ENTITIES)->map(fn (array $entity) => $workspace->hasModule($entity['module'])),
        ]);
    }

    public function create(Request $request): View
    {
        $entity = $request->string('entity')->toString();

        return $this->form(new CustomField(['entity' => isset(CustomField::ENTITIES[$entity]) ? $entity : 'contact', 'type' => 'text']));
    }

    public function store(CustomFieldRequest $request): RedirectResponse
    {
        $data = $request->field();
        if (CustomField::query()->where('entity', $data['entity'])->count() >= CustomField::MAX_PER_ENTITY) {
            return back()->withInput()->withErrors(['label' => CustomField::ENTITIES[$data['entity']]['label'].' already have '.CustomField::MAX_PER_ENTITY.' extra fields.']);
        }

        $field = CustomField::create($data);
        Audit::log('settings', 'custom-field-created', 'Added the field "'.$field->label.'" to '.mb_strtolower($field->entityLabel()), $field);

        return redirect()->route('settings.custom-fields.index')->with('flash', ['type' => 'success', 'message' => '"'.$field->label.'" added to '.mb_strtolower($field->entityLabel()).'.']);
    }

    public function edit(CustomField $customField): View
    {
        return $this->form($customField);
    }

    public function update(CustomFieldRequest $request, CustomField $customField): RedirectResponse
    {
        $customField->update($request->field());
        Audit::log('settings', 'custom-field-updated', 'Updated the field "'.$customField->label.'" on '.mb_strtolower($customField->entityLabel()), $customField);

        return redirect()->route('settings.custom-fields.index')->with('flash', ['type' => 'success', 'message' => 'Field saved.']);
    }

    public function destroy(CustomField $customField): RedirectResponse
    {
        $customField->delete();
        Audit::log('settings', 'custom-field-deleted', 'Removed the field "'.$customField->label.'" from '.mb_strtolower($customField->entityLabel()));

        return redirect()->route('settings.custom-fields.index')->with('flash', ['type' => 'success', 'message' => '"'.$customField->label.'" removed. Values already saved on records are no longer shown.']);
    }

    /** Swap a field with its neighbour, to change the order it appears in forms. */
    public function move(Request $request, CustomField $customField): RedirectResponse
    {
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];
        $siblings = CustomField::query()->where('entity', $customField->entity)->orderBy('position')->orderBy('id')->get()->values();
        $index = $siblings->search(fn (CustomField $field) => $field->is($customField));
        $neighbour = $siblings->get($direction === 'up' ? $index - 1 : $index + 1);

        if ($neighbour) {
            $siblings->splice($index, 1);
            $siblings->splice($direction === 'up' ? $index - 1 : $index + 1, 0, [$customField]);
            $siblings->each(fn (CustomField $field, int $position) => $field->update(['position' => $position + 1]));
        }

        return back();
    }

    protected function form(CustomField $field): View
    {
        return view('settings.custom-fields.form', [
            'field' => $field,
            'entities' => collect(CustomField::ENTITIES)->map(fn (array $entity) => $entity['label'])->all(),
            'types' => CustomField::TYPES,
        ]);
    }
}
