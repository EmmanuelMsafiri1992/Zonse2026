<?php

namespace App\Support;

use App\Blueprints\BlueprintRegistry;
use App\Blueprints\Entity;
use App\Models\CustomField;
use App\Models\FormSubmission;
use App\Models\Record;
use App\Models\WebForm;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contacts\Models\Contact;
use Modules\Helpdesk\Models\Ticket;

/**
 * Public forms built by the workspace: which kinds of record a form can create, which fields it
 * may ask for, the rules a visitor's answers must pass and turning a submission into the record.
 *
 * A placeable field is described as array{label: string, type: string, options: array<string, string>, must: bool, help: ?string, rules: list<mixed>}.
 * Keys are "name", "subject" and so on for the record's own columns, "data.x" for an app's fields,
 * "contact.x" for the person behind an app record and "custom.x" for the workspace's custom fields.
 */
class WebForms
{
    /** Field types the public form knows how to show. */
    public const TYPES = ['text', 'textarea', 'email', 'phone', 'number', 'money', 'date', 'datetime', 'time', 'select', 'checkbox', 'url'];

    /**
     * What a form can create in this workspace, grouped for a select.
     *
     * @return array<string, array<string, string>>
     */
    public static function targets(Workspace $workspace): array
    {
        $records = array_filter([
            'contact' => $workspace->hasModule('contacts') ? 'Contacts (sign-ups, enquiries)' : null,
            'ticket' => $workspace->hasModule('helpdesk') ? 'Helpdesk tickets (requests, complaints)' : null,
        ]);
        $groups = $records === [] ? [] : ['Records' => $records];

        foreach (collect(CustomField::entityChoices($workspace))->except('Records') as $app => $entities) {
            $entities = array_filter($entities, fn (string $key) => self::fillable(self::entity($key)), ARRAY_FILTER_USE_KEY);
            if ($entities !== []) {
                $groups[$app] = $entities;
            }
        }

        return $groups;
    }

    public static function allowsTarget(Workspace $workspace, string $target): bool
    {
        return collect(self::targets($workspace))->contains(fn (array $group) => isset($group[$target]));
    }

    public static function targetLabel(string $target): string
    {
        return match ($target) {
            'contact' => 'Contacts',
            'ticket' => 'Helpdesk tickets',
            default => CustomField::appEntityLabel($target) ?? $target,
        };
    }

    /**
     * Every field a form for this target may ask for, in a sensible order.
     *
     * @return array<string, array{label: string, type: string, options: array<string, string>, must: bool, help: ?string, rules: list<mixed>}>
     */
    public static function available(string $target): array
    {
        $fields = match ($target) {
            'contact' => [
                'name' => self::field('Full name', 'text', must: true),
                'email' => self::field('Email address', 'email'),
                'phone' => self::field('Phone number', 'phone'),
                'company_name' => self::field('Company', 'text'),
                'notes' => self::field('Message', 'textarea'),
            ],
            'ticket' => [
                'requester_name' => self::field('Your name', 'text'),
                'requester_email' => self::field('Email address', 'email', must: true),
                'subject' => self::field('Subject', 'text', must: true),
                'body' => self::field('How can we help?', 'textarea', must: true),
            ],
            default => self::appFields($target),
        };

        foreach (CustomFields::for($target) as $custom) {
            $fields['custom.'.$custom->key] = self::field($custom->label, $custom->type, $custom->type === 'select' ? array_combine($custom->options ?? [], $custom->options ?? []) : [], false, $custom->help, array_slice($custom->rules(), 1));
        }

        return $fields;
    }

    /**
     * The fields a new form starts with: everything the record cannot be saved without, plus
     * the obvious contact details.
     *
     * @return list<array{key: string, label: string, required: bool, help: ?string}>
     */
    public static function starterFields(string $target): array
    {
        $starter = [];
        foreach (self::available($target) as $key => $field) {
            if ($field['must'] || in_array($key, ['email', 'phone', 'notes', 'requester_name', 'contact.name', 'contact.email'], true)) {
                $starter[] = ['key' => $key, 'label' => $field['label'], 'required' => $field['must'] || in_array($key, ['contact.name', 'contact.email'], true), 'help' => null];
            }
        }

        return $starter;
    }

    /**
     * Clean the field list sent by the builder: known keys only, once each, with the must-have
     * fields always present and required.
     *
     * @return list<array{key: string, label: string, required: bool, help: ?string}>
     */
    public static function normalise(string $target, mixed $fields): array
    {
        $available = self::available($target);
        $clean = [];
        foreach (is_array($fields) ? $fields : [] as $field) {
            $key = is_array($field) ? (string) ($field['key'] ?? '') : '';
            if (! isset($available[$key]) || isset($clean[$key])) {
                continue;
            }
            $label = trim((string) ($field['label'] ?? ''));
            $help = trim((string) ($field['help'] ?? ''));
            $clean[$key] = [
                'key' => $key,
                'label' => Str::limit($label !== '' ? $label : $available[$key]['label'], 120, ''),
                'required' => $available[$key]['must'] || filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'help' => $help !== '' ? Str::limit($help, 250, '') : null,
            ];
        }
        foreach ($available as $key => $definition) {
            if ($definition['must'] && ! isset($clean[$key])) {
                $clean[$key] = ['key' => $key, 'label' => $definition['label'], 'required' => true, 'help' => null];
            }
        }

        return array_values($clean);
    }

    /** The name a field's answer is posted under (dots would read as nesting). */
    public static function inputName(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /**
     * The form's fields joined to their definitions; fields that no longer exist are skipped.
     *
     * @return list<array{key: string, input: string, label: string, required: bool, help: ?string, type: string, options: array<string, string>, rules: list<mixed>}>
     */
    public static function placed(WebForm $form): array
    {
        $available = self::available($form->target);
        $placed = [];
        foreach ($form->fields ?? [] as $field) {
            if (isset($available[$field['key']])) {
                $definition = $available[$field['key']];
                $placed[] = [
                    'key' => $field['key'],
                    'input' => self::inputName($field['key']),
                    'label' => $field['label'],
                    'required' => (bool) $field['required'],
                    'help' => $field['help'] ?? $definition['help'],
                    'type' => $definition['type'],
                    'options' => $definition['options'],
                    'rules' => $definition['rules'],
                ];
            }
        }

        return $placed;
    }

    /**
     * Validation rules and attribute names for a visitor's answers.
     *
     * @return array{0: array<string, list<mixed>>, 1: array<string, string>}
     */
    public static function rules(WebForm $form): array
    {
        $rules = [];
        $attributes = [];
        foreach (self::placed($form) as $field) {
            $presence = $field['required'] && $field['type'] !== 'checkbox' ? 'required' : 'nullable';
            $rules[$field['input']] = [$presence, ...$field['rules']];
            $attributes[$field['input']] = $field['label'];
        }

        return [$rules, $attributes];
    }

    /**
     * Turn validated answers into the record the form creates, and log the submission.
     *
     * @param  array<string, mixed>  $answers  keyed by input name
     */
    public static function submit(WebForm $form, array $answers, ?string $ip = null): FormSubmission
    {
        $values = [];
        $readable = [];
        foreach (self::placed($form) as $field) {
            $value = self::clean($field['type'], $answers[$field['input']] ?? null);
            $values[$field['key']] = $value;
            $readable[] = ['label' => $field['label'], 'value' => self::display($field, $value)];
        }

        return DB::transaction(function () use ($form, $values, $readable, $ip) {
            $subject = match ($form->target) {
                'contact' => self::saveContact($form->workspace, $values),
                'ticket' => self::saveTicket($form, $values),
                default => self::saveRecord($form, $values),
            };

            $submission = FormSubmission::create([
                'workspace_id' => $form->workspace_id,
                'web_form_id' => $form->id,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'data' => $readable,
                'ip_hash' => $ip ? hash('sha256', $ip.config('app.key')) : null,
            ]);
            $form->forceFill(['submissions_count' => $form->submissions_count + 1, 'last_submitted_at' => now()])->save();

            return $submission;
        });
    }

    // ----- Saving -----------------------------------------------------------

    /** @param  array<string, mixed>  $values */
    protected static function saveContact(Workspace $workspace, array $values): Contact
    {
        $custom = self::customValues($values);
        $email = filled($values['email'] ?? null) ? mb_strtolower((string) $values['email']) : null;
        $contact = $email ? Contact::forWorkspace($workspace)->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first() : null;

        if ($contact) {
            foreach (['phone', 'company_name'] as $column) {
                if (blank($contact->{$column}) && filled($values[$column] ?? null)) {
                    $contact->{$column} = $values[$column];
                }
            }
            if (filled($values['notes'] ?? null)) {
                $contact->notes = trim(($contact->notes ? $contact->notes."\n\n" : '').$values['notes']);
            }
            $contact->custom_fields = array_merge($contact->custom_fields ?? [], array_filter($custom, fn (mixed $value) => $value !== null));
            $contact->save();

            return $contact;
        }

        return Contact::create([
            'workspace_id' => $workspace->id,
            'type' => 'customer',
            'kind' => filled($values['company_name'] ?? null) ? 'company' : 'person',
            'name' => $values['name'],
            'company_name' => $values['company_name'] ?? null,
            'email' => $email,
            'phone' => $values['phone'] ?? null,
            'notes' => $values['notes'] ?? null,
            'currency_code' => $workspace->currency_code,
            'tags' => ['online'],
            'is_active' => true,
            'custom_fields' => $custom,
        ]);
    }

    /** @param  array<string, mixed>  $values */
    protected static function saveTicket(WebForm $form, array $values): Ticket
    {
        $email = mb_strtolower((string) $values['requester_email']);
        $name = $values['requester_name'] ?? null;
        $contact = $form->workspace->hasModule('contacts') ? app(PublicPage::class)->contactFor($form->workspace, ['name' => $name ?: Str::before($email, '@'), 'email' => $email]) : null;

        return Ticket::create([
            'workspace_id' => $form->workspace_id,
            'contact_id' => $contact?->id,
            'requester_name' => $name ?: $contact?->displayName(),
            'requester_email' => $email,
            'subject' => $values['subject'],
            'body' => $values['body'],
            'channel' => 'web',
            'custom_fields' => self::customValues($values),
        ]);
    }

    /** @param  array<string, mixed>  $values */
    protected static function saveRecord(WebForm $form, array $values): Record
    {
        [$blueprint, $entity] = explode('.', $form->target, 2);
        $definition = self::entity($form->target);
        $contact = null;
        if (filled($values['contact.email'] ?? null) && $form->workspace->hasModule('contacts')) {
            $email = (string) $values['contact.email'];
            $contact = app(PublicPage::class)->contactFor($form->workspace, ['name' => $values['contact.name'] ?? Str::before($email, '@'), 'email' => $email, 'phone' => $values['contact.phone'] ?? null]);
        }

        $data = [];
        foreach ($definition->fields as $field) {
            if (array_key_exists('data.'.$field->key, $values)) {
                $data[$field->key] = $field->cast($values['data.'.$field->key]);
            }
        }

        return Record::create([
            'workspace_id' => $form->workspace_id,
            'blueprint' => $blueprint,
            'entity' => $entity,
            'title' => $values['title'],
            'data' => $data,
            'contact_id' => $contact?->id,
            'occurs_on' => $values['occurs_on'] ?? null,
            'currency' => $form->workspace->currency_code,
            'custom_fields' => self::customValues($values),
        ]);
    }

    // ----- Helpers ----------------------------------------------------------

    /**
     * @param  array<string, string>  $options
     * @param  list<mixed>|null  $rules
     * @return array{label: string, type: string, options: array<string, string>, must: bool, help: ?string, rules: list<mixed>}
     */
    protected static function field(string $label, string $type, array $options = [], bool $must = false, ?string $help = null, ?array $rules = null): array
    {
        $rules ??= match ($type) {
            'textarea' => ['string', 'max:5000'],
            'email' => ['email', 'max:190'],
            'phone' => ['string', 'max:40', 'regex:/^[0-9+()\-.\s]+$/'],
            'date' => ['date_format:Y-m-d'],
            default => ['string', 'max:190'],
        };

        return compact('label', 'type', 'options', 'must', 'help', 'rules');
    }

    /** @return array<string, array{label: string, type: string, options: array<string, string>, must: bool, help: ?string, rules: list<mixed>}> */
    protected static function appFields(string $target): array
    {
        $entity = self::entity($target);
        if (! $entity) {
            return [];
        }

        $fields = ['title' => self::field($entity->titleLabel, 'text', must: true)];
        foreach ($entity->fields as $field) {
            if (! in_array($field->type, ['record', 'user'], true)) {
                $fields['data.'.$field->key] = self::field($field->label, $field->type, $field->options, $field->required, $field->help, array_slice($field->rules(), 1));
            }
        }
        if ($entity->hasDate()) {
            $fields['occurs_on'] = self::field((string) $entity->dateLabel, 'date');
        }
        if ($entity->hasContact()) {
            $fields['contact.name'] = self::field('Your name', 'text');
            $fields['contact.email'] = self::field('Email address', 'email');
            $fields['contact.phone'] = self::field('Phone number', 'phone');
        }

        return $fields;
    }

    protected static function entity(string $target): ?Entity
    {
        if (! str_contains($target, '.')) {
            return null;
        }
        [$blueprint, $entity] = explode('.', $target, 2);

        return app(BlueprintRegistry::class)->get($blueprint)?->entity($entity);
    }

    /** A visitor cannot pick other records or team members, so an entity needing one cannot be filled in from outside. */
    protected static function fillable(?Entity $entity): bool
    {
        return $entity !== null && collect($entity->fields)->doesntContain(fn ($field) => $field->required && in_array($field->type, ['record', 'user'], true));
    }

    protected static function clean(string $type, mixed $value): mixed
    {
        if ($type === 'checkbox') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return match ($type) {
            'number', 'money' => is_numeric($value) ? $value + 0 : null,
            default => is_string($value) ? trim($value) : $value,
        };
    }

    /** @param  array{type: string, options: array<string, string>}  $field */
    protected static function display(array $field, mixed $value): ?string
    {
        return match (true) {
            $field['type'] === 'checkbox' => $value ? 'Yes' : 'No',
            $value === null => null,
            $field['type'] === 'select' => $field['options'][$value] ?? (string) $value,
            default => (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected static function customValues(array $values): array
    {
        $custom = [];
        foreach ($values as $key => $value) {
            if (str_starts_with($key, 'custom.')) {
                $custom[substr($key, 7)] = $value;
            }
        }

        return $custom;
    }

    /** Where to open the record a submission created, or null once it is gone. */
    public static function subjectUrl(?Model $subject): ?string
    {
        return match (true) {
            $subject instanceof Contact => route('contacts.show', $subject),
            $subject instanceof Ticket => route('tickets.show', $subject),
            $subject instanceof Record => $subject->url(),
            default => null,
        };
    }
}
