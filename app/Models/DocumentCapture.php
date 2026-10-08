<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Database\Factories\DocumentCaptureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A photo or PDF of a receipt, supplier invoice or ID document. The text is read from it,
 * the useful fields are picked out for someone to check, and it is then turned into an
 * expense or a contact.
 */
class DocumentCapture extends Model
{
    /** @use HasFactory<DocumentCaptureFactory> */
    use BelongsToWorkspace, HasFactory;

    /**
     * What can be scanned, and the fields read from each.
     *
     * @var array<string, array{label: string, hint: string, fields: array<string, array{label: string, type: string}>}>
     */
    public const TYPES = [
        'receipt' => [
            'label' => 'Receipt',
            'hint' => 'Till slips and cash receipts, saved as an expense.',
            'fields' => [
                'merchant' => ['label' => 'Shop or supplier', 'type' => 'text'],
                'date' => ['label' => 'Date', 'type' => 'date'],
                'total' => ['label' => 'Total paid', 'type' => 'money'],
                'tax' => ['label' => 'VAT / tax', 'type' => 'money'],
                'currency' => ['label' => 'Currency', 'type' => 'currency'],
                'number' => ['label' => 'Receipt number', 'type' => 'text'],
                'tax_number' => ['label' => 'Supplier VAT / tax number', 'type' => 'text'],
                'category' => ['label' => 'Category', 'type' => 'category'],
            ],
        ],
        'invoice' => [
            'label' => 'Supplier invoice',
            'hint' => 'Bills from suppliers, saved as an expense and the supplier as a contact.',
            'fields' => [
                'merchant' => ['label' => 'Supplier', 'type' => 'text'],
                'number' => ['label' => 'Invoice number', 'type' => 'text'],
                'date' => ['label' => 'Invoice date', 'type' => 'date'],
                'due_date' => ['label' => 'Due date', 'type' => 'date'],
                'total' => ['label' => 'Total due', 'type' => 'money'],
                'tax' => ['label' => 'VAT / tax', 'type' => 'money'],
                'currency' => ['label' => 'Currency', 'type' => 'currency'],
                'tax_number' => ['label' => 'Supplier VAT / tax number', 'type' => 'text'],
                'category' => ['label' => 'Category', 'type' => 'category'],
            ],
        ],
        'id_document' => [
            'label' => 'ID document',
            'hint' => 'National ID cards and passports, saved as a contact.',
            'fields' => [
                'surname' => ['label' => 'Surname', 'type' => 'text'],
                'first_names' => ['label' => 'First names', 'type' => 'text'],
                'id_number' => ['label' => 'ID or passport number', 'type' => 'text'],
                'date_of_birth' => ['label' => 'Date of birth', 'type' => 'date'],
                'sex' => ['label' => 'Sex', 'type' => 'text'],
                'nationality' => ['label' => 'Nationality', 'type' => 'text'],
            ],
        ],
    ];

    /** @var array<string, string> */
    public const STATUSES = [
        'processing' => 'Reading',
        'ready' => 'Ready to check',
        'failed' => 'Could not read',
        'done' => 'Saved',
    ];

    /** Expense categories in the Expenses app, matched from words on the receipt. */
    public const CATEGORIES = ['fuel', 'travel', 'meals', 'office', 'utilities', 'repairs', 'airtime', 'other'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'processing'];

    protected $fillable = [
        'workspace_id', 'uuid', 'type', 'status', 'file_name', 'file_path', 'mime', 'file_size', 'file_hash',
        'provider', 'raw_text', 'fields', 'error', 'result_type', 'result_id', 'created_by', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['fields' => 'array', 'file_size' => 'integer', 'processed_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function result(): MorphTo
    {
        return $this->morphTo();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type]['label'] ?? ucfirst($this->type);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    /** The status pill class used across the app. */
    public function pillStatus(): string
    {
        return match ($this->status) {
            'ready' => 'pending',
            'done' => 'completed',
            'failed' => 'rejected',
            default => 'draft',
        };
    }

    /** @return array<string, array{label: string, type: string}> */
    public function fieldDefinitions(): array
    {
        return self::TYPES[$this->type]['fields'] ?? [];
    }

    public function field(string $key): ?string
    {
        $value = $this->fields[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /** A short name for lists: who the receipt is from, or whose ID it is. */
    public function summary(): string
    {
        if ($this->type === 'id_document') {
            $name = trim(($this->field('first_names') ?? '').' '.($this->field('surname') ?? ''));

            return $name !== '' ? $name : $this->file_name;
        }

        return $this->field('merchant') ?? $this->file_name;
    }

    public function activityUrl(): string
    {
        return route('captures.show', $this);
    }
}
