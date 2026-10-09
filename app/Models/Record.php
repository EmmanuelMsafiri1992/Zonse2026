<?php

namespace App\Models;

use App\Blueprints\AppLogic;
use App\Blueprints\Blueprint;
use App\Blueprints\BlueprintRegistry;
use App\Blueprints\Entity;
use App\Blueprints\Field;
use App\Support\Sequence;
use App\Tenancy\BelongsToWorkspace;
use App\Tenancy\HasComments;
use App\Tenancy\HasCustomFields;
use App\Tenancy\RecordsActivity;
use App\Tenancy\WorkspaceContext;
use Database\Factories\RecordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;

/**
 * A row in any blueprint-driven app. The blueprint + entity keys say which
 * definition describes it; the shared columns (title, status, contact,
 * assignee, amount, dates) are real columns and the rest lives in `data`.
 */
class Record extends Model
{
    /** @use HasFactory<RecordFactory> */
    use BelongsToWorkspace, HasComments, HasCustomFields, HasFactory, RecordsActivity, SoftDeletes;

    /** Statuses that mean nothing more needs doing, whatever the app. */
    public const DONE_STATUSES = ['done', 'completed', 'closed', 'cancelled', 'archived', 'resolved', 'paid', 'delivered', 'rejected', 'expired'];

    protected $fillable = [
        'workspace_id', 'branch_id', 'blueprint', 'entity', 'number', 'title', 'status', 'data',
        'contact_id', 'assignee_id', 'amount', 'currency', 'occurs_on', 'due_on', 'created_by',
    ];

    /** @var list<string> */
    protected array $activityAttributes = ['status', 'title', 'assignee_id', 'amount', 'occurs_on', 'due_on', 'contact_id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'amount' => 'decimal:2',
            'occurs_on' => 'date',
            'due_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Record $record) {
            $record->uuid ??= (string) Str::uuid();
            $record->created_by ??= auth()->id();
            $record->status = $record->status ?: $record->definition()->defaultStatus();
            if (empty($record->number)) {
                $entity = $record->definition();
                $record->number = Sequence::next($entity->sequenceKey(), $entity->numberPrefix, $record->workspace_id ?: app(WorkspaceContext::class)->id());
            }
        });

        static::saving(function (Record $record) {
            $record->appLogic()?->saving($record);
            $record->search_text = $record->buildSearchText();
        });

        static::saved(function (Record $record) {
            $record->appLogic()?->saved($record);
        });
    }

    // ----- Definition -------------------------------------------------------

    public function blueprintDefinition(): Blueprint
    {
        return app(BlueprintRegistry::class)->get($this->blueprint)
            ?? throw new \RuntimeException("Unknown blueprint [{$this->blueprint}].");
    }

    /** The app's behaviour class, or null when the blueprint no longer exists. */
    public function appLogic(): ?AppLogic
    {
        return app(BlueprintRegistry::class)->get((string) $this->blueprint)?->logic();
    }

    public function definition(): Entity
    {
        return $this->blueprintDefinition()->entity($this->entity)
            ?? throw new \RuntimeException("Unknown entity [{$this->entity}] on blueprint [{$this->blueprint}].");
    }

    /** Value of one of the app's own fields from the data JSON (the workspace's extra fields are customField()). */
    public function value(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function statusLabel(): string
    {
        return $this->definition()->statuses[$this->status] ?? Entity::statusLabel((string) $this->status);
    }

    public function statusTone(): string
    {
        return $this->definition()->statusTone($this->status);
    }

    public function isDone(): bool
    {
        return in_array($this->status, self::DONE_STATUSES, true);
    }

    // ----- Relations --------------------------------------------------------

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Invoices raised from this record (a visit's bill, each month's rent). */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** The newest invoice that still has money owing. */
    public function openInvoice(): ?Invoice
    {
        return $this->invoices()->whereIn('status', Invoice::OPEN_STATUSES)->latest('id')->first();
    }

    /** The record a "record" field points to, if any. */
    public function related(string $fieldKey): ?Record
    {
        $field = $this->definition()->field($fieldKey);
        $id = $this->value($fieldKey);
        if (! $field || $field->type !== 'record' || ! $id) {
            return null;
        }

        return static::query()->ofEntity($this->blueprint, $field->relatedEntity)->find($id);
    }

    // ----- Scopes -----------------------------------------------------------

    public function scopeOfEntity(Builder $query, string $blueprint, string $entity): Builder
    {
        return $query->where('blueprint', $blueprint)->where('entity', $entity);
    }

    public function scopeOfBlueprint(Builder $query, string $blueprint): Builder
    {
        return $query->where('blueprint', $blueprint);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where('search_text', 'like', '%'.mb_strtolower($term).'%');
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $status ? $query->where('status', $status) : $query;
    }

    public function scopeForContact(Builder $query, int|string|null $contactId): Builder
    {
        return $contactId ? $query->where('contact_id', $contactId) : $query;
    }

    public function scopeAssignedTo(Builder $query, int|string|null $assignee): Builder
    {
        if (! $assignee) {
            return $query;
        }
        if ($assignee === 'unassigned') {
            return $query->whereNull('assignee_id');
        }
        if ($assignee === 'me') {
            $assignee = auth()->id();
        }

        return $query->where('assignee_id', $assignee);
    }

    /** Records whose record-field $fieldKey points at the given record id. */
    public function scopeLinkedTo(Builder $query, string $fieldKey, int $recordId): Builder
    {
        return $query->where('data->'.$fieldKey, $recordId);
    }

    // ----- Activity ---------------------------------------------------------

    public function activityLabel(): string
    {
        try {
            return $this->definition()->label.' '.$this->number;
        } catch (\RuntimeException) {
            return 'Record '.$this->number;
        }
    }

    public function activityUrl(): ?string
    {
        return route('apps.records.show', ['blueprint' => $this->blueprint, 'entity' => $this->entity, 'record' => $this->id]);
    }

    public function url(): string
    {
        return $this->activityUrl();
    }

    protected function buildSearchText(): string
    {
        $parts = [$this->title, $this->number];
        try {
            foreach ($this->definition()->fields as $field) {
                if ($field->isTextual() && filled($this->value($field->key))) {
                    $parts[] = (string) $this->value($field->key);
                }
            }
        } catch (\RuntimeException) {
            // Unknown definition (e.g. a removed blueprint); index the shared columns only.
        }
        foreach ((array) ($this->custom_fields ?? []) as $value) {
            if (is_string($value) && $value !== '') {
                $parts[] = $value;
            }
        }

        return mb_strtolower(implode(' ', array_filter($parts)));
    }

    /** @return array<string, mixed> field key => cast value, for forms and exports */
    public function fieldValues(): array
    {
        $values = [];
        foreach ($this->definition()->fields as $field) {
            $values[$field->key] = $this->value($field->key);
        }

        return $values;
    }

    /** Human-readable value of a field, for lists and CSV exports. */
    public function displayValue(Field $field): string
    {
        $value = $this->value($field->key);
        if ($value === null || $value === '') {
            return '';
        }

        return match ($field->type) {
            'checkbox' => $value ? 'Yes' : 'No',
            'money' => number_format((float) $value, 2),
            'number' => rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.'),
            'select' => $field->options[$value] ?? (string) $value,
            'record' => $this->related($field->key)?->title ?? '',
            'user' => User::query()->find($value)?->name ?? '',
            'date' => (string) Carbon::parse($value)->format('d M Y'),
            'datetime' => (string) Carbon::parse($value)->format('d M Y H:i'),
            default => (string) $value,
        };
    }
}
