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
 * Safari / camping / activity bookings: an activity never takes more guests than its capacity at
 * one time. Campsites are counted across every night a booking covers; other activities per date
 * and start time, where a booking with no time counts against every slot that day. Closed activities
 * take no bookings. A booking is priced at the price per person times the guests, and for campsites
 * times the nights. Nobody goes out without a signed indemnity. Each day, paid bookings that have
 * finished are completed and unpaid ones whose date has passed are cancelled.
 */
class SafariLogic extends AppLogic
{
    /**
     * Bookings that take places.
     *
     * @var list<string>
     */
    protected const HOLDING = ['booked', 'paid'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'bookings') {
            return [];
        }
        $data = $payload['data'];
        $errors = [];

        if ($existing && in_array($existing->status, ['completed', 'cancelled'], true) && $payload['status'] !== $existing->status) {
            $errors['status'] = 'This booking is '.$existing->status.'.';
        }
        if ((int) ($data['guests'] ?? 0) < 1) {
            $errors['data.guests'] = 'Book at least one guest.';
        }
        $start = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : null;
        $end = filled($payload['due_on'] ?? null) ? Carbon::parse($payload['due_on']) : null;
        if ($start && $end && $end->lt($start)) {
            $errors['due_on'] = 'The booking cannot end before it starts.';
        }
        if ($payload['status'] === 'completed' && empty($data['indemnity_signed'])) {
            $errors['data.indemnity_signed'] = 'The indemnity must be signed first.';
        }

        $activity = filled($data['activity'] ?? null) ? $this->records('activities')->find($data['activity']) : null;
        if (! $activity || ! $start || ! in_array($payload['status'], self::HOLDING, true)) {
            return $errors;
        }
        if ($activity->status === 'closed' && (! $existing || (int) $existing->value('activity') !== $activity->id)) {
            $errors['data.activity'] = $activity->title.' is closed.';
        }
        $capacity = (int) $activity->value('capacity');
        if ($capacity > 0) {
            $taken = $this->taken($activity, $start, $end ?? $start, $data['start_time'] ?? null, $existing?->id);
            if ($taken + (int) ($data['guests'] ?? 0) > $capacity) {
                $errors['data.guests'] = 'Only '.max(0, $capacity - $taken).' place(s) left on '.$activity->title.'.';
            }
        }

        return $errors;
    }

    /**
     * Whether the activity is booked by the night.
     */
    protected function overnight(?Record $activity): bool
    {
        return $activity?->value('type') === 'campsite';
    }

    /**
     * The most guests already booked on the activity at any one time in the period.
     */
    protected function taken(Record $activity, Carbon $start, Carbon $end, ?string $time, ?int $except): int
    {
        $bookings = $this->linked('bookings', 'activity', $activity)->whereIn('status', self::HOLDING)
            ->where('occurs_on', '<=', $end->copy()->endOfDay())
            ->when($except, fn ($query) => $query->whereKeyNot($except))->get()
            ->filter(fn (Record $booking) => ($booking->due_on ?? $booking->occurs_on)->gte($start->copy()->startOfDay()));

        if (! $this->overnight($activity)) {
            return $bookings->filter(fn (Record $booking) => blank($time) || blank($booking->value('start_time')) || $booking->value('start_time') === $time)
                ->sum(fn (Record $booking) => (int) $booking->value('guests'));
        }

        $most = 0;
        $last = $end->gt($start) ? $end->copy()->subDay() : $start->copy();
        for ($night = $start->copy()->startOfDay(); $night->lte($last); $night->addDay()) {
            $most = max($most, $bookings->filter(fn (Record $booking) => $booking->occurs_on->lte($night) && ($booking->due_on && $booking->due_on->gt($booking->occurs_on) ? $booking->due_on->copy()->subDay() : $booking->occurs_on)->gte($night))
                ->sum(fn (Record $booking) => (int) $booking->value('guests')));
        }

        return $most;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'bookings') {
            return;
        }
        $activity = $this->parent($record, 'activity');
        $guests = max(1, (int) $record->value('guests'));
        $nights = $this->overnight($activity) && $record->occurs_on && $record->due_on ? max(1, (int) $record->occurs_on->copy()->startOfDay()->diffInDays($record->due_on->copy()->startOfDay())) : 1;
        $price = (float) $activity?->value('price_per_person');

        $priced = $record->amount !== null && abs((float) $record->amount - (float) $record->value('_priced')) < 0.01;
        if (($record->amount === null || $priced) && $price > 0) {
            $record->amount = round($price * $guests * $nights, 2);
        }
        $this->put($record, ['_nights' => $this->overnight($activity) ? $nights : null, '_priced' => $record->amount !== null && $price > 0 ? round($price * $guests * $nights, 2) : null]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'bookings') {
            return [];
        }
        $sign = empty($record->value('indemnity_signed')) ? ['indemnity' => ['label' => 'Indemnity signed', 'icon' => 'file-signature']] : [];
        $cancel = ['cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this booking?']];

        return match ($record->status) {
            'booked' => [...$sign, 'pay' => ['label' => 'Mark paid', 'icon' => 'banknote'], ...$cancel],
            'paid' => [...$sign, 'complete' => ['label' => 'Completed', 'icon' => 'check'], ...$cancel],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'indemnity':
                $record->update(['data' => [...$record->data, 'indemnity_signed' => true, '_signed_at' => now()->toDateTimeString()]]);

                return 'Indemnity signed for '.$record->title.'.';
            case 'pay':
                $record->update(['status' => 'paid']);

                return $record->title.' paid '.$this->money($record->amount).'.';
            case 'complete':
                if (empty($record->value('indemnity_signed'))) {
                    throw ValidationException::withMessages(['indemnity_signed' => 'The indemnity must be signed first.']);
                }
                $record->update(['status' => 'completed']);

                return $record->title.'\'s booking completed.';
            default:
                $record->update(['status' => 'cancelled']);

                return $record->title.'\'s booking cancelled.';
        }
    }

    public function daily(Workspace $workspace): int
    {
        $past = $this->records('bookings')->whereIn('status', self::HOLDING)->where('occurs_on', '<', today()->startOfDay())->get()
            ->filter(fn (Record $booking) => ($booking->due_on ?? $booking->occurs_on)->lt(today()));
        $past->each(fn (Record $booking) => $booking->update(['status' => $booking->status === 'paid' ? 'completed' : 'cancelled']));

        return $past->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'activities') {
            return [];
        }
        $coming = $this->linked('bookings', 'activity', $record)->whereIn('status', self::HOLDING)->where('occurs_on', '>=', today()->startOfDay())->orderBy('occurs_on')->limit(20)->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Coming bookings', 'icon' => 'calendar-check', 'empty' => 'No bookings yet.',
            'rows' => $coming->map(fn (Record $booking) => $this->row($booking))->values()->all(),
        ]]];
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Record $booking, ?string $activity = null): array
    {
        return [
            'label' => $booking->title,
            'sub' => trim(($activity ? $activity.' · ' : '').$booking->occurs_on?->format('d M').($booking->value('start_time') ? ' '.$booking->value('start_time') : '').' · '.(int) $booking->value('guests').' guest(s)'),
            'value' => empty($booking->value('indemnity_signed')) ? 'Indemnity missing' : ucfirst($booking->status),
            'href' => $booking->url(),
            'tone' => empty($booking->value('indemnity_signed')) ? 'danger' : ($booking->status === 'paid' ? 'success' : 'warning'),
        ];
    }

    public function homeCards(): array
    {
        $today = $this->records('bookings')->whereIn('status', self::HOLDING)->where('occurs_on', '<=', today()->endOfDay())->get()
            ->filter(fn (Record $booking) => ($booking->due_on ?? $booking->occurs_on)->gte(today()))
            ->sortBy(fn (Record $booking) => (string) $booking->value('start_time'));
        $names = $this->records('activities')->pluck('title', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today', 'icon' => 'tent-tree', 'stats' => [
                ['label' => 'Guests out today', 'value' => (string) $today->sum(fn (Record $booking) => (int) $booking->value('guests'))],
                ['label' => 'Indemnities missing', 'value' => (string) $today->filter(fn (Record $booking) => empty($booking->value('indemnity_signed')))->count(), 'tone' => $today->contains(fn (Record $booking) => empty($booking->value('indemnity_signed'))) ? 'danger' : null],
                ['label' => 'Unpaid', 'value' => $this->money($today->where('status', 'booked')->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Today\'s guests', 'icon' => 'calendar-check', 'empty' => 'Nobody booked today.',
                'rows' => $today->map(fn (Record $booking) => $this->row($booking, $names[(int) $booking->value('activity')] ?? null))->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $bookings = $this->dated('bookings', $from, $to)->whereIn('status', ['paid', 'completed'])->get();
        $activities = $this->records('activities')->orderBy('title')->get();

        $byActivity = $activities->map(function (Record $activity) use ($bookings) {
            $group = $bookings->filter(fn (Record $booking) => (int) $booking->value('activity') === $activity->id);
            $guests = $group->sum(fn (Record $booking) => (int) $booking->value('guests') * max(1, (int) $booking->value('_nights')));

            return [$activity->title, ucfirst(str_replace('_', ' ', (string) $activity->value('type'))), $group->count(), $guests, $this->money($group->sum('amount'))];
        })->filter(fn (array $row) => $row[2] > 0)->values()->all();

        $byType = $bookings->groupBy(fn (Record $booking) => (string) $activities->firstWhere('id', (int) $booking->value('activity'))?->value('type'))->sortKeys()
            ->map(fn (Collection $group, string $type) => [ucfirst(str_replace('_', ' ', $type ?: 'other')), $group->count(), $this->money($group->sum('amount'))])->values()->all();

        return [
            ['title' => 'By activity', 'columns' => ['Activity', 'Type', 'Bookings', 'Guests (guest-nights for camping)', 'Revenue'], 'rows' => $byActivity],
            ['title' => 'By type', 'columns' => ['Type', 'Bookings', 'Revenue'], 'rows' => $byType],
        ];
    }
}
