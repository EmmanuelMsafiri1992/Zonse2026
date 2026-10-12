<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\RecordRequest;
use App\Support\CustomFields;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An app record sent through the API. The app's own fields arrive as "fields" (the form calls them "data"),
 * and updates may send only what changes; the rest is filled from the stored record before the app's rules run.
 */
class RecordApiRequest extends RecordRequest
{
    protected function prepareForValidation(): void
    {
        $existing = $this->existing();
        $input = $this->except(['data', 'fields']);
        $current = $existing ? [
            ...$existing->only(['title', 'status', 'branch_id', 'contact_id', 'assignee_id', 'amount']),
            'occurs_on' => $existing->occurs_on?->toDateString(),
            'due_on' => $existing->due_on?->toDateString(),
        ] : [];
        $fields = $this->input('fields');
        $data = array_merge($existing ? (array) $existing->data : [], is_array($fields) ? $fields : []);

        $this->replace(CustomFields::fromApi([...$current, ...$input, 'data' => $data], $existing));
        parent::prepareForValidation();
    }

    /** Report problems with the app's fields under "fields.", the name callers send them by. */
    protected function failedValidation(Validator $validator): void
    {
        $errors = [];
        foreach ($validator->errors()->messages() as $key => $messages) {
            $errors[Str::startsWith($key, 'data.') ? 'fields.'.Str::after($key, 'data.') : $key] = $messages;
        }

        throw ValidationException::withMessages($errors);
    }
}
