<?php

namespace App\Models;

use App\Support\Automations;
use App\Tenancy\BelongsToWorkspace;
use Database\Factories\AutomationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A "when this happens, do that" rule: a trigger event, optional conditions and one or more actions.
 *
 * @property list<array{field: string, operator: string, value: ?string}> $conditions
 * @property list<array<string, mixed>> $actions
 */
class Automation extends Model
{
    /** @use HasFactory<AutomationFactory> */
    use BelongsToWorkspace, HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'run_count' => 0, 'conditions' => '[]', 'actions' => '[]'];

    protected $fillable = ['workspace_id', 'name', 'trigger', 'conditions', 'actions', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'actions' => 'array',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Automation $automation) {
            $automation->created_by ??= auth()->id();
        });
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function triggerLabel(): string
    {
        return Automations::TRIGGERS[$this->trigger] ?? $this->trigger;
    }

    /** One plain-English line per action, for lists. */
    public function summary(): string
    {
        return collect($this->actions)->map(fn (array $action) => Automations::ACTIONS[$action['type']]['label'] ?? $action['type'])->implode(', ');
    }
}
