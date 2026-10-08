<?php

namespace App\Http\Requests\Api;

use App\Support\CustomFields;
use Modules\Contacts\Http\Requests\ContactRequest;
use Modules\Contacts\Models\Contact;

class ContactApiRequest extends ContactRequest
{
    /** API updates may send only the fields that change; the rest are filled from the stored item before the module's rules run. */
    protected string $routeKey = 'contact';

    /** @return array<string, mixed> */
    protected function currentValues(Contact $contact): array
    {
        return [
            ...$contact->only(['type', 'kind', 'name', 'company_name', 'email', 'phone', 'mobile', 'tax_number', 'address', 'city', 'country_code', 'currency_code', 'branch_id', 'notes', 'is_active']),
            'tags' => implode(', ', $contact->tags ?? []),
        ];
    }

    protected function prepareForValidation(): void
    {
        $model = $this->route($this->routeKey);
        $merged = array_merge($model instanceof Contact ? $this->currentValues($model) : ['kind' => 'person'], $this->all());
        if (is_array($merged['tags'] ?? null)) {
            $merged['tags'] = implode(', ', $merged['tags']);
        }

        $this->replace(CustomFields::fromApi($merged, $model instanceof Contact ? $model : null));
    }
}
