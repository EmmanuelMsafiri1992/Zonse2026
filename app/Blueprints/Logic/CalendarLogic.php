<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Shared calendars & rooms: an event ends after it starts, a room or resource out of service
 * cannot be booked, the same room cannot be double-booked (all-day events take the whole day),
 * and a room is not booked for more people than it holds.
 */
class CalendarLogic extends AppLogic
{
    public const DAY_START = '00:00';

    public const DAY_END = '23:59';

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'events') {
            return [];
        }

        $data = $payload['data'];
        $errors = [];
        if (empty($data['all_day']) && filled($data['start_time'] ?? null) && filled($data['end_time'] ?? null) && strcmp((string) $data['end_time'], (string) $data['start_time']) <= 0) {
            $errors['data.end_time'] = 'The event must end after it starts.';
        }

        $resource = ! empty($data['resource']) ? $this->records('resources')->find($data['resource']) : null;
        if (! $resource || $payload['status'] === 'cancelled') {
            return $errors;
        }

        if ($resource->status === 'out_of_service') {
            $errors['data.resource'] = $resource->title.' is out of service.';
        }
        $capacity = (int) $this->number($resource, 'capacity');
        $people = count($this->attendees((string) ($data['attendees'] ?? '')));
        if ($capacity > 0 && $people > $capacity) {
            $errors['data.attendees'] = $resource->title.' holds '.$capacity.' people; '.$people.' are invited.';
        }

        if (filled($payload['occurs_on'] ?? null) && ! isset($errors['data.end_time'])) {
            [$start, $end] = $this->span($data);
            $clash = $this->linked('events', 'resource', $resource)->where('status', '!=', 'cancelled')->whereDate('occurs_on', $payload['occurs_on'])
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(function (Record $other) use ($start, $end) {
                    [$otherStart, $otherEnd] = $this->span((array) $other->data);

                    return strcmp($otherStart, $end) < 0 && strcmp($otherEnd, $start) > 0;
                });
            if ($clash) {
                $errors['data.resource'] = $resource->title.' is already booked for "'.$clash->title.'" ('.implode('–', $this->span((array) $clash->data)).').';
            }
        }

        return $errors;
    }

    /**
     * Start and end as "H:i" strings; all-day events and events without an end fill the rest of the day.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string}
     */
    public function span(array $data): array
    {
        if (! empty($data['all_day']) || blank($data['start_time'] ?? null)) {
            return [self::DAY_START, self::DAY_END];
        }

        $start = Carbon::parse($data['start_time'])->format('H:i');
        $end = filled($data['end_time'] ?? null) ? Carbon::parse($data['end_time'])->format('H:i') : Carbon::parse($data['start_time'])->addHour()->format('H:i');

        return [$start, $end];
    }

    /** @return list<string> */
    public function attendees(string $list): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $list) ?: [])));
    }

    protected function hours(Record $event): float
    {
        [$start, $end] = $this->span((array) $event->data);

        return round(Carbon::parse($start)->diffInMinutes(Carbon::parse($end)) / 60, 1);
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'resources') {
            return [];
        }

        $bookings = $this->linked('events', 'resource', $record)->where('status', '!=', 'cancelled')->whereDate('occurs_on', '>=', today())->orderBy('occurs_on')->take(15)->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Upcoming bookings', 'icon' => 'calendar', 'empty' => 'Nothing booked.',
            'rows' => $bookings->map(fn (Record $event) => [
                'label' => $event->title, 'sub' => $event->assignee?->name, 'value' => $event->occurs_on->format('D d M').' '.implode('–', $this->span((array) $event->data)), 'href' => $event->url(),
            ])->values()->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $today = $this->records('events')->where('status', '!=', 'cancelled')->whereDate('occurs_on', today())->get()
            ->sortBy(fn (Record $event) => $this->span((array) $event->data)[0]);
        $now = now()->format('H:i');
        $busy = $today->filter(function (Record $event) use ($now) {
            [$start, $end] = $this->span((array) $event->data);

            return strcmp($start, $now) <= 0 && strcmp($end, $now) > 0;
        })->map(fn (Record $event) => (int) $event->value('resource'))->filter()->all();
        $free = $this->records('resources')->where('status', 'available')->orderBy('title')->get()->reject(fn (Record $resource) => in_array($resource->id, $busy, true));
        $resources = $this->records('resources')->pluck('title', 'id');

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Today', 'icon' => 'calendar', 'empty' => 'Nothing on the calendar today.',
                'rows' => $today->map(fn (Record $event) => [
                    'label' => $event->title, 'sub' => $resources[$event->value('resource')] ?? null, 'value' => implode('–', $this->span((array) $event->data)), 'href' => $event->url(),
                    'tone' => $event->status === 'tentative' ? 'warning' : null,
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Free right now', 'icon' => 'door-open', 'empty' => 'Every room is in use.',
                'rows' => $free->map(fn (Record $resource) => [
                    'label' => $resource->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $resource->value('type'))), 'value' => $resource->value('capacity') ? $resource->value('capacity').' seats' : '', 'href' => $resource->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $events = $this->dated('events', $from, $to)->where('status', '!=', 'cancelled')->get();
        $resources = $this->records('resources')->orderBy('title')->get();

        $use = $resources->map(function (Record $resource) use ($events) {
            $mine = $events->filter(fn (Record $event) => (int) $event->value('resource') === $resource->id);

            return [$resource->title, ucfirst(str_replace('_', ' ', (string) $resource->value('type'))), $mine->count(), $mine->sum(fn (Record $event) => $this->hours($event))];
        })->all();

        $months = collect($this->months($from, $to))->map(fn ($label, $month) => [
            $label, $events->filter(fn (Record $event) => $event->occurs_on?->format('Y-m') === $month)->count(),
            $events->filter(fn (Record $event) => $event->occurs_on?->format('Y-m') === $month && $event->status === 'tentative')->count(),
        ])->values()->all();

        return [
            ['title' => 'Room and resource use', 'columns' => ['Room / resource', 'Type', 'Bookings', 'Hours booked'], 'rows' => $use],
            ['title' => 'Events by month', 'columns' => ['Month', 'Events', 'Still tentative'], 'rows' => $months],
        ];
    }
}
