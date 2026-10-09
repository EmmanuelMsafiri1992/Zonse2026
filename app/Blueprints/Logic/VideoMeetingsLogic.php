<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Video meetings: a meeting runs for a sensible length, its host cannot be in two meetings at
 * once, and a cancelled or ended meeting cannot go live again. Meetings are started and ended
 * in one click (keeping the recording link), and past meetings left open are closed each night.
 */
class VideoMeetingsLogic extends AppLogic
{
    public const DEFAULT_MINUTES = 60;

    public const OPEN = ['scheduled', 'live'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if (filled($data['duration'] ?? null) && ((float) $data['duration'] < 5 || (float) $data['duration'] > 600)) {
            $errors['data.duration'] = 'A meeting runs between 5 and 600 minutes.';
        }
        if ($payload['status'] === 'live' && in_array($existing?->status, ['cancelled', 'ended'], true)) {
            $errors['status'] = 'This meeting is '.$existing->status.' and cannot go live again.';
        }

        if (in_array($payload['status'], self::OPEN, true) && filled($payload['occurs_on'] ?? null) && filled($data['start_time'] ?? null) && filled($payload['assignee_id'] ?? null)) {
            [$start, $end] = $this->window($payload['occurs_on'], $data['start_time'], $data['duration'] ?? null);
            $clash = $this->records('meetings')->whereIn('status', self::OPEN)->where('assignee_id', $payload['assignee_id'])->whereDate('occurs_on', $payload['occurs_on'])
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(function (Record $other) use ($start, $end) {
                    [$otherStart, $otherEnd] = $this->window($other->occurs_on->toDateString(), (string) $other->value('start_time'), $other->value('duration'));

                    return $otherStart->lt($end) && $otherEnd->gt($start);
                });
            if ($clash) {
                $errors['data.start_time'] = 'The host is already in "'.$clash->title.'" at '.$clash->value('start_time').'.';
            }
        }

        return $errors;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function window(string $date, string $time, mixed $minutes): array
    {
        $start = Carbon::parse($date.' '.$time);

        return [$start, $start->copy()->addMinutes((int) ($minutes ?: self::DEFAULT_MINUTES))];
    }

    public function daily(Workspace $workspace): int
    {
        $closed = 0;
        foreach ($this->records('meetings')->whereIn('status', self::OPEN)->whereDate('occurs_on', '<', today())->get() as $meeting) {
            $meeting->update(['status' => 'ended']);
            $closed++;
        }

        return $closed;
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'scheduled' => ['start' => ['label' => 'Start meeting', 'icon' => 'play', 'confirm' => 'Start '.$record->title.' now?']],
            'live' => ['end' => ['label' => 'End meeting', 'icon' => 'square', 'fields' => [['name' => 'recording_url', 'label' => 'Recording link (optional)', 'type' => 'url']]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'start') {
            $record->update(['status' => 'live']);

            return $record->title.' is live.';
        }

        $recording = $request->validate(['recording_url' => ['nullable', 'url', 'max:500']])['recording_url'] ?? null;
        $record->update(['status' => 'ended', 'data' => [...(array) $record->data, 'recording_url' => $recording ?: $record->value('recording_url')]]);

        return $record->title.' has ended.';
    }

    public function recordCards(Record $record): array
    {
        if (! $record->occurs_on || blank($record->value('start_time'))) {
            return [];
        }

        [$start, $end] = $this->window($record->occurs_on->toDateString(), (string) $record->value('start_time'), $record->value('duration'));
        $attendees = $this->attendees($record);

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Meeting', 'icon' => 'video', 'stats' => array_values(array_filter([
            ['label' => 'When', 'value' => $start->format('D d M, H:i').' – '.$end->format('H:i')],
            ['label' => 'Attendees', 'value' => (string) count($attendees)],
            ['label' => 'Join', 'value' => (string) ($record->value('link') ?? '—')],
            $record->value('recording_url') ? ['label' => 'Recording', 'value' => (string) $record->value('recording_url')] : null,
        ]))]]];
    }

    /** @return list<string> */
    public function attendees(Record $record): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', (string) $record->value('attendees')) ?: [])));
    }

    public function homeCards(): array
    {
        $upcoming = $this->records('meetings')->whereIn('status', self::OPEN)->whereBetween('occurs_on', [today(), today()->addDays(6)->endOfDay()])->get()
            ->sortBy(fn (Record $meeting) => $meeting->occurs_on->toDateString().' '.$meeting->value('start_time'));
        $today = $upcoming->filter(fn (Record $meeting) => $meeting->occurs_on->isToday());

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Today\'s meetings', 'icon' => 'video', 'empty' => 'No meetings today.',
                'rows' => $today->map(fn (Record $meeting) => [
                    'label' => $meeting->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $meeting->value('platform'))), 'value' => (string) $meeting->value('start_time'),
                    'href' => $meeting->url(), 'tone' => $meeting->status === 'live' ? 'success' : null,
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Coming up this week', 'icon' => 'calendar', 'empty' => 'Nothing else this week.',
                'rows' => $upcoming->reject(fn (Record $meeting) => $meeting->occurs_on->isToday())->map(fn (Record $meeting) => [
                    'label' => $meeting->title, 'sub' => $meeting->assignee?->name, 'value' => $meeting->occurs_on->format('D d M').' '.$meeting->value('start_time'), 'href' => $meeting->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $meetings = $this->dated('meetings', $from, $to)->where('status', '!=', 'cancelled')->get();
        $minutes = fn ($group) => $group->sum(fn (Record $meeting) => (int) ($meeting->value('duration') ?: self::DEFAULT_MINUTES));

        $platforms = $meetings->groupBy(fn (Record $meeting) => (string) ($meeting->value('platform') ?: 'other'))->sortKeys()->map(fn ($group, $platform) => [
            ucfirst(str_replace('_', ' ', $platform)), $group->count(), round($minutes($group) / 60, 1),
        ])->values()->all();

        $names = User::query()->whereIn('id', $meetings->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');
        $hosts = $meetings->groupBy(fn (Record $meeting) => $names[$meeting->assignee_id] ?? 'No host')->sortKeys()->map(fn ($group, $host) => [
            $host, $group->count(), round($minutes($group) / 60, 1), $group->sum(fn (Record $meeting) => count($this->attendees($meeting))),
        ])->values()->all();

        return [
            ['title' => 'Meetings by platform', 'columns' => ['Platform', 'Meetings', 'Hours'], 'rows' => $platforms],
            ['title' => 'Time in meetings by host', 'columns' => ['Host', 'Meetings', 'Hours', 'Attendees'], 'rows' => $hosts],
        ];
    }
}
