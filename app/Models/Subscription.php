<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    public const STATUSES = ['trialing', 'active', 'past_due', 'cancelled', 'expired'];

    protected $fillable = [
        'workspace_id', 'plan_id', 'status', 'billing_cycle', 'amount', 'currency', 'gateway',
        'gateway_ref', 'trial_ends_at', 'current_period_start', 'current_period_end',
        'cancelled_at', 'ends_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'amount' => 'decimal:2',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isTrialing(): bool
    {
        return $this->status === 'trialing' && $this->trial_ends_at?->isFuture();
    }

    public function isActive(): bool
    {
        return $this->status === 'active' || $this->isTrialing();
    }

    public function daysLeftInTrial(): int
    {
        return $this->trial_ends_at ? max(0, (int) ceil(now()->diffInDays($this->trial_ends_at, false))) : 0;
    }
}
