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
 * Parking management: registrations are kept in capitals without spaces, so the same car always matches.
 * A vehicle holds one live permit at a time and can't be parked twice at once. A car with a permit for the
 * day parks free; anyone else pays a fee, before leaving or on the way out. Each night permits past their
 * end date expire, and cars still marked parked from an earlier day are flagged unpaid.
 */
class ParkingLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'permits') {
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The permit must end after it starts.';
            }
            $registration = $this->plate($data['registration'] ?? '');
            if ($registration !== '' && $payload['status'] === 'active' && ($other = $this->records('permits')->where('status', 'active')->where('data->registration', $registration)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first())) {
                $errors['data.registration'] = $registration.' already has a permit for '.$other->title.'.';
            }
        }
        if ($entity->key === 'sessions') {
            if (filled($data['exit_time'] ?? null) && filled($data['entry_time'] ?? null) && $data['exit_time'] <= $data['entry_time']) {
                $errors['data.exit_time'] = 'The exit must be after the entry.';
            }
            $registration = $this->plate($payload['title'] ?? '');
            if ($registration !== '' && in_array($payload['status'], ['parked', 'paid'], true) && $this->records('sessions')->whereIn('status', ['parked', 'paid'])->where('title', $registration)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                $errors['title'] = $registration.' is already parked.';
            }
        }

        return $errors;
    }

    /**
     * A registration in capitals without spaces or dashes.
     */
    public function plate(?string $registration): string
    {
        return strtoupper(preg_replace('/[\s\-]+/', '', (string) $registration));
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'permits') {
            $this->put($record, ['registration' => $this->plate($record->value('registration'))]);
            if ($record->status === 'active' && $record->due_on && $record->due_on->lt(today())) {
                $record->status = 'expired';
            }

            return;
        }
        $record->title = $this->plate($record->title);
        $permit = $this->permitFor($record->title, $record->occurs_on);
        $entry = $record->value('entry_time');
        $exit = $record->value('exit_time');
        $this->put($record, [
            '_permit' => $permit?->title,
            '_minutes' => $entry && $exit ? (int) Carbon::parse($entry)->diffInMinutes(Carbon::parse($exit)) : null,
        ]);
        if ($permit) {
            $record->amount = 0;
        }
    }

    /**
     * The live permit covering a registration on a day.
     */
    protected function permitFor(string $registration, Carbon $day): ?Record
    {
        return $this->records('permits')->where('status', 'active')->where('data->registration', $registration)->get()
            ->first(fn (Record $permit) => (! $permit->occurs_on || $permit->occurs_on->lte($day)) && (! $permit->due_on || $permit->due_on->gte($day)));
    }

    public function daily(Workspace $workspace): int
    {
        $permits = $this->records('permits')->where('status', 'active')->whereDate('due_on', '<', today()->toDateString())->get()->each(fn (Record $permit) => $permit->save());
        $stale = $this->records('sessions')->where('status', 'parked')->whereDate('occurs_on', '<', today()->toDateString())->get()->each(fn (Record $session) => $session->update(['status' => 'unpaid']));

        return $permits->count() + $stale->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'sessions') {
            return [];
        }
        $pay = ['pay' => ['label' => 'Paid', 'icon' => 'banknote', 'fields' => [['name' => 'amount', 'label' => 'Fee', 'type' => 'number', 'value' => $record->amount]]]];
        $exit = ['exit' => ['label' => 'Exited', 'icon' => 'log-out', 'fields' => [['name' => 'exit_time', 'label' => 'Exit time', 'type' => 'time', 'value' => now()->format('H:i')]]]];

        return match ($record->status) {
            'parked' => $record->value('_permit') ? $exit : [...$pay, ...$exit],
            'paid' => $exit,
            'unpaid' => $pay,
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'pay') {
            $fee = (float) $request->validate(['amount' => ['required', 'numeric', 'gt:0']])['amount'];
            $record->update(['amount' => $fee, 'status' => $record->status === 'unpaid' && $record->value('exit_time') ? 'exited' : 'paid']);

            return $record->title.' paid '.$this->money($fee).'.';
        }
        $exit = $request->validate(['exit_time' => ['required', 'date_format:H:i']])['exit_time'];
        if ($record->value('entry_time') && $exit <= $record->value('entry_time')) {
            throw ValidationException::withMessages(['exit_time' => 'The exit must be after the entry at '.$record->value('entry_time').'.']);
        }
        $owes = $record->status === 'parked' && ! $record->value('_permit');
        $record->update(['status' => $owes ? 'unpaid' : 'exited', 'data' => [...$record->data, 'exit_time' => $exit]]);
        $stay = (int) $record->value('_minutes');

        return $record->title.' left after '.intdiv($stay, 60).'h '.str_pad((string) ($stay % 60), 2, '0', STR_PAD_LEFT).($record->value('_permit') ? ', on permit.' : ($owes ? ' without paying.' : '.'));
    }

    public function homeCards(): array
    {
        $parked = $this->records('sessions')->whereIn('status', ['parked', 'paid'])->count();
        $unpaid = $this->records('sessions')->where('status', 'unpaid')->orderByDesc('occurs_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Parking', 'icon' => 'square-parking', 'stats' => [
                ['label' => 'Parked now', 'value' => $parked],
                ['label' => 'Active permits', 'value' => $this->records('permits')->where('status', 'active')->count()],
                ['label' => 'Fees today', 'value' => $this->money($this->records('sessions')->whereIn('status', ['paid', 'exited'])->whereDate('occurs_on', today()->toDateString())->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Unpaid', 'icon' => 'alert-triangle', 'empty' => 'No unpaid parking.',
                'rows' => $unpaid->map(fn (Record $session) => ['label' => $session->title, 'sub' => $session->value('zone'), 'value' => $session->occurs_on->format('d M'), 'href' => $session->url(), 'tone' => 'danger'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $sessions = $this->dated('sessions', $from, $to)->get();

        return [['title' => 'Sessions by zone', 'columns' => ['Zone', 'Sessions', 'On permit', 'Hours', 'Fees', 'Unpaid'], 'rows' => $sessions
            ->groupBy(fn (Record $session) => (string) ($session->value('zone') ?: 'No zone'))->sortKeys()
            ->map(fn ($group, string $zone) => [$zone, $group->count(), $group->filter(fn (Record $session) => $session->value('_permit'))->count(),
                round($group->sum(fn (Record $session) => $this->number($session, '_minutes')) / 60, 1),
                $this->money($group->whereIn('status', ['paid', 'exited'])->sum(fn (Record $session) => (float) $session->amount)), $group->where('status', 'unpaid')->count()])
            ->values()->all()]];
    }
}
