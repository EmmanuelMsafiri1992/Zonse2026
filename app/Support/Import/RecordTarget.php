<?php

namespace App\Support\Import;

use App\Blueprints\Blueprint;
use App\Blueprints\Entity;
use App\Blueprints\Field;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Contacts\Models\Contact;

/**
 * The records of one app entity (students, bookings, properties…). Headings match the entity's
 * own labels, so a file from the app's "Export CSV" button imports straight back.
 */
class RecordTarget extends ImportTarget
{
    /** @var array<string, array<string, int>> lookups by kind => normalised text => id */
    protected array $lookups = [];

    public function __construct(public Blueprint $app, public Entity $entity, protected Workspace $workspace) {}

    public function key(): string
    {
        return 'records.'.$this->app->key.'.'.$this->entity->key;
    }

    public function label(): string
    {
        return $this->app->name.': '.$this->entity->plural;
    }

    public function icon(): string
    {
        return $this->entity->icon;
    }

    public function listUrl(): ?string
    {
        return route('apps.records.index', [$this->app->key, $this->entity->key]);
    }

    public function modelClass(): string
    {
        return Record::class;
    }

    public function columns(): array
    {
        $entity = $this->entity;
        $columns = ['title' => ['label' => $entity->titleLabel, 'required' => true, 'example' => 'Example '.strtolower($entity->label), 'aliases' => ['title', 'name']]];
        if (count($entity->statuses) > 1) {
            $columns['status'] = ['label' => 'Status', 'example' => reset($entity->statuses), 'hint' => implode(', ', $entity->statuses).'.', 'aliases' => ['stage', 'state']];
        }
        if ($entity->hasContact()) {
            $columns['contact'] = ['label' => $entity->contactLabel, 'example' => 'Tendai Moyo', 'hint' => 'The name or email of an existing contact.', 'aliases' => ['contact', 'customer', 'client', 'contactname', 'contactemail']];
        }
        if ($entity->hasAssignee) {
            $columns['assignee'] = ['label' => 'Assignee', 'example' => '', 'hint' => 'The name or email of a team member.', 'aliases' => ['assignedto', 'owner', 'staff', 'responsible']];
        }
        if ($entity->hasAmount()) {
            $columns['amount'] = ['label' => $entity->amountLabel, 'example' => '100.00', 'aliases' => ['amount', 'total', 'value', 'price']];
        }
        if ($entity->hasDate()) {
            $columns['occurs_on'] = ['label' => $entity->dateLabel, 'example' => today()->format('Y-m-d'), 'aliases' => ['date']];
        }
        if ($entity->hasDue()) {
            $columns['due_on'] = ['label' => $entity->dueLabel, 'example' => today()->addWeek()->format('Y-m-d'), 'aliases' => ['duedate', 'due', 'deadline']];
        }
        foreach ($entity->fields as $field) {
            $columns['data.'.$field->key] = [
                'label' => $field->label,
                'required' => $field->required,
                'example' => $this->example($field),
                'hint' => match ($field->type) {
                    'select' => implode(', ', $field->options).'.',
                    'record' => 'The name or number of an existing '.strtolower($this->app->entity((string) $field->relatedEntity)?->label ?? 'record').'.',
                    'user' => 'The name or email of a team member.',
                    'checkbox' => 'Yes or no.',
                    default => null,
                },
                'aliases' => [$field->key],
            ];
        }

        return $columns;
    }

    public function prepare(array $raw, array $options): array
    {
        $entity = $this->entity;
        $errors = [];
        $warnings = [];
        $order = $options['date_order'] ?? 'dmy';
        $text = fn (string $key) => array_key_exists($key, $raw) ? (Values::text($raw[$key]) ?: null) : null;

        $title = $text('title');
        if ($title === null) {
            $errors[] = 'Needs a '.strtolower($entity->titleLabel).'.';
        }

        $status = null;
        if (($statusText = $text('status')) !== null && ! ($status = Values::option($statusText, $entity->statuses))) {
            $errors[] = 'Status "'.$statusText.'" is not one of: '.implode(', ', $entity->statuses).'.';
        }

        $contactId = null;
        if (($contactText = $text('contact')) !== null && ! ($contactId = $this->contactId($contactText))) {
            $warnings[] = 'No contact called "'.$contactText.'", so '.strtolower($entity->contactLabel).' is left blank.';
        }
        $assigneeId = null;
        if (($assigneeText = $text('assignee')) !== null && ! ($assigneeId = $this->memberId($assigneeText))) {
            $warnings[] = '"'.$assigneeText.'" is not on your team, so nobody is assigned.';
        }

        $amount = null;
        if (($amountText = $text('amount')) !== null && ($amount = Values::number($amountText)) === null) {
            $errors[] = $entity->amountLabel.' "'.$amountText.'" is not a number.';
        }
        $dates = [];
        foreach (['occurs_on' => $entity->dateLabel, 'due_on' => $entity->dueLabel] as $key => $label) {
            $dates[$key] = null;
            if (($dateText = $text($key)) !== null && ! ($dates[$key] = Values::date($dateText, $order))) {
                $errors[] = $label.' "'.$dateText.'" is not a date.';
            }
        }

        $data = [];
        foreach ($entity->fields as $field) {
            $cell = $text('data.'.$field->key);
            if ($cell === null) {
                if ($field->required && array_key_exists('data.'.$field->key, $raw)) {
                    $errors[] = 'Needs '.strtolower($field->label).'.';
                }

                continue;
            }
            [$value, $problem, $isWarning] = $this->fieldValue($field, $cell, $order);
            if ($problem && $isWarning) {
                $warnings[] = $problem;
            } elseif ($problem) {
                $errors[] = $problem;
            }
            if ($value !== null) {
                $data[$field->key] = $value;
            }
        }

        $values = [
            'title' => $title,
            'status' => $status,
            'contact_id' => $contactId,
            'assignee_id' => $assigneeId,
            'amount' => $amount !== null ? round($amount, 2) : null,
            'occurs_on' => $dates['occurs_on'],
            'due_on' => $dates['due_on'],
            'data' => $data,
        ];

        $rules = ['title' => ['nullable', 'string', 'max:255'], 'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999']];
        $labels = [];
        foreach ($entity->fields as $field) {
            if (! in_array($field->type, ['record', 'user'], true)) {
                $rules['data.'.$field->key] = array_map(fn ($rule) => $rule === 'required' ? 'nullable' : $rule, $field->rules());
            }
        }
        $errors = [...$errors, ...$this->validate($values, $rules)];

        return ['values' => $values, 'errors' => $errors, 'warnings' => $warnings];
    }

    public function check(array $values, ?Model $existing): array
    {
        $payload = $this->payload($values, $existing);
        if (! $existing) {
            foreach ($this->entity->fields as $field) {
                if ($field->required && ($payload['data'][$field->key] ?? null) === null) {
                    return ['Needs '.strtolower($field->label).'.'];
                }
            }
        }

        return array_values($this->app->logic()->validate($this->entity, $payload, $existing));
    }

    public function matchKey(array $values): ?string
    {
        return 'title:'.Values::key((string) $values['title']);
    }

    public function findExisting(array $values): ?Model
    {
        return Record::query()->ofEntity($this->app->key, $this->entity->key)->whereRaw('lower(title) = ?', [mb_strtolower((string) $values['title'])])->first();
    }

    public function create(array $values, array $options): Model
    {
        return Record::create([...$this->payload($values, null), 'blueprint' => $this->app->key, 'entity' => $this->entity->key]);
    }

    public function update(Model $model, array $values): void
    {
        $model->update($this->payload($values, $model));
    }

    public function describe(Model $model): string
    {
        return Str::limit($model->title, 60).' ('.$model->number.')';
    }

    public function inUse(Model $model): bool
    {
        return $model->invoices()->exists();
    }

    /**
     * The full attributes to save: the row's values over the existing record, or over the defaults.
     *
     * @return array<string, mixed>
     */
    protected function payload(array $values, ?Model $existing): array
    {
        $entity = $this->entity;
        $data = array_merge(
            $existing ? (array) $existing->data : collect($entity->fields)->mapWithKeys(fn (Field $field) => [$field->key => $field->cast(null)])->all(),
            $values['data'],
        );
        $base = $existing ? $existing->only(['title', 'status', 'contact_id', 'assignee_id', 'amount', 'occurs_on', 'due_on']) : ['status' => $entity->defaultStatus()];

        return array_merge($base, $this->given(array_intersect_key($values, array_flip(['title', 'status', 'contact_id', 'assignee_id', 'amount', 'occurs_on', 'due_on']))), [
            'data' => $data,
            'currency' => $entity->hasAmount() ? ($existing?->currency ?? $this->workspace->currency_code) : null,
        ]);
    }

    /** @return array{0: mixed, 1: ?string, 2: bool} value, problem, whether the problem is only a warning */
    protected function fieldValue(Field $field, string $cell, string $order): array
    {
        $label = $field->label;

        return match ($field->type) {
            'number', 'money' => ($number = Values::number($cell)) === null ? [null, $label.' "'.$cell.'" is not a number.', false] : [$field->type === 'money' ? round($number, 2) : $number, null, false],
            'date', 'datetime' => ($date = Values::date($cell, $order)) ? [$date, null, false] : [null, $label.' "'.$cell.'" is not a date.', false],
            'time' => ($time = Values::time($cell)) ? [$time, null, false] : [null, $label.' "'.$cell.'" is not a time.', false],
            'select' => ($option = Values::option($cell, $field->options)) ? [$option, null, false] : [null, $label.' "'.$cell.'" is not one of: '.implode(', ', $field->options).'.', false],
            'checkbox' => ($flag = Values::boolean($cell)) === null ? [null, $label.' "'.$cell.'" is not yes or no.', false] : [$flag, null, false],
            'record' => ($id = $this->relatedId((string) $field->relatedEntity, $cell)) ? [$id, null, false]
                : [null, 'No '.strtolower($this->app->entity((string) $field->relatedEntity)?->label ?? 'record').' called "'.$cell.'".', ! $field->required],
            'user' => ($id = $this->memberId($cell)) ? [$id, null, false] : [null, '"'.$cell.'" is not on your team, so '.strtolower($label).' is left blank.', true],
            default => [$cell, null, false],
        };
    }

    protected function example(Field $field): string
    {
        return match ($field->type) {
            'number' => '1', 'money' => '50.00', 'date' => today()->format('Y-m-d'), 'datetime' => today()->format('Y-m-d'), 'time' => '09:00',
            'select' => (string) reset($field->options), 'checkbox' => 'no', 'email' => 'name@example.com', 'phone' => '+263 77 123 4567', 'url' => 'https://example.com',
            default => '',
        };
    }

    protected function contactId(string $text): ?int
    {
        if (! isset($this->lookups['contacts'])) {
            $this->lookups['contacts'] = [];
            foreach (Contact::query()->get(['id', 'name', 'company_name', 'email']) as $contact) {
                foreach ([$contact->email, $contact->company_name, $contact->name, $contact->displayName()] as $key) {
                    if ($key) {
                        $this->lookups['contacts'][Values::key($key)] ??= $contact->id;
                    }
                }
            }
        }

        return $this->lookups['contacts'][Values::key($text)] ?? null;
    }

    protected function memberId(string $text): ?int
    {
        if (! isset($this->lookups['members'])) {
            $this->lookups['members'] = [];
            foreach ($this->workspace->members()->get(['users.id', 'users.name', 'users.email']) as $member) {
                $this->lookups['members'][Values::key($member->email)] = $member->id;
                $this->lookups['members'][Values::key($member->name)] ??= $member->id;
            }
        }

        return $this->lookups['members'][Values::key($text)] ?? null;
    }

    protected function relatedId(string $entityKey, string $text): ?int
    {
        $kind = 'record:'.$entityKey;
        if (! isset($this->lookups[$kind])) {
            $this->lookups[$kind] = [];
            foreach (Record::query()->ofEntity($this->app->key, $entityKey)->get(['id', 'title', 'number']) as $record) {
                $this->lookups[$kind][Values::key((string) $record->number)] = $record->id;
                $this->lookups[$kind][Values::key($record->title)] ??= $record->id;
            }
        }

        return $this->lookups[$kind][Values::key($text)] ?? null;
    }
}
