<?php

namespace Modules\Invoicing\Http\Requests;

use App\Support\Lists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for invoices and quotes: header fields plus a list of lines.
 */
class DocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $workspaceId = $this->user()->current_workspace_id;
        $isQuote = $this->routeIs('quotes.*');

        return [
            'contact_id' => ['required', Rule::exists('contacts', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at')],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('workspace_id', $workspaceId)],
            'issue_date' => ['required', 'date'],
            'due_date' => [Rule::requiredIf(! $isQuote), 'nullable', 'date', 'after_or_equal:issue_date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'currency_code' => ['required', Rule::in(array_keys(Lists::CURRENCIES))],
            'reference' => ['nullable', 'string', 'max:120'],
            'discount_type' => ['nullable', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['nullable', Rule::exists('items', 'id')->where('workspace_id', $workspaceId)],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'contact_id' => 'customer', 'lines' => 'lines', 'lines.*.description' => 'line description',
            'lines.*.quantity' => 'quantity', 'lines.*.unit_price' => 'unit price', 'lines.*.tax_rate' => 'tax rate',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['lines.required' => 'Add at least one line.', 'lines.min' => 'Add at least one line.'];
    }

    /** Header attributes for the document model (everything except lines). */
    public function documentPayload(): array
    {
        $data = $this->validated();
        unset($data['lines']);
        $data['discount_value'] = ! empty($data['discount_type']) ? (float) ($data['discount_value'] ?? 0) : 0;

        return $data;
    }

    /** @return list<array<string, mixed>> */
    public function lines(): array
    {
        return array_values($this->validated()['lines']);
    }
}
