<?php

namespace App\Models;

use App\Support\WebForms;
use App\Tenancy\BelongsToWorkspace;
use Database\Factories\WebFormFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A public form the workspace built: visitors fill it in at its link (or an embed on another
 * site) and each answer becomes a contact, ticket or app record.
 *
 * @property list<array{key: string, label: string, required: bool, help: ?string}> $fields
 */
class WebForm extends Model
{
    /** @use HasFactory<WebFormFactory> */
    use BelongsToWorkspace, HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'submissions_count' => 0, 'fields' => '[]'];

    protected $fillable = ['workspace_id', 'name', 'target', 'title', 'intro', 'fields', 'success_message', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'is_active' => 'boolean',
            'last_submitted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WebForm $form) {
            $form->uuid ??= (string) Str::uuid();
            $form->created_by ??= auth()->id();
        });
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function targetLabel(): string
    {
        return WebForms::targetLabel($this->target);
    }

    /** The link to share with visitors. */
    public function publicUrl(): string
    {
        return route('forms.public.show', $this->uuid);
    }

    /** HTML to paste into another website to show the form there. */
    public function embedCode(): string
    {
        return '<iframe src="'.e(route('forms.public.show', ['uuid' => $this->uuid, 'embed' => 1])).'" title="'.e($this->title).'" style="width:100%;min-height:640px;border:0" loading="lazy"></iframe>';
    }
}
