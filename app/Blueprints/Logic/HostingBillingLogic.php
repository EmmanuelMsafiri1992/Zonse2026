<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Hosting & domain billing: domain names are stored in lower case without "http://" or "www.", and each
 * domain is listed once. A domain runs a year from registration and a hosting account is due a month after
 * it is created. Each night, domains past their expiry renew for a year when auto-renew is on and expire when
 * it is off, and hosting accounts more than 7 days overdue are suspended. Payment moves the next due date on.
 */
class HostingBillingLogic extends AppLogic
{
    /**
     * Days a hosting account may be overdue before it is suspended.
     */
    public const GRACE_DAYS = 7;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $errors = [];
        if ($entity->key !== 'domains') {
            return $errors;
        }
        $name = $this->domain((string) $payload['title']);
        if (! preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $name)) {
            $errors['title'] = 'Give a domain name like example.com.';
        } elseif ($this->records('domains')->where('title', $name)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
            $errors['title'] = $name.' is already listed.';
        }

        return $errors;
    }

    /**
     * A domain name in its stored form.
     */
    protected function domain(string $name): string
    {
        return preg_replace(['#^https?://#', '#^www\.#', '#/.*$#'], '', strtolower(trim($name)));
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'domains') {
            $record->title = $this->domain((string) $record->title);
            $record->due_on ??= $record->occurs_on->copy()->addYear();

            return;
        }
        $record->title = $this->domain((string) $record->title);
        $record->due_on ??= $record->occurs_on->copy()->addMonthNoOverflow();
    }

    public function daily(Workspace $workspace): int
    {
        $domains = $this->records('domains')->where('status', 'active')->whereDate('due_on', '<', today()->toDateString())->get()
            ->each(fn (Record $domain) => $domain->value('auto_renew')
                ? $domain->update(['due_on' => $this->nextExpiry($domain, 1)])
                : $domain->update(['status' => 'expired']));
        $hosting = $this->records('hosting')->where('status', 'active')->whereDate('due_on', '<', today()->subDays(self::GRACE_DAYS)->toDateString())->get()
            ->each(fn (Record $account) => $account->update(['status' => 'suspended']));

        return $domains->count() + $hosting->count();
    }

    /**
     * A domain's expiry after renewing it for some years, counted from its expiry or from today if it has lapsed.
     */
    protected function nextExpiry(Record $domain, int $years): Carbon
    {
        $from = $domain->due_on ?? today();
        while ($from->lt(today())) {
            $from = $from->copy()->addYear();
            $years--;
        }

        return $from->copy()->addYears(max(0, $years));
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'domains') {
            return in_array($record->status, ['active', 'expired'], true)
                ? ['renew' => ['label' => 'Renew', 'icon' => 'refresh-cw', 'fields' => [['name' => 'years', 'label' => 'Years', 'type' => 'number', 'value' => 1]]]]
                : [];
        }

        return match ($record->status) {
            'active' => ['paid' => ['label' => 'Payment received', 'icon' => 'banknote', 'fields' => [['name' => 'months', 'label' => 'Months paid', 'type' => 'number', 'value' => 1]]], 'suspend' => ['label' => 'Suspend', 'icon' => 'pause']],
            'suspended' => ['paid' => ['label' => 'Paid and reactivate', 'icon' => 'banknote', 'fields' => [['name' => 'months', 'label' => 'Months paid', 'type' => 'number', 'value' => 1]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'renew':
                $years = (int) $request->validate(['years' => ['required', 'integer', 'min:1', 'max:10']])['years'];
                $expiry = $record->due_on && $record->due_on->gte(today()) ? $record->due_on->copy()->addYears($years) : today()->addYears($years);
                $record->update(['status' => 'active', 'due_on' => $expiry]);

                return $record->title.' renewed until '.$expiry->format('d M Y').'.';
            case 'suspend':
                $record->update(['status' => 'suspended']);

                return $record->title.' suspended.';
            default:
                $months = (int) $request->validate(['months' => ['required', 'integer', 'min:1', 'max:36']])['months'];
                $due = ($record->due_on ?? today())->copy()->addMonthsNoOverflow($months);
                $wasSuspended = $record->status === 'suspended';
                $record->update(['status' => 'active', 'due_on' => $due]);

                return $record->title.' paid until '.$due->format('d M Y').($wasSuspended ? ' and reactivated' : '').'.';
        }
    }

    public function homeCards(): array
    {
        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Domains expiring in 30 days', 'icon' => 'globe', 'empty' => 'No domains expiring soon.',
                'rows' => $this->records('domains')->where('status', 'active')->whereDate('due_on', '<=', today()->addDays(30)->toDateString())->orderBy('due_on')->get()
                    ->map(fn (Record $domain) => ['label' => $domain->title, 'sub' => $domain->value('auto_renew') ? 'Auto-renews' : 'Manual renewal', 'value' => $domain->due_on->format('d M Y'), 'href' => $domain->url(), 'tone' => $domain->value('auto_renew') ? null : 'warning'])->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Hosting overdue', 'icon' => 'server', 'empty' => 'No hosting accounts overdue.',
                'rows' => $this->records('hosting')->whereIn('status', ['active', 'suspended'])->whereDate('due_on', '<', today()->toDateString())->orderBy('due_on')->get()
                    ->map(fn (Record $account) => ['label' => $account->title, 'sub' => $account->status, 'value' => 'Due '.$account->due_on->format('d M'), 'href' => $account->url(), 'tone' => 'danger'])->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $domains = $this->records('domains')->where('status', 'active')->whereDate('due_on', '>=', $from->toDateString())->whereDate('due_on', '<=', $to->toDateString())->get();

        return [
            ['title' => 'Hosting by plan', 'columns' => ['Plan', 'Active accounts', 'Suspended', 'Monthly revenue'], 'rows' => $this->records('hosting')->where('status', '!=', 'cancelled')->get()
                ->groupBy(fn (Record $account) => ucfirst(str_replace('_', ' ', (string) $account->value('plan'))))->sortKeys()
                ->map(fn ($group, string $plan) => [$plan, $group->where('status', 'active')->count(), $group->where('status', 'suspended')->count(), $this->money($group->where('status', 'active')->sum('amount'))])
                ->values()->all()],
            ['title' => 'Domain renewals by month', 'columns' => ['Month', 'Domains', 'Renewal value'], 'rows' => collect($this->months($from, $to))
                ->map(function (string $label, string $month) use ($domains) {
                    $inMonth = $domains->filter(fn (Record $domain) => $domain->due_on->format('Y-m') === $month);

                    return [$label, $inMonth->count(), $this->money($inMonth->sum('amount'))];
                })->values()->all()],
        ];
    }
}
