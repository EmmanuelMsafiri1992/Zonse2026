<?php

namespace App\Models;

use App\Support\DocumentTemplates;
use App\Tenancy\BelongsToWorkspace;
use Database\Factories\DocumentTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A letter, certificate or receipt the workspace designed: fixed wording with merge tags such as
 * {{ contact.name }}, filled in from a contact, payment or app record and printed as a PDF.
 *
 * @property list<string> $signatures the role under each signature line, e.g. "Director"
 */
class DocumentTemplate extends Model
{
    /** @use HasFactory<DocumentTemplateFactory> */
    use BelongsToWorkspace, HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'paper' => 'a4', 'orientation' => 'portrait', 'font' => 'sans', 'align' => 'left', 'border' => 'none',
        'color' => '#0073ea', 'show_logo' => true, 'signatures' => '[]', 'is_active' => true, 'generated_count' => 0,
    ];

    protected $fillable = [
        'workspace_id', 'name', 'kind', 'subject', 'heading', 'body', 'paper', 'orientation', 'font', 'align',
        'border', 'color', 'show_logo', 'signatures', 'footer', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'signatures' => 'array',
            'show_logo' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DocumentTemplate $template) {
            $template->created_by ??= auth()->id();
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Switched-on templates that fill in from this kind of subject. */
    public function scopeFor(Builder $query, string $subject): Builder
    {
        return $query->where('subject', $subject)->where('is_active', true)->orderBy('name');
    }

    public function kindLabel(): string
    {
        return DocumentTemplates::KINDS[$this->kind] ?? ucfirst($this->kind);
    }

    public function subjectLabel(): string
    {
        return DocumentTemplates::subjectLabel($this->subject);
    }
}
