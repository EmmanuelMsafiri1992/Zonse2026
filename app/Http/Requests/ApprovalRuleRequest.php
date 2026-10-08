<?php

namespace App\Http\Requests;

use App\Models\ApprovalRule;
use App\Support\Lists;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** What needs a yes, from what amount, and who gives it. */
class ApprovalRuleRequest extends FormRequest
{
    /** Who may manage rules is decided by the settings route group. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'min_amount' => filled($this->input('min_amount')) ? $this->input('min_amount') : 0,
            'currency_code' => filled($this->input('currency_code')) ? $this->input('currency_code') : null,
            'approver_id' => $this->input('approver') === 'person' ? $this->input('approver_id') : null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['required', Rule::in(array_keys(ApprovalRule::SUBJECTS))],
            'min_amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'currency_code' => ['nullable', Rule::in(array_keys(Lists::CURRENCIES))],
            'approver' => ['required', Rule::in(array_keys(ApprovalRule::APPROVERS))],
            'approver_id' => [
                'nullable', 'required_if:approver,person', 'integer',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', app(WorkspaceContext::class)->id()),
            ],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['subject' => 'step', 'min_amount' => 'amount', 'approver_id' => 'person'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['approver_id.required_if' => 'Choose who approves.', 'approver_id.exists' => 'Choose someone on your team.'];
    }

    /** @return array{name: string, subject: string, min_amount: float, currency_code: ?string, approver: string, approver_id: ?int, is_active: bool} */
    public function rule(): array
    {
        $data = $this->validated();
        $data['min_amount'] = (float) $data['min_amount'];
        $data['approver_id'] = $data['approver_id'] ? (int) $data['approver_id'] : null;

        return $data;
    }
}
