<?php

namespace App\Models;

use App\Support\Money;
use App\Tenancy\BelongsToWorkspace;
use Database\Factories\ApprovalRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One ask for a yes: who asked, about what and for how much, and what the approver decided. */
class ApprovalRequest extends Model
{
    /** @use HasFactory<ApprovalRequestFactory> */
    use BelongsToWorkspace, HasFactory;

    /** @var array<string, string> */
    public const STATUSES = ['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Turned down', 'withdrawn' => 'Withdrawn'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'workspace_id', 'approval_rule_id', 'subject', 'approvable_type', 'approvable_id', 'title', 'amount', 'currency_code',
        'status', 'requested_by', 'note', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return ['amount' => 'float', 'decided_at' => 'datetime'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ApprovalRule::class, 'approval_rule_id');
    }

    /** The document waiting on the decision; it may since have been deleted. */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function subjectLabel(): string
    {
        return ApprovalRule::SUBJECTS[$this->subject]['label'] ?? $this->subject;
    }

    public function amountLabel(): ?string
    {
        return $this->amount === null ? null : Money::format($this->amount, $this->currency_code);
    }

    /** Whether this person may say yes or no. Nobody decides their own request, unless they own the workspace. */
    public function canBeDecidedBy(User $user): bool
    {
        if (! $this->isPending()) {
            return false;
        }
        $workspace = $this->workspace;
        if ($user->id === $this->requested_by && ! $user->isOwnerOf($workspace)) {
            return false;
        }

        return $this->rule ? $this->rule->allowsApprovalBy($user) : $user->isAdminOf($workspace);
    }
}
