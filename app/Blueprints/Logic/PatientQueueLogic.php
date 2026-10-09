<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Patient queue: each walk-in gets a token numbered per service and per day (C-001, P-002 …).
 * Emergencies are called before the elderly and the elderly before everyone else, otherwise first
 * come first served. A token is called to a room, served and finished, its waiting time is kept,
 * and tokens still waiting at the end of the day become no-shows.
 */
class PatientQueueLogic extends AppLogic
{
    /** @var array<string, string> */
    public const LETTERS = ['consultation' => 'C', 'pharmacy' => 'P', 'laboratory' => 'L', 'dressing' => 'D', 'vaccination' => 'V'];

    /** @var array<string, int> */
    public const PRIORITY = ['emergency' => 0, 'elderly' => 1, 'normal' => 2];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $errors = [];
        if (in_array($payload['status'], ['called', 'in_service'], true) && blank($payload['data']['room'] ?? null)) {
            $errors['data.room'] = 'Say which room or counter.';
        }
        if (! $existing && filled($payload['occurs_on'] ?? null) && ! Carbon::parse($payload['occurs_on'])->isToday()) {
            $errors['occurs_on'] = 'Tokens are issued for today.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        $service = (string) ($record->value('service') ?: 'consultation');

        if (blank($record->value('_number'))) {
            $issued = $this->records('tokens')->whereDate('occurs_on', $record->occurs_on)->where('data->service', $service)->count();
            $this->put($record, [
                'service' => $service,
                '_number' => (self::LETTERS[$service] ?? 'Q').'-'.str_pad((string) ($issued + 1), 3, '0', STR_PAD_LEFT),
                '_queued_at' => now()->toDateTimeString(),
            ]);
        }
        $this->put($record, ['priority' => $record->value('priority') ?: 'normal']);

        $called = $record->value('_called_at');
        if (in_array($record->status, ['called', 'in_service', 'done'], true) && blank($called)) {
            $called = now()->toDateTimeString();
        }
        $this->put($record, [
            '_called_at' => $record->status === 'waiting' ? null : $called,
            '_wait_minutes' => filled($called) ? (int) Carbon::parse($record->value('_queued_at'))->diffInMinutes(Carbon::parse($called)) : null,
            '_done_at' => $record->status === 'done' ? ($record->value('_done_at') ?? now()->toDateTimeString()) : null,
        ]);
    }

    /**
     * Today's waiting tokens in the order they will be called.
     *
     * @return Collection<int, Record>
     */
    protected function waiting(?string $service = null): Collection
    {
        return $this->records('tokens')->whereDate('occurs_on', today())->where('status', 'waiting')->get()
            ->when($service, fn (Collection $tokens) => $tokens->filter(fn (Record $token) => $token->value('service') === $service))
            ->sortBy(fn (Record $token) => sprintf('%d-%s-%010d', self::PRIORITY[$token->value('priority')] ?? 2, $token->value('_queued_at'), $token->id))
            ->values();
    }

    public function daily(Workspace $workspace): int
    {
        $missed = 0;
        foreach ($this->records('tokens')->whereDate('occurs_on', '<', today())->whereIn('status', ['waiting', 'called'])->get() as $token) {
            $token->update(['status' => 'no_show']);
            $missed++;
        }

        return $missed;
    }

    public function actions(Record $record): array
    {
        $room = ['name' => 'room', 'label' => 'Room / counter', 'type' => 'text', 'value' => $record->value('room')];

        return match ($record->status) {
            'waiting' => ['call' => ['label' => 'Call', 'icon' => 'megaphone', 'fields' => [$room]], 'no_show' => ['label' => 'No-show', 'icon' => 'user-x']],
            'called' => ['start' => ['label' => 'Start', 'icon' => 'play'], 'recall' => ['label' => 'Call again', 'icon' => 'megaphone'], 'no_show' => ['label' => 'No-show', 'icon' => 'user-x']],
            'in_service' => ['done' => ['label' => 'Done', 'icon' => 'check']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $number = $record->value('_number');

        switch ($action) {
            case 'call':
                $room = $request->validate(['room' => ['required', 'string']])['room'];
                $next = $this->waiting($record->value('service'))->first();
                if ($next && $next->id !== $record->id && (self::PRIORITY[$next->value('priority')] ?? 2) < (self::PRIORITY[$record->value('priority')] ?? 2)) {
                    throw ValidationException::withMessages(['status' => $next->value('_number').' ('.$next->value('priority').') is ahead in the queue.']);
                }
                $record->update(['status' => 'called', 'data' => [...$record->data, 'room' => $room]]);

                return $number.' to '.$room.'.';
            case 'recall':
                return $number.' to '.$record->value('room').' again.';
            case 'start':
                $record->update(['status' => 'in_service']);

                return 'Serving '.$number.'.';
            case 'done':
                $record->update(['status' => 'done']);

                return $number.' done.';
        }

        $record->update(['status' => 'no_show']);

        return $number.' marked as a no-show.';
    }

    public function homeCards(): array
    {
        $today = $this->records('tokens')->whereDate('occurs_on', today())->get();
        $waiting = $this->waiting();
        $served = $today->filter(fn (Record $token) => $token->value('_wait_minutes') !== null);
        $serving = $today->whereIn('status', ['called', 'in_service'])->sortByDesc(fn (Record $token) => $token->value('_called_at'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Queue today', 'icon' => 'list-ordered', 'stats' => [
                ['label' => 'Waiting', 'value' => (string) $waiting->count()],
                ['label' => 'Emergencies waiting', 'value' => (string) $waiting->filter(fn (Record $token) => $token->value('priority') === 'emergency')->count(), 'tone' => $waiting->contains(fn (Record $token) => $token->value('priority') === 'emergency') ? 'danger' : null],
                ['label' => 'Being seen', 'value' => (string) $serving->count()],
                ['label' => 'Done', 'value' => (string) $today->where('status', 'done')->count()],
                ['label' => 'Average wait', 'value' => $served->isEmpty() ? '—' : (int) round($served->avg(fn (Record $token) => (int) $token->value('_wait_minutes'))).' min'],
                ['label' => 'No-shows', 'value' => (string) $today->where('status', 'no_show')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Now serving', 'icon' => 'megaphone', 'empty' => 'Nobody has been called yet.',
                'rows' => $serving->take(8)->map(fn (Record $token) => [
                    'label' => $token->value('_number').' · '.$token->title, 'sub' => ucfirst((string) $token->value('service')), 'value' => (string) $token->value('room'), 'href' => $token->url(),
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Next in line', 'icon' => 'users', 'empty' => 'Nobody is waiting.',
                'rows' => $waiting->take(10)->map(fn (Record $token) => [
                    'label' => $token->value('_number').' · '.$token->title, 'sub' => ucfirst((string) $token->value('service')), 'value' => (int) Carbon::parse($token->value('_queued_at'))->diffInMinutes(now()).' min', 'href' => $token->url(), 'tone' => $token->value('priority') === 'emergency' ? 'danger' : ($token->value('priority') === 'elderly' ? 'warning' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $tokens = $this->dated('tokens', $from, $to)->get();
        $services = $this->app->entities['tokens']->field('service')?->options ?? [];
        $byService = collect($services)->map(function (string $label, string $service) use ($tokens) {
            $group = $tokens->filter(fn (Record $token) => $token->value('service') === $service);
            $waited = $group->filter(fn (Record $token) => $token->value('_wait_minutes') !== null);

            return [$label, $group->count(), $group->where('status', 'done')->count(), $group->where('status', 'no_show')->count(), $waited->isEmpty() ? '—' : (int) round($waited->avg(fn (Record $token) => (int) $token->value('_wait_minutes'))).' min', $waited->isEmpty() ? '—' : (int) $waited->max(fn (Record $token) => (int) $token->value('_wait_minutes')).' min'];
        })->values()->all();

        $byDay = $tokens->groupBy(fn (Record $token) => $token->occurs_on?->toDateString())->sortKeys()->map(fn (Collection $group, string $day) => [
            Carbon::parse($day)->format('d M Y'), $group->count(), $group->where('status', 'done')->count(), $group->where('status', 'no_show')->count(), $group->filter(fn (Record $token) => $token->value('priority') === 'emergency')->count(),
        ])->values()->all();

        return [
            ['title' => 'Waiting times by service', 'columns' => ['Service', 'Tokens', 'Served', 'No-shows', 'Average wait', 'Longest wait'], 'rows' => $byService],
            ['title' => 'Tokens by day', 'columns' => ['Day', 'Tokens', 'Served', 'No-shows', 'Emergencies'], 'rows' => $byDay],
        ];
    }
}
