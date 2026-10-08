<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One time an automation was triggered, and what each of its actions did. */
class AutomationRun extends Model
{
    use BelongsToWorkspace;

    public const STATUSES = ['succeeded' => 'Done', 'skipped' => 'Skipped', 'failed' => 'Had problems'];

    protected $fillable = ['workspace_id', 'automation_id', 'event', 'subject_type', 'subject_id', 'subject_label', 'status', 'log'];

    protected function casts(): array
    {
        return ['log' => 'array'];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }
}
