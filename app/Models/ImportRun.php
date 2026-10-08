<?php

namespace App\Models;

use App\Support\Import\Importer;
use App\Tenancy\BelongsToWorkspace;
use Database\Factories\ImportRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One spreadsheet brought into a workspace: the parsed rows while the owner matches columns and
 * checks the preview, then what the import created so it can be undone.
 */
class ImportRun extends Model
{
    /** @use HasFactory<ImportRunFactory> */
    use BelongsToWorkspace, HasFactory;

    public const STATUSES = ['mapping' => 'Matching columns', 'ready' => 'Ready to import', 'done' => 'Imported', 'undone' => 'Undone'];

    protected $fillable = [
        'workspace_id', 'user_id', 'target', 'source', 'filename', 'status', 'headers', 'rows', 'mapping', 'options', 'summary',
        'total_rows', 'created_count', 'updated_count', 'skipped_count', 'failed_count', 'created_ids', 'imported_at', 'undone_at',
    ];

    /** @var list<string> */
    protected $hidden = ['rows'];

    protected function casts(): array
    {
        return [
            'headers' => 'array', 'rows' => 'array', 'mapping' => 'array', 'options' => 'array', 'summary' => 'array', 'created_ids' => 'array',
            'total_rows' => 'integer', 'created_count' => 'integer', 'updated_count' => 'integer', 'skipped_count' => 'integer', 'failed_count' => 'integer',
            'imported_at' => 'datetime', 'undone_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'undone'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function sourceName(): ?string
    {
        return $this->source ? (Importer::SOURCES[$this->source]['name'] ?? null) : null;
    }
}
