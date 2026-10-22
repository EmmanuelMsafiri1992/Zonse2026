<?php

namespace App\Payments;

use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Whether a workspace may use its apps right now, worked out from its latest subscription.
 *
 * - active: a paid period or a free plan; nothing to do.
 * - trial: the free trial is still running (until = when it ends).
 * - grace: the trial or paid period has lapsed but the grace period is still running (until = when it ends).
 * - blocked: the grace period is over; only billing, profile and help pages open.
 *
 * A workspace run by the platform itself (owned by a super admin) is always active.
 *
 * SUBSCRIPTION_ENFORCE_FROM postpones every block until that date, so plans that were confirmed
 * by hand before online payments existed get time to pay.
 */
final readonly class SubscriptionAccess
{
    public function __construct(
        public string $state,
        public ?CarbonInterface $until = null,
    ) {}

    public static function for(Workspace $workspace): self
    {
        $subscription = $workspace->subscription;
        if (! $subscription || $subscription->plan?->isFree() || $workspace->isRunByPlatform()) {
            return new self('active');
        }

        $lapsesAt = match ($subscription->status) {
            'trialing' => $subscription->trial_ends_at,
            'active', 'past_due' => $subscription->current_period_end,
            default => $subscription->ends_at ?? $subscription->current_period_end ?? $subscription->created_at,
        };
        if (! $lapsesAt) {
            return new self('active');
        }

        if ($lapsesAt->isFuture()) {
            return new self($subscription->status === 'trialing' ? 'trial' : 'active', $lapsesAt);
        }

        $graceEnds = $lapsesAt->copy()->addDays(self::graceDays())->endOfDay();
        $enforceFrom = self::enforceFrom();
        if ($enforceFrom && $graceEnds->lt($enforceFrom)) {
            $graceEnds = $enforceFrom;
        }
        if ($graceEnds->isFuture()) {
            return new self('grace', $graceEnds);
        }

        return new self('blocked', $lapsesAt);
    }

    public static function graceDays(): int
    {
        return max(0, (int) config('zonseo.checkout.grace_days', 7));
    }

    public static function enforceFrom(): ?Carbon
    {
        $date = config('zonseo.checkout.enforce_from');

        return $date ? Carbon::parse($date)->startOfDay() : null;
    }

    public function isBlocked(): bool
    {
        return $this->state === 'blocked';
    }

    public function inGrace(): bool
    {
        return $this->state === 'grace';
    }

    public function onTrial(): bool
    {
        return $this->state === 'trial';
    }

    /** Whole days until the trial or grace period ends, never negative. */
    public function daysLeft(): int
    {
        return $this->until ? max(0, (int) ceil(now()->diffInDays($this->until, false))) : 0;
    }
}
