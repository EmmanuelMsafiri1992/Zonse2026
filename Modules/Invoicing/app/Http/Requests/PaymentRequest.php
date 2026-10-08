<?php

namespace Modules\Invoicing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Invoicing\Models\Payment;

class PaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** Reject amounts larger than what is still owed on the invoice. */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $invoice = $this->route('invoice');
                $amount = (float) $this->input('amount');

                if ($invoice && $amount > round((float) $invoice->balance, 2) + 0.005) {
                    $validator->errors()->add('amount', 'The amount cannot be more than the outstanding balance of '.$invoice->money($invoice->balance).'.');
                }
            },
        ];
    }
}
