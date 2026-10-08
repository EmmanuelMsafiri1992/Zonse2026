<?php

namespace Modules\Contacts\Http\Requests;

use App\Support\Lists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Contacts\Models\Contact;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(Contact::TYPES))],
            'kind' => ['required', Rule::in(array_keys(Contact::KINDS))],
            'name' => ['required', 'string', 'max:160'],
            'company_name' => ['nullable', 'string', 'max:160', Rule::requiredIf(fn () => $this->input('kind') === 'company')],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'mobile' => ['nullable', 'string', 'max:40'],
            'tax_number' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country_code' => ['nullable', Rule::in(array_keys(Lists::COUNTRIES))],
            'currency_code' => ['nullable', Rule::in(array_keys(Lists::CURRENCIES))],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('workspace_id', $this->user()->current_workspace_id)],
            'tags' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $data = $this->validated();
        $data['tags'] = collect(explode(',', (string) ($data['tags'] ?? '')))->map(fn ($t) => trim($t))->filter()->unique()->values()->all();
        $data['is_active'] = $this->boolean('is_active', true);

        return $data;
    }
}
