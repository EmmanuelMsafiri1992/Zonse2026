<?php

namespace App\Http\Requests;

use App\Models\WebForm;
use App\Support\WebForms;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Starting a public form (name and what it creates) or saving it from the builder. */
class WebFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        if (! $this->route('webForm')) {
            $targets = collect(WebForms::targets(app(WorkspaceContext::class)->getOrFail()))->flatMap(fn (array $group) => array_keys($group))->all();

            return [
                'name' => ['required', 'string', 'max:120'],
                'target' => ['required', 'string', Rule::in($targets)],
            ];
        }

        return [
            'name' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:160'],
            'intro' => ['nullable', 'string', 'max:2000'],
            'success_message' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            'fields' => ['required', 'json'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['target.in' => 'Pick what the form should create.'];
    }

    /**
     * The attributes to save, with the builder's field list cleaned against what the target allows.
     *
     * @return array<string, mixed>
     */
    public function form(): array
    {
        /** @var WebForm|null $form */
        $form = $this->route('webForm');
        if (! $form) {
            $target = $this->string('target')->toString();

            return [
                'name' => $this->string('name')->trim()->toString(),
                'title' => $this->string('name')->trim()->toString(),
                'target' => $target,
                'fields' => WebForms::starterFields($target),
            ];
        }

        return [
            'name' => $this->string('name')->trim()->toString(),
            'title' => $this->string('title')->trim()->toString(),
            'intro' => $this->filled('intro') ? $this->string('intro')->trim()->toString() : null,
            'success_message' => $this->filled('success_message') ? $this->string('success_message')->trim()->toString() : null,
            'is_active' => $this->boolean('is_active'),
            'fields' => WebForms::normalise($form->target, json_decode((string) $this->input('fields'), true)),
        ];
    }
}
