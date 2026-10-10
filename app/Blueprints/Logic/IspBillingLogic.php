<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ISP / WISP billing: an active subscriber needs a PPPoE username, and usernames and IP addresses are used once.
 * Activating a subscriber records the install date and pays them up for the first month. Recording a payment
 * moves the paid-until date on by the months paid and restores a suspended service. Subscribers more than 3
 * days past their paid-until date are suspended each morning. Faults are only logged for live subscribers, a
 * technician is named before one is sent, and the report shows how long each kind of fault took to resolve.
 */
class IspBillingLogic extends AppLogic
{
    /**
     * Days after the paid-until date before the service is suspended.
     */
    public const GRACE_DAYS = 3;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'faults') {
            if (! $existing && filled($data['subscriber'] ?? null) && ($subscriber = $this->records('subscribers')->find($data['subscriber'])) && $subscriber->status === 'cancelled') {
                $errors['data.subscriber'] = $subscriber->title.' has cancelled.';
            }
            if ($payload['status'] === 'technician_sent' && blank($payload['assignee_id'] ?? null)) {
                $errors['assignee_id'] = 'Say which technician was sent.';
            }

            return $errors;
        }
        if ($payload['status'] === 'active' && blank($data['username'] ?? null)) {
            $errors['data.username'] = 'Give the PPPoE username.';
        }
        if (filled($data['ip_address'] ?? null) && ! filter_var($data['ip_address'], FILTER_VALIDATE_IP)) {
            $errors['data.ip_address'] = 'This isn\'t a valid IP address.';
        }
        $others = $this->records('subscribers')->where('status', '!=', 'cancelled')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get();
        foreach (['username' => 'username', 'ip_address' => 'IP address'] as $field => $label) {
            $value = strtolower(trim((string) ($data[$field] ?? '')));
            if ($value !== '' && ! isset($errors['data.'.$field]) && ($other = $others->first(fn (Record $subscriber) => strtolower(trim((string) $subscriber->value($field))) === $value))) {
                $errors['data.'.$field] = 'This '.$label.' is already used by '.$other->title.'.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'faults') {
            $record->occurs_on ??= today();
            if ($record->status === 'resolved' && blank($record->value('_resolved_on'))) {
                $this->put($record, ['_resolved_on' => today()->toDateString()]);
            }

            return;
        }
        if (filled($record->value('username'))) {
            $this->put($record, ['username' => strtolower(trim((string) $record->value('username')))]);
        }
        if ($record->status === 'active') {
            $record->occurs_on ??= today();
            $record->due_on ??= $record->occurs_on->copy()->addMonthNoOverflow();
        }
    }

    public function daily(Workspace $workspace): int
    {
        $lapsed = $this->records('subscribers')->where('status', 'active')->whereNotNull('due_on')->whereDate('due_on', '<', today()->subDays(self::GRACE_DAYS)->toDateString())->get();
        $lapsed->each(fn (Record $subscriber) => $subscriber->update(['status' => 'suspended']));

        return $lapsed->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'faults') {
            return match ($record->status) {
                'logged' => ['investigate' => ['label' => 'Investigate', 'icon' => 'search'], 'resolve' => ['label' => 'Resolved', 'icon' => 'check']],
                'investigating' => ['send' => ['label' => 'Send technician', 'icon' => 'wrench', 'fields' => [['name' => 'technician', 'label' => 'Technician', 'type' => 'select', 'options' => $this->staff($record)]]], 'resolve' => ['label' => 'Resolved', 'icon' => 'check']],
                'technician_sent' => ['resolve' => ['label' => 'Resolved', 'icon' => 'check']],
                default => [],
            };
        }
        $pay = ['pay' => ['label' => 'Record payment', 'icon' => 'banknote', 'fields' => [['name' => 'months', 'label' => 'Months paid', 'type' => 'number', 'value' => 1]]]];

        return match ($record->status) {
            'pending_install' => ['activate' => ['label' => 'Activate', 'icon' => 'wifi', 'fields' => [['name' => 'username', 'label' => 'PPPoE username', 'type' => 'text', 'value' => $record->value('username')]]]],
            'active' => [...$pay, 'cancel' => ['label' => 'Cancel service', 'icon' => 'x']],
            'suspended' => [...$pay, 'cancel' => ['label' => 'Cancel service', 'icon' => 'x']],
            default => [],
        };
    }

    /**
     * The workspace members by id.
     *
     * @return array<int, string>
     */
    protected function staff(Record $record): array
    {
        return Workspace::query()->find($record->workspace_id)?->members()->orderBy('users.name')->pluck('users.name', 'users.id')->all() ?? [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'activate':
                $username = $request->validate(['username' => ['required', 'string', 'max:100']])['username'];
                $taken = $this->records('subscribers')->where('status', '!=', 'cancelled')->whereKeyNot($record->id)->get()->first(fn (Record $subscriber) => $subscriber->value('username') === strtolower(trim($username)));
                if ($taken) {
                    throw ValidationException::withMessages(['username' => 'This username is already used by '.$taken->title.'.']);
                }
                $record->update(['status' => 'active', 'data' => [...$record->data, 'username' => $username]]);

                return $record->title.' is connected, paid until '.$record->due_on->format('d M Y').'.';
            case 'pay':
                $months = (int) $request->validate(['months' => ['required', 'integer', 'min:1', 'max:24']])['months'];
                $from = $record->due_on && $record->due_on->gte(today()) ? $record->due_on : today();
                $record->update(['status' => 'active', 'due_on' => $from->copy()->addMonthsNoOverflow($months)]);

                return $record->title.' paid until '.$record->due_on->format('d M Y').'.';
            case 'cancel':
                $record->update(['status' => 'cancelled']);

                return $record->title.'\'s service cancelled.';
            case 'investigate':
                $record->update(['status' => 'investigating']);

                return $record->title.' is being investigated.';
            case 'send':
                $technician = (int) $request->validate(['technician' => ['required', 'integer']])['technician'];
                $record->update(['status' => 'technician_sent', 'assignee_id' => $technician]);

                return 'Technician sent for '.$record->title.'.';
            default:
                $record->update(['status' => 'resolved']);

                return $record->title.' resolved.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'subscribers') {
            return [];
        }

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Faults', 'icon' => 'router', 'empty' => 'No faults logged.',
            'rows' => $this->linked('faults', 'subscriber', $record)->latest('occurs_on')->latest('id')->limit(8)->get()
                ->map(fn (Record $fault) => ['label' => ucfirst(str_replace('_', ' ', (string) $fault->value('type'))), 'sub' => $fault->occurs_on?->format('d M Y'), 'value' => str_replace('_', ' ', $fault->status), 'href' => $fault->url(), 'tone' => $fault->status === 'resolved' ? null : 'warning'])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $live = $this->records('subscribers')->whereIn('status', ['active', 'suspended'])->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Payment due in 7 days', 'icon' => 'calendar-clock', 'empty' => 'No payments due this week.',
                'rows' => $live->where('status', 'active')->filter(fn (Record $subscriber) => $subscriber->due_on && $subscriber->due_on->lte(today()->addDays(7)))->sortBy('due_on')
                    ->map(fn (Record $subscriber) => ['label' => $subscriber->title, 'sub' => $subscriber->value('package'), 'value' => $subscriber->due_on->format('d M'), 'href' => $subscriber->url(), 'tone' => $subscriber->due_on->lt(today()) ? 'danger' : null])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Network', 'icon' => 'wifi', 'stats' => [
                ['label' => 'Active', 'value' => $live->where('status', 'active')->count()],
                ['label' => 'Suspended', 'value' => $live->where('status', 'suspended')->count()],
                ['label' => 'Monthly revenue', 'value' => $this->money($live->where('status', 'active')->sum('amount'))],
                ['label' => 'Open faults', 'value' => $this->records('faults')->where('status', '!=', 'resolved')->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $subscribers = $this->records('subscribers')->where('status', 'active')->get();
        $faults = $this->dated('faults', $from, $to)->get();

        return [
            ['title' => 'Active subscribers by package', 'columns' => ['Package', 'Subscribers', 'Monthly revenue'], 'rows' => $subscribers
                ->groupBy(fn (Record $subscriber) => (string) $subscriber->value('package'))->sortKeys()
                ->map(fn ($group, string $package) => [$package, $group->count(), $this->money($group->sum('amount'))])
                ->values()->all()],
            ['title' => 'Faults by type', 'columns' => ['Type', 'Logged', 'Resolved', 'Average days to resolve'], 'rows' => $faults
                ->groupBy(fn (Record $fault) => ucfirst(str_replace('_', ' ', (string) $fault->value('type'))))->sortKeys()
                ->map(function ($group, string $type) {
                    $resolved = $group->filter(fn (Record $fault) => $fault->status === 'resolved' && filled($fault->value('_resolved_on')));

                    return [$type, $group->count(), $resolved->count(), $resolved->count() ? round($resolved->avg(fn (Record $fault) => $fault->occurs_on->diffInDays(Carbon::parse($fault->value('_resolved_on')))), 1) : '—'];
                })->values()->all()],
        ];
    }
}
