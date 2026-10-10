<?php

namespace App\Support\Import;

use App\Models\Record;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;

/** Customers, suppliers and leads, including customer and supplier lists exported from QuickBooks, Sage and Xero. */
class ContactTarget extends ImportTarget
{
    /** Words other systems use for a contact type. */
    protected const TYPE_WORDS = [
        'customer' => ['customer', 'customers', 'client', 'clients', 'debtor', 'buyer'],
        'supplier' => ['supplier', 'suppliers', 'vendor', 'vendors', 'creditor'],
        'lead' => ['lead', 'leads', 'prospect', 'prospects'],
        'other' => ['other', 'contact'],
    ];

    public function key(): string
    {
        return 'contacts';
    }

    public function label(): string
    {
        return 'Contacts';
    }

    public function icon(): string
    {
        return 'users';
    }

    public function listUrl(): ?string
    {
        return route('contacts.index');
    }

    public function modelClass(): string
    {
        return Contact::class;
    }

    public function columns(): array
    {
        return [
            'name' => ['label' => 'Name', 'required' => true, 'example' => 'Tendai Moyo', 'hint' => 'The person, or the company when there is no contact person.',
                'aliases' => ['contactname', 'fullname', 'displayname', 'customer', 'customername', 'customerfullname', 'vendor', 'vendorname', 'supplier', 'suppliername', 'contact', 'contactperson']],
            'company_name' => ['label' => 'Company', 'example' => 'Moyo Hardware', 'aliases' => ['companyname', 'company', 'organisation', 'organization', 'business', 'businessname', 'accountname']],
            'type' => ['label' => 'Type', 'example' => 'Customer', 'hint' => 'Customer, supplier, lead or other.', 'aliases' => ['contacttype', 'category', 'relationship', 'group']],
            'email' => ['label' => 'Email', 'example' => 'tendai@example.com', 'aliases' => ['emailaddress', 'mainemail', 'e-mail', 'email1']],
            'phone' => ['label' => 'Phone', 'example' => '+263 242 700 100', 'aliases' => ['telephone', 'phonenumber', 'tel', 'workphone', 'telephone1', 'businessphone']],
            'mobile' => ['label' => 'Mobile', 'example' => '+263 77 123 4567', 'aliases' => ['mobilenumber', 'mobilephone', 'cell', 'cellphone', 'cellnumber']],
            'tax_number' => ['label' => 'Tax number', 'example' => '2000123456', 'aliases' => ['taxnumber', 'vatnumber', 'vatregistrationnumber', 'vatregistration', 'taxregistrationnumber', 'taxid', 'tin', 'taxno', 'vatno', 'krapin', 'bpnumber']],
            'address' => ['label' => 'Address', 'example' => '12 Samora Machel Ave', 'aliases' => ['street', 'street1', 'billingaddress', 'billingaddressline1', 'billingstreet', 'addressline1', 'poaddressline1', 'streetaddress']],
            'city' => ['label' => 'City', 'example' => 'Harare', 'aliases' => ['town', 'towncity', 'billingcity', 'billingaddresscity', 'pocity']],
            'country' => ['label' => 'Country', 'example' => 'Zimbabwe', 'hint' => 'A name or a two-letter code.', 'aliases' => ['countrycode', 'billingcountry', 'billingaddresscountry', 'pocountry']],
            'currency' => ['label' => 'Currency', 'example' => 'USD', 'aliases' => ['currencycode']],
            'tags' => ['label' => 'Tags', 'example' => 'wholesale, harare', 'aliases' => ['tag', 'groups', 'labels']],
            'notes' => ['label' => 'Notes', 'example' => '', 'aliases' => ['note', 'comments', 'memo', 'remarks']],
            'active' => ['label' => 'Active', 'example' => 'yes', 'aliases' => ['isactive', 'status', 'enabled']],
        ];
    }

    public function extraOptions(array $headers): array
    {
        $keys = array_map(fn (string $header) => Values::key($header), $headers);
        $looksLikeSuppliers = (bool) array_intersect($keys, ['vendor', 'vendorname', 'supplier', 'suppliername']);

        return ['default_type' => ['label' => 'When a row has no type, add it as', 'options' => Contact::TYPES, 'default' => $looksLikeSuppliers ? 'supplier' : 'customer']];
    }

    public function prepare(array $raw, array $options): array
    {
        $errors = [];
        $warnings = [];
        $text = fn (string $key) => array_key_exists($key, $raw) ? (Values::text($raw[$key]) ?: null) : null;

        $name = $text('name');
        $company = $text('company_name');
        if ($name === null && $company === null) {
            $errors[] = 'Needs a name or a company name.';
        }

        $type = null;
        if (($typeText = $text('type')) !== null) {
            $type = collect(self::TYPE_WORDS)->search(fn (array $words) => in_array(Values::key($typeText), $words, true)) ?: null;
            if (! $type) {
                $warnings[] = 'Type "'.$typeText.'" is not known, so it is added as '.strtolower(Contact::TYPES[$options['default_type'] ?? 'customer'] ?? 'customer').'.';
            }
        }

        $country = null;
        if (($countryText = $text('country')) !== null && ! ($country = Values::country($countryText))) {
            $warnings[] = 'Country "'.$countryText.'" is not recognised, so it is left blank.';
        }
        $currency = null;
        if (($currencyText = $text('currency')) !== null && ! ($currency = Values::currency($currencyText))) {
            $warnings[] = 'Currency "'.$currencyText.'" is not one Zonseob supports, so it is left blank.';
        }
        $active = null;
        if (($activeText = $text('active')) !== null && ($active = Values::boolean($activeText)) === null) {
            $warnings[] = 'Active "'.$activeText.'" is not yes or no, so the contact stays active.';
        }

        $values = [
            'name' => $name ?? $company,
            'company_name' => $company,
            'kind' => array_key_exists('company_name', $raw) ? ($company ? 'company' : 'person') : null,
            'type' => $type,
            'email' => ($email = $text('email')) ? mb_strtolower($email) : null,
            'phone' => $text('phone'),
            'mobile' => $text('mobile'),
            'tax_number' => $text('tax_number'),
            'address' => $text('address'),
            'city' => $text('city'),
            'country_code' => $country,
            'currency_code' => $currency,
            'tags' => ($tags = $text('tags')) ? collect(preg_split('/[,;]/', $tags))->map(fn ($tag) => trim($tag))->filter()->unique()->values()->all() : null,
            'notes' => $text('notes'),
            'is_active' => $active,
        ];

        $errors = [...$errors, ...$this->validate($values, [
            'name' => ['nullable', 'string', 'max:160'], 'company_name' => ['nullable', 'string', 'max:160'], 'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'], 'mobile' => ['nullable', 'string', 'max:40'], 'tax_number' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:255'], 'city' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:5000'],
        ])];

        return ['values' => $values, 'errors' => $errors, 'warnings' => $warnings];
    }

    public function matchKey(array $values): ?string
    {
        return $values['email'] ? 'email:'.$values['email'] : 'name:'.Values::key((string) $values['name']).'|'.Values::key((string) $values['company_name']);
    }

    public function findExisting(array $values): ?Model
    {
        if ($values['email']) {
            return Contact::query()->whereRaw('lower(email) = ?', [$values['email']])->first();
        }

        return Contact::query()->whereRaw('lower(name) = ?', [mb_strtolower((string) $values['name'])])
            ->when($values['company_name'], fn ($query, $company) => $query->whereRaw('lower(company_name) = ?', [mb_strtolower($company)]))
            ->first();
    }

    public function create(array $values, array $options): Model
    {
        return Contact::create(array_merge([
            'type' => $options['default_type'] ?? 'customer', 'kind' => 'person', 'is_active' => true, 'tags' => [],
        ], $this->given($values)));
    }

    public function update(Model $model, array $values): void
    {
        $model->update($this->given($values));
    }

    public function describe(Model $model): string
    {
        return Str::limit($model->displayName(), 60);
    }

    public function inUse(Model $model): bool
    {
        return Invoice::query()->where('contact_id', $model->getKey())->exists()
            || Record::query()->where('contact_id', $model->getKey())->exists();
    }
}
