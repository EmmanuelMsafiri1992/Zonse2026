<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class WorkspaceMembership extends Pivot
{
    public const ROLES = ['owner', 'admin', 'manager', 'member', 'viewer'];

    protected $table = 'workspace_user';

    public $incrementing = true;

    protected $fillable = ['workspace_id', 'user_id', 'branch_id', 'role', 'job_title', 'invited_by', 'joined_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }
}
