<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Music & recording studio: a session needs a room, a start time and its length, and a room can't hold two
 * sessions that overlap, nor can an engineer run two at once. A session runs booked, in session and completed.
 * Projects move through pre-production, tracking, mixing and mastering to release. The report shows the hours
 * each room was booked and the takings by type of session.
 */
class MusicStudioLogic extends AppLogic
{
    /**
     * The next stage for a project.
     *
     * @var array<string, string>
     */
    public const NEXT = ['pre_production' => 'tracking', 'tracking' => 'mixing', 'mixing' => 'mastering', 'mastering' => 'released'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key !== 'sessions' || $payload['status'] === 'cancelled') {
            return $errors;
        }
        if (blank($data['room'] ?? null)) {
            $errors['data.room'] = 'Give the studio room.';
        }
        if (blank($data['start_time'] ?? null)) {
            $errors['data.start_time'] = 'Give the start time.';
        }
        if ((float) ($data['hours'] ?? 0) <= 0) {
            $errors['data.hours'] = 'Give the length of the session in hours.';
        }
        if ($errors) {
            return $errors;
        }
        [$start, $end] = $this->window((string) $data['start_time'], (float) $data['hours']);
        $date = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on'])->toDateString() : today()->toDateString();
        $others = $this->records('sessions')->whereIn('status', ['booked', 'in_session'])->whereDate('occurs_on', $date)
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
            ->filter(function (Record $other) use ($start, $end) {
                [$otherStart, $otherEnd] = $this->window((string) $other->value('start_time'), $this->number($other, 'hours'));

                return $otherStart < $end && $start < $otherEnd;
            });
        if ($clash = $others->first(fn (Record $other) => strcasecmp(trim((string) $other->value('room')), trim((string) $data['room'])) === 0)) {
            $errors['data.start_time'] = trim((string) $data['room']).' is booked for '.$clash->title.' from '.substr((string) $clash->value('start_time'), 0, 5).'.';
        } elseif (filled($data['engineer'] ?? null) && ($clash = $others->first(fn (Record $other) => (string) $other->value('engineer') === (string) $data['engineer']))) {
            $errors['data.engineer'] = 'The engineer is with '.$clash->title.' from '.substr((string) $clash->value('start_time'), 0, 5).'.';
        }

        return $errors;
    }

    /**
     * A session's start and end as minutes after midnight.
     *
     * @return array{0: int, 1: int}
     */
    protected function window(string $startTime, float $hours): array
    {
        [$hour, $minute] = array_map('intval', explode(':', $startTime.':0'));
        $start = $hour * 60 + $minute;

        return [$start, $start + (int) round($hours * 60)];
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'projects') {
            return isset(self::NEXT[$record->status]) ? ['advance' => ['label' => 'Move to '.str_replace('_', ' ', self::NEXT[$record->status]), 'icon' => 'arrow-right']] : [];
        }

        return match ($record->status) {
            'booked' => ['start' => ['label' => 'Start session', 'icon' => 'play'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'in_session' => ['finish' => ['label' => 'Finish', 'icon' => 'check', 'fields' => [['name' => 'hours', 'label' => 'Hours used', 'type' => 'number', 'value' => $record->value('hours')]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'advance':
                $record->update(['status' => self::NEXT[$record->status]]);

                return $record->title.' moved to '.str_replace('_', ' ', $record->status).'.';
            case 'start':
                $record->update(['status' => 'in_session']);

                return $record->title.' is in session.';
            case 'cancel':
                $record->update(['status' => 'cancelled']);

                return $record->title.'\'s session cancelled.';
            default:
                $hours = (float) $request->validate(['hours' => ['required', 'numeric', 'gt:0']])['hours'];
                $booked = $this->number($record, 'hours');
                $amount = $booked > 0 && $record->amount > 0 ? round($record->amount / $booked * $hours, 2) : $record->amount;
                $record->update(['status' => 'completed', 'amount' => $amount, 'data' => [...$record->data, 'hours' => $hours]]);

                return $record->title.'\'s session finished after '.$hours.' '.str('hour')->plural($hours).'.';
        }
    }

    public function homeCards(): array
    {
        $today = $this->records('sessions')->whereIn('status', ['booked', 'in_session'])->whereDate('occurs_on', today()->toDateString())->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Sessions today', 'icon' => 'music', 'empty' => 'No sessions today.',
                'rows' => $today->sortBy(fn (Record $session) => (string) $session->value('start_time'))
                    ->map(fn (Record $session) => ['label' => $session->title, 'sub' => $session->value('room').' · '.ucfirst((string) $session->value('type')), 'value' => substr((string) $session->value('start_time'), 0, 5), 'href' => $session->url(), 'tone' => $session->status === 'in_session' ? 'success' : null])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Projects', 'icon' => 'disc-3', 'stats' => [
                ['label' => 'In production', 'value' => $this->records('projects')->where('status', '!=', 'released')->count()],
                ['label' => 'Due in 30 days', 'value' => $this->records('projects')->where('status', '!=', 'released')->whereNotNull('due_on')->whereDate('due_on', '<=', today()->addDays(30)->toDateString())->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $sessions = $this->dated('sessions', $from, $to)->where('status', '!=', 'cancelled')->get();

        return [
            ['title' => 'Hours by room', 'columns' => ['Room', 'Sessions', 'Hours'], 'rows' => $sessions
                ->groupBy(fn (Record $session) => trim((string) $session->value('room')) ?: 'No room')->sortKeys()
                ->map(fn ($group, string $room) => [$room, $group->count(), round($group->sum(fn (Record $session) => $this->number($session, 'hours')), 1)])
                ->values()->all()],
            ['title' => 'Takings by session type', 'columns' => ['Type', 'Sessions', 'Takings'], 'rows' => $sessions->where('status', 'completed')
                ->groupBy(fn (Record $session) => ucfirst((string) $session->value('type')))->sortKeys()
                ->map(fn ($group, string $type) => [$type, $group->count(), $this->money($group->sum('amount'))])
                ->values()->all()],
        ];
    }
}
