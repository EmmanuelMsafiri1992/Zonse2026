<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Support\Notifier;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class SendPlanReminders extends Command
{
    /** Days before a trial or cancelled plan ends that the owner is reminded. */
    public const REMIND_DAYS_BEFORE = [3, 1];

    /** Read notifications older than this are deleted. */
    public const KEEP_READ_DAYS = 90;

    protected $signature = 'zonseo:send-plan-reminders';

    protected $description = 'Remind workspace owners before their free trial or cancelled plan ends, and tidy old read notifications';

    public function handle(): int
    {
        $sent = 0;
        foreach (self::REMIND_DAYS_BEFORE as $days) {
            $day = now()->addDays($days);
            Subscription::query()
                ->where(fn ($q) => $q->where('status', 'trialing')->whereBetween('trial_ends_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]))
                ->orWhere(fn ($q) => $q->where('status', 'cancelled')->whereBetween('ends_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]))
                ->with(['workspace.owner', 'plan'])
                ->each(function (Subscription $subscription) use ($days, &$sent) {
                    $workspace = $subscription->workspace;
                    if (! $workspace?->owner || $workspace->subscription?->id !== $subscription->id) {
                        return;
                    }

                    $when = $days === 1 ? 'tomorrow' : 'in '.$days.' days';
                    $title = $subscription->status === 'trialing'
                        ? 'Your free trial of the '.$subscription->plan?->name.' plan ends '.$when
                        : 'Your '.$subscription->plan?->name.' plan ends '.$when;

                    $sent += Notifier::send(
                        $workspace->owner, 'billing', $title, 'Choose a plan to keep your apps and data working without a break.',
                        route('settings.billing.index'), 'hourglass', $workspace,
                    );
                });
        }

        $pruned = DatabaseNotification::query()->whereNotNull('read_at')->where('read_at', '<', now()->subDays(self::KEEP_READ_DAYS))->delete();

        $this->info($sent.' plan reminders sent, '.$pruned.' old notifications removed.');

        return self::SUCCESS;
    }
}
