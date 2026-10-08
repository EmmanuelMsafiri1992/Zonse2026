<?php

namespace Modules\Tasks\Models;

use App\Models\Branch;
use App\Models\User;
use App\Tenancy\BelongsToWorkspace;
use App\Tenancy\HasComments;
use App\Tenancy\HasCustomFields;
use App\Tenancy\RecordsActivity;
use App\Tenancy\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Contacts\Models\Contact;
use Modules\Tasks\Database\Factories\TaskFactory;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use BelongsToWorkspace, HasComments, HasCustomFields, HasFactory, RecordsActivity, SoftDeletes;

    public const STATUSES = ['todo' => 'To do', 'in_progress' => 'In progress', 'done' => 'Done'];

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

    public const OPEN_STATUSES = ['todo', 'in_progress'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'todo', 'priority' => 'normal', 'position' => 0];

    protected $fillable = [
        'workspace_id', 'branch_id', 'contact_id', 'assignee_id', 'taskable_type', 'taskable_id', 'title', 'description',
        'status', 'priority', 'due_date', 'position', 'completed_at', 'created_by',
    ];

    /** @var list<string> */
    protected array $activityAttributes = ['status', 'priority', 'assignee_id', 'due_date', 'title'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'completed_at' => 'datetime', 'position' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (Task $task) {
            $task->uuid ??= (string) Str::uuid();
            $task->created_by ??= auth()->id();
        });

        static::saving(function (Task $task) {
            if ($task->isDirty('status')) {
                $task->completed_at = $task->status === 'done' ? now() : null;
            }
        });
    }

    protected static function newFactory(): TaskFactory
    {
        return TaskFactory::new();
    }

    /** Today's date in the workspace's timezone, since due dates are plain calendar days. */
    public static function localToday(): CarbonImmutable
    {
        $timezone = app(WorkspaceContext::class)->get()?->timezone ?: config('app.timezone');

        return CarbonImmutable::now($timezone)->startOfDay();
    }

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

    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $status ? $query->where('status', $status) : $query;
    }

    public function scopePriority(Builder $query, ?string $priority): Builder
    {
        return $priority ? $query->where('priority', $priority) : $query;
    }

    public function scopeAssignedTo(Builder $query, int|string|null $userId): Builder
    {
        if ($userId === 'unassigned') {
            return $query->whereNull('assignee_id');
        }

        return $userId ? $query->where('assignee_id', $userId) : $query;
    }

    public function scopeForContact(Builder $query, int|string|null $contactId): Builder
    {
        return $contactId ? $query->where('contact_id', $contactId) : $query;
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereDate('due_date', '<', self::localToday()->toDateString());
    }

    public function scopeDueOn(Builder $query, CarbonImmutable $day): Builder
    {
        return $query->whereDate('due_date', $day->toDateString());
    }

    public function scopeDueBetween(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query->whereBetween('due_date', [$from->toDateString(), $to->toDateString()]);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")
                ->orWhereHas('contact', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")->orWhere('company_name', 'like', "%{$term}%"));
        });
    }

    /** Sort by priority weight (urgent first), then due date, then board position. */
    public function scopeOrderByUrgency(Builder $query): Builder
    {
        return $query->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderByRaw('case when due_date is null then 1 else 0 end')
            ->orderBy('due_date')->orderBy('position')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date !== null && $this->due_date->lt(self::localToday());
    }

    public function isDueToday(): bool
    {
        return $this->due_date !== null && $this->due_date->isSameDay(self::localToday());
    }

    public function dueLabel(): ?string
    {
        if ($this->due_date === null) {
            return null;
        }
        if ($this->isDueToday()) {
            return 'Today';
        }
        if ($this->due_date->isSameDay(self::localToday()->addDay())) {
            return 'Tomorrow';
        }
        if ($this->due_date->isSameDay(self::localToday()->subDay())) {
            return 'Yesterday';
        }

        return $this->due_date->format('D d M');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? ucfirst($this->priority);
    }

    /** Move the task into a status column at a position and shift its siblings down. */
    public function moveTo(string $status, int $position): static
    {
        abort_unless(array_key_exists($status, self::STATUSES), 422, 'Unknown status.');

        $siblings = static::query()->where('status', $status)->where('id', '!=', $this->id)->orderBy('position')->orderBy('id')->pluck('id');
        $ordered = $siblings->splice(0, max(0, $position))->push($this->id)->concat($siblings)->values();

        $ordered->each(fn (int $id, int $index) => static::query()->whereKey($id)->update(['position' => $index]));

        $this->forceFill(['status' => $status, 'position' => $ordered->search($this->id)])->save();

        return $this;
    }

    public function activityLabel(): string
    {
        return 'Task "'.Str::limit($this->title, 40).'"';
    }

    public function activityUrl(): string
    {
        return route('tasks.show', $this);
    }
}
