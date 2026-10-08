<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Appointments\Models\Appointment;
use Modules\Contacts\Models\Contact;
use Modules\Helpdesk\Models\Ticket;
use Modules\Tasks\Models\Task;

/**
 * An extra field a workspace adds to one kind of record, such as "Medical aid number" on contacts.
 * Values live in the record's own custom_fields column, keyed by the field's key.
 *
 * @property list<string>|null $options
 */
class CustomField extends Model
{
    /** @use HasFactory<CustomFieldFactory> */
    use BelongsToWorkspace, HasFactory;

    /** Most fields one kind of record may carry. */
    public const MAX_PER_ENTITY = 30;

    /** @var array<string, array{label: string, icon: string}> */
    public const TYPES = [
        'text' => ['label' => 'Short text', 'icon' => 'type'],
        'textarea' => ['label' => 'Long text', 'icon' => 'align-left'],
        'number' => ['label' => 'Number', 'icon' => 'hash'],
        'date' => ['label' => 'Date', 'icon' => 'calendar'],
        'select' => ['label' => 'Pick from a list', 'icon' => 'list'],
        'checkbox' => ['label' => 'Yes / no', 'icon' => 'square-check'],
        'email' => ['label' => 'Email address', 'icon' => 'mail'],
        'phone' => ['label' => 'Phone number', 'icon' => 'phone'],
        'url' => ['label' => 'Web address', 'icon' => 'link'],
    ];

    /** @var array<string, array{label: string, singular: string, module: string, model: class-string<Model>}> */
    public const ENTITIES = [
        'contact' => ['label' => 'Contacts', 'singular' => 'contact', 'module' => 'contacts', 'model' => Contact::class],
        'task' => ['label' => 'Tasks', 'singular' => 'task', 'module' => 'tasks', 'model' => Task::class],
        'ticket' => ['label' => 'Tickets', 'singular' => 'ticket', 'module' => 'helpdesk', 'model' => Ticket::class],
        'appointment' => ['label' => 'Appointments', 'singular' => 'appointment', 'module' => 'appointments', 'model' => Appointment::class],
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['is_required' => false, 'position' => 0];

    protected $fillable = ['workspace_id', 'entity', 'key', 'label', 'type', 'options', 'is_required', 'help', 'position'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_required' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CustomField $field) {
            $field->key = $field->key ?: self::uniqueKey($field);
            $field->position = $field->position ?: (int) self::query()->where('workspace_id', $field->workspace_id)->where('entity', $field->entity)->max('position') + 1;
        });
    }

    /** A short, stable key made from the label, unique for this kind of record. */
    protected static function uniqueKey(CustomField $field): string
    {
        $base = Str::limit(Str::snake(Str::ascii(preg_replace('/[^A-Za-z0-9 ]+/', ' ', $field->label) ?? '')), 50, '') ?: 'field';
        $base = trim($base, '_') ?: 'field';
        $key = $base;
        for ($n = 2; self::query()->where('workspace_id', $field->workspace_id)->where('entity', $field->entity)->where('key', $key)->exists(); $n++) {
            $key = $base.'_'.$n;
        }

        return $key;
    }

    /** Which kind of record a model is, or null when it cannot carry custom fields. */
    public static function entityFor(Model|string $model): ?string
    {
        $class = is_string($model) ? $model : $model::class;

        return collect(self::ENTITIES)->search(fn (array $entity) => $entity['model'] === $class) ?: null;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type]['label'] ?? $this->type;
    }

    public function entityLabel(): string
    {
        return self::ENTITIES[$this->entity]['label'] ?? $this->entity;
    }

    /**
     * Validation rules for one value of this field.
     *
     * @return list<mixed>
     */
    public function rules(): array
    {
        $presence = $this->is_required && $this->type !== 'checkbox' ? 'required' : 'nullable';

        return [$presence, ...match ($this->type) {
            'textarea' => ['string', 'max:5000'],
            'number' => ['numeric', 'between:-1000000000000,1000000000000'],
            'date' => ['date_format:Y-m-d'],
            'select' => [Rule::in($this->options ?? [])],
            'checkbox' => ['boolean'],
            'email' => ['email', 'max:160'],
            'phone' => ['string', 'max:40', 'regex:/^[0-9+()\-.\s]+$/'],
            'url' => ['url:http,https', 'max:255'],
            default => ['string', 'max:255'],
        }];
    }

    /** The value as it is stored: numbers as numbers, yes/no as true/false, blanks as null. */
    public function clean(mixed $value): mixed
    {
        if ($this->type === 'checkbox') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return match ($this->type) {
            'number' => is_numeric($value) ? $value + 0 : null,
            default => is_string($value) ? trim($value) : $value,
        };
    }

    /** The value written out for people to read. */
    public function display(mixed $value): ?string
    {
        if ($this->type === 'checkbox') {
            return $value === null ? null : ($value ? 'Yes' : 'No');
        }
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->type) {
            'number' => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 4, '.', ','), '0'), '.') : (string) $value,
            'date' => rescue(fn () => CarbonImmutable::createFromFormat('Y-m-d', (string) $value)->format('j M Y'), (string) $value, false),
            default => (string) $value,
        };
    }
}
