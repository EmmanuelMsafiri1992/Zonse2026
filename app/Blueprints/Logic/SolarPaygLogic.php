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
 * Solar energy & PAYG systems: each controller serial is used once, a pay-as-you-go system needs a daily rate,
 * and the balance can't be more than the contract value. A payment cuts the balance and buys days of power at
 * the daily rate, issuing a new unlock code; paying the balance off unlocks the system for good. Systems that
 * run past their paid-until date are locked each morning, and systems locked for 30 days can be repossessed.
 * Generation readings flag weak batteries.
 */
class SolarPaygLogic extends AppLogic
{
    /**
     * Battery health percentage below which a reading raises an alert.
     */
    public const WEAK_BATTERY = 60;

    /**
     * Days a system is locked before it can be repossessed.
     */
    public const REPOSSESS_AFTER_DAYS = 30;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'readings') {
            if (! $existing && filled($data['system'] ?? null) && ($system = $this->records('systems')->find($data['system'])) && $system->status === 'repossessed') {
                $errors['data.system'] = $system->title.'\'s system has been repossessed.';
            }
            if (filled($data['battery_health'] ?? null) && ((float) $data['battery_health'] < 0 || (float) $data['battery_health'] > 100)) {
                $errors['data.battery_health'] = 'Battery health is a percentage from 0 to 100.';
            }

            return $errors;
        }
        if (! empty($data['payg']) && (float) ($data['daily_rate'] ?? 0) <= 0) {
            $errors['data.daily_rate'] = 'Give the daily rate for a pay-as-you-go system.';
        }
        if (filled($payload['amount'] ?? null) && (float) ($data['balance'] ?? 0) > (float) $payload['amount']) {
            $errors['data.balance'] = 'The balance is more than the contract value.';
        }
        $serial = strtoupper(trim((string) ($data['serial_number'] ?? '')));
        if ($serial !== '' && ($other = $this->records('systems')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->first(fn (Record $system) => strtoupper(trim((string) $system->value('serial_number'))) === $serial))) {
            $errors['data.serial_number'] = 'This controller is already on '.$other->title.'.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'readings') {
            $record->occurs_on ??= today();
            if (($system = $this->parent($record, 'system'))) {
                $record->title = $system->title;
            }
            if (filled($record->value('battery_health')) && $this->number($record, 'battery_health') < self::WEAK_BATTERY && blank($record->value('alerts'))) {
                $this->put($record, ['alerts' => 'Battery health low']);
            }

            return;
        }
        $record->occurs_on ??= today();
        if (filled($record->value('serial_number'))) {
            $this->put($record, ['serial_number' => strtoupper(trim((string) $record->value('serial_number')))]);
        }
        if ($record->status === 'locked' && blank($record->value('_locked_on'))) {
            $this->put($record, ['_locked_on' => today()->toDateString()]);
        } elseif ($record->status !== 'locked' && filled($record->value('_locked_on'))) {
            $this->put($record, ['_locked_on' => null]);
        }
    }

    public function daily(Workspace $workspace): int
    {
        $lapsed = $this->records('systems')->where('status', 'active')->whereNotNull('due_on')->whereDate('due_on', '<', today()->toDateString())->get()
            ->filter(fn (Record $system) => $system->value('payg'));
        $lapsed->each(fn (Record $system) => $system->update(['status' => 'locked']));

        return $lapsed->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'systems' || in_array($record->status, ['repossessed', 'paid_off'], true)) {
            return [];
        }
        $actions = ['pay' => ['label' => 'Record payment', 'icon' => 'banknote', 'fields' => [['name' => 'amount', 'label' => 'Amount', 'type' => 'number']]]];
        if ($record->status === 'locked' && filled($locked = $record->value('_locked_on')) && Carbon::parse($locked)->lte(today()->subDays(self::REPOSSESS_AFTER_DAYS))) {
            $actions['repossess'] = ['label' => 'Repossess', 'icon' => 'undo-2'];
        }

        return $actions;
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'repossess') {
            if (! isset($this->actions($record)['repossess'])) {
                throw ValidationException::withMessages(['status' => 'A system can only be repossessed after '.self::REPOSSESS_AFTER_DAYS.' days locked.']);
            }
            $record->update(['status' => 'repossessed']);

            return $record->title.'\'s system repossessed.';
        }
        $amount = round((float) $request->validate(['amount' => ['required', 'numeric', 'min:0.01']])['amount'], 2);
        $balance = round(max(0, $this->number($record, 'balance') - $amount), 2);
        if ($balance <= 0) {
            $record->update(['status' => 'paid_off', 'data' => [...$record->data, 'balance' => 0, 'unlock_code' => $this->unlockCode($record)]]);

            return $record->title.' has paid off the system; it is unlocked for good.';
        }
        $days = $record->value('payg') && $this->number($record, 'daily_rate') > 0 ? (int) floor($amount / $this->number($record, 'daily_rate')) : 0;
        $from = $record->due_on && $record->due_on->gte(today()) ? $record->due_on : today();
        $record->update([
            'status' => 'active',
            'due_on' => $days ? $from->copy()->addDays($days) : $record->due_on,
            'data' => [...$record->data, 'balance' => $balance, ...($days ? ['unlock_code' => $this->unlockCode($record)] : [])],
        ]);

        return $days
            ? $record->title.' paid '.$this->money($amount).' for '.$days.' '.str('day')->plural($days).', code '.$record->value('unlock_code').'.'
            : $record->title.' paid '.$this->money($amount).', '.$this->money($balance).' left.';
    }

    /**
     * A new eight-digit unlock code for the system.
     */
    protected function unlockCode(Record $record): string
    {
        return str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'systems') {
            return [];
        }
        $readings = $this->linked('readings', 'system', $record)->whereDate('occurs_on', '>=', today()->subDays(30)->toDateString())->get();
        $latest = $this->linked('readings', 'system', $record)->latest('occurs_on')->latest('id')->first();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Account', 'icon' => 'sun', 'stats' => [
            ['label' => 'Balance', 'value' => $this->money($record->value('balance'))],
            ['label' => 'Paid until', 'value' => $record->due_on?->format('d M Y') ?? '—'],
            ['label' => 'Generated, 30 days', 'value' => round($readings->sum(fn (Record $reading) => $this->number($reading, 'kwh')), 1).' kWh'],
            ['label' => 'Battery health', 'value' => $latest && filled($latest->value('battery_health')) ? (int) $latest->value('battery_health').'%' : '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        $systems = $this->records('systems')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Locked systems', 'icon' => 'lock', 'empty' => 'No systems locked.',
                'rows' => $systems->where('status', 'locked')->sortBy('due_on')
                    ->map(fn (Record $system) => ['label' => $system->title, 'sub' => $system->value('kit'), 'value' => $this->money($system->value('balance')), 'href' => $system->url(), 'tone' => isset($this->actions($system)['repossess']) ? 'danger' : 'warning'])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Portfolio', 'icon' => 'sun', 'stats' => [
                ['label' => 'Active', 'value' => $systems->where('status', 'active')->count()],
                ['label' => 'Paid off', 'value' => $systems->where('status', 'paid_off')->count()],
                ['label' => 'Outstanding', 'value' => $this->money($systems->whereIn('status', ['active', 'locked'])->sum(fn (Record $system) => $this->number($system, 'balance')))],
                ['label' => 'Weak batteries this week', 'value' => $this->records('readings')->whereDate('occurs_on', '>=', today()->subDays(7)->toDateString())->get()->filter(fn (Record $reading) => filled($reading->value('battery_health')) && $this->number($reading, 'battery_health') < self::WEAK_BATTERY)->pluck('data.system')->unique()->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $systems = $this->records('systems')->get();
        $readings = $this->dated('readings', $from, $to)->get();

        return [
            ['title' => 'Portfolio by kit', 'columns' => ['Kit', 'Systems', 'Active', 'Locked', 'Paid off', 'Contract value', 'Outstanding'], 'rows' => $systems
                ->groupBy(fn (Record $system) => trim((string) $system->value('kit')))->sortKeys()
                ->map(fn ($group, string $kit) => [$kit, $group->count(), $group->where('status', 'active')->count(), $group->where('status', 'locked')->count(), $group->where('status', 'paid_off')->count(), $this->money($group->sum('amount')), $this->money($group->whereIn('status', ['active', 'locked'])->sum(fn (Record $system) => $this->number($system, 'balance')))])
                ->values()->all()],
            ['title' => 'Generation', 'columns' => ['System', 'Readings', 'kWh', 'Lowest battery health'], 'rows' => $readings
                ->groupBy('title')->sortKeys()
                ->map(fn ($group, string $system) => [$system, $group->count(), round($group->sum(fn (Record $reading) => $this->number($reading, 'kwh')), 1), ($low = $group->filter(fn (Record $reading) => filled($reading->value('battery_health')))->min(fn (Record $reading) => $this->number($reading, 'battery_health'))) !== null ? (int) $low.'%' : '—'])
                ->values()->all()],
        ];
    }
}
