<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Models\Invoice;

/**
 * Subscription billing: a subscription charges its plan's price times the quantity, raises an
 * invoice on each next-bill date and moves the date on by the plan's interval. An overdue
 * invoice puts it past due; paying up makes it active again. Trials turn active on their
 * first bill date.
 */
class RecurringBillingLogic extends AppLogic
{
    public const BILLABLE = ['trial', 'active', 'past_due'];

    /** Months per interval, for monthly recurring revenue. */
    public const PER_MONTH = ['weekly' => 52 / 12, 'monthly' => 1, 'quarterly' => 1 / 3, 'yearly' => 1 / 12];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'subscriptions' || empty($payload['data']['plan'])) {
            return [];
        }

        $plan = $this->records('plans')->find($payload['data']['plan']);
        $switching = ! $existing || (int) $existing->value('plan') !== (int) $payload['data']['plan'];
        if ($plan && $plan->status === 'retired' && $switching) {
            return ['data.plan' => 'The '.$plan->title.' plan is retired. Pick an active plan.'];
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'subscriptions') {
            return;
        }

        $plan = $this->parent($record, 'plan');
        if ($plan) {
            $record->amount = round($this->number($plan, 'price') * max(1, $this->number($record, 'quantity')), 2);
            $this->put($record, ['_interval' => $plan->value('interval')]);
        }
        if (in_array($record->status, self::BILLABLE, true) && ! $record->due_on) {
            $record->due_on = $record->occurs_on ?? today();
        }
    }

    /** Monthly recurring revenue from one subscription. */
    public function monthly(Record $subscription): float
    {
        return round((float) $subscription->amount * (self::PER_MONTH[$subscription->value('_interval')] ?? 1), 2);
    }

    public function nextDate(Carbon $date, ?string $interval): Carbon
    {
        return match ($interval) {
            'weekly' => $date->copy()->addWeek(),
            'quarterly' => $date->copy()->addMonthsNoOverflow(3),
            'yearly' => $date->copy()->addYearNoOverflow(),
            default => $date->copy()->addMonthNoOverflow(),
        };
    }

    /** Invoice the cycle starting on the next bill date and move the date on. */
    public function billCycle(Record $subscription): Invoice
    {
        $start = $subscription->due_on->copy();
        $next = $this->nextDate($start, $subscription->value('_interval'));
        $plan = $this->parent($subscription, 'plan');
        $quantity = max(1, $this->number($subscription, 'quantity'));

        $invoice = $this->billing()->invoice($subscription, [[
            'description' => ($plan?->title ?? $subscription->title).' · '.$start->format('d M Y').' to '.$next->copy()->subDay()->format('d M Y'),
            'quantity' => $quantity, 'unit_price' => round((float) $subscription->amount / $quantity, 2),
        ]], $start->toDateString(), true, today(), $start->max(today()));

        $subscription->update(['due_on' => $next, 'status' => $subscription->status === 'trial' ? 'active' : $subscription->status]);

        return $invoice;
    }

    public function daily(Workspace $workspace): int
    {
        if (! $this->billing()->available()) {
            return 0;
        }

        $billed = 0;
        foreach ($this->records('subscriptions')->whereIn('status', self::BILLABLE)->whereDate('due_on', '<=', today())->get() as $subscription) {
            for ($cycles = 0; $cycles < 12 && $subscription->due_on && $subscription->due_on->lte(today()); $cycles++) {
                try {
                    $this->billCycle($subscription);
                    $billed++;
                } catch (ValidationException) {
                    break;
                }
            }
        }

        return $billed;
    }

    public function invoiceChanged(Record $record, Invoice $invoice): void
    {
        if ($record->entity !== 'subscriptions') {
            return;
        }

        if ($invoice->status === 'overdue' && $record->status === 'active') {
            $record->update(['status' => 'past_due']);
        } elseif ($record->status === 'past_due' && ! $this->hasOverdueInvoices($record)) {
            $record->update(['status' => 'active']);
        }
    }

    /** Invoices marked overdue, or still open past their due date. */
    protected function hasOverdueInvoices(Record $subscription): bool
    {
        return $subscription->invoices()->whereIn('status', Invoice::OPEN_STATUSES)
            ->where(fn ($query) => $query->where('status', 'overdue')->orWhereDate('due_date', '<', today()))->exists();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'subscriptions' && in_array($record->status, self::BILLABLE, true) && $this->billing()->available()) {
            return ['bill_now' => ['label' => 'Bill next period now', 'icon' => 'receipt', 'confirm' => 'Invoice the period starting '.($record->due_on ?? today())->format('d M Y').' now?']];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if (! $record->due_on) {
            $record->save();
        }
        $invoice = $this->billCycle($record);

        return 'Invoice '.$invoice->number.' raised. Next bill date '.$record->fresh()->due_on->format('d M Y').'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'plans') {
            $subscriptions = $this->linked('subscriptions', 'plan', $record)->whereIn('status', self::BILLABLE)->get();

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Subscribers', 'icon' => 'repeat', 'stats' => [
                ['label' => 'Subscribers', 'value' => (string) $subscriptions->count()],
                ['label' => 'Monthly recurring revenue', 'value' => $this->money($subscriptions->sum(fn (Record $subscription) => $this->monthly($subscription)))],
            ]]]];
        }

        if ($record->entity === 'subscriptions') {
            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Billing', 'icon' => 'repeat', 'stats' => [
                ['label' => 'Each '.str_replace('ly', '', (string) ($record->value('_interval') ?: 'month')), 'value' => $this->money($record->amount)],
                ['label' => 'Per month', 'value' => $this->money($this->monthly($record))],
                ['label' => 'Next bill', 'value' => $record->due_on?->format('d M Y') ?? '—'],
                ['label' => 'Owing', 'value' => $this->money($this->owingFor([$record->id])), 'tone' => $record->status === 'past_due' ? 'danger' : null],
            ]]]];
        }

        return [];
    }

    public function homeCards(): array
    {
        $live = $this->records('subscriptions')->whereIn('status', self::BILLABLE)->get();
        $pastDue = $live->where('status', 'past_due');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Subscriptions', 'icon' => 'repeat', 'stats' => [
                ['label' => 'Monthly recurring revenue', 'value' => $this->money($live->whereIn('status', ['active', 'past_due'])->sum(fn (Record $subscription) => $this->monthly($subscription)))],
                ['label' => 'Active', 'value' => (string) $live->where('status', 'active')->count()],
                ['label' => 'On trial', 'value' => (string) $live->where('status', 'trial')->count()],
                ['label' => 'Past due', 'value' => (string) $pastDue->count(), 'tone' => $pastDue->isNotEmpty() ? 'danger' : null],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $subscriptions = $this->records('subscriptions')->get();
        $plans = $this->records('plans')->orderBy('title')->get()->map(function (Record $plan) use ($subscriptions) {
            $live = $subscriptions->filter(fn (Record $subscription) => (int) $subscription->value('plan') === $plan->id && in_array($subscription->status, ['active', 'past_due'], true));

            return [$plan->title, ucfirst((string) $plan->value('interval')), $this->money($plan->value('price')), $live->count(), $this->money($live->sum(fn (Record $subscription) => $this->monthly($subscription)))];
        })->all();

        $started = $subscriptions->filter(fn (Record $subscription) => ($subscription->occurs_on ?? $subscription->created_at)->between($from, $to));
        $cancelled = $subscriptions->filter(fn (Record $subscription) => $subscription->status === 'cancelled' && $subscription->updated_at->between($from, $to));

        return [
            ['title' => 'Revenue by plan', 'columns' => ['Plan', 'Interval', 'Price', 'Paying subscribers', 'Monthly recurring revenue'], 'rows' => $plans],
            ['title' => 'Growth', 'columns' => ['Measure', 'Count'], 'rows' => [['New subscriptions', $started->count()], ['Cancelled', $cancelled->count()], ['Net change', $started->count() - $cancelled->count()]]],
        ];
    }
}
