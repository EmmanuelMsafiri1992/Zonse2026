<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Co-working & desk booking: a booking ends after it starts, and a desk or room is never double-booked
 * on the same day. Only active members can book. A booking records its length in hours. Members can be
 * paused, resumed or cancelled, and cancelling a member cancels their upcoming bookings. The home page
 * shows today's bookings and the monthly membership income, and the report shows use by desk or room.
 */
class CoworkingLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key !== 'bookings') {
            return $errors;
        }
        if (filled($data['member'] ?? null) && ! $existing && ($member = $this->records('members')->find($data['member'])) && $member->status !== 'active') {
            $errors['data.member'] = $member->title.'\'s membership is '.$member->status.'.';
        }
        $start = (string) ($data['start_time'] ?? '');
        $end = (string) ($data['end_time'] ?? '');
        if ($start !== '' && $end !== '' && $end <= $start) {
            $errors['data.end_time'] = 'The booking must end after it starts.';
        } elseif ($start !== '' && $payload['status'] !== 'cancelled' && filled($payload['title'] ?? null)) {
            $date = Carbon::parse($payload['occurs_on'] ?? today())->toDateString();
            $clash = $this->records('bookings')->where('status', '!=', 'cancelled')->whereDate('occurs_on', $date)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $booking) => mb_strtolower(trim($booking->title)) === mb_strtolower(trim($payload['title']))
                    && $start < ($booking->value('end_time') ?: '24:00') && (string) $booking->value('start_time') < ($end ?: '24:00'));
            if ($clash) {
                $errors['data.start_time'] = $clash->title.' is booked from '.$clash->value('start_time').' to '.($clash->value('end_time') ?: 'close').'.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'bookings') {
            return;
        }
        $record->occurs_on ??= today();
        $start = $record->value('start_time');
        $end = $record->value('end_time');
        $this->put($record, ['_hours' => $start && $end ? round(Carbon::parse($start)->diffInMinutes(Carbon::parse($end)) / 60, 2) : null]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'members') {
            return match ($record->status) {
                'active' => ['pause' => ['label' => 'Pause', 'icon' => 'pause'], 'cancel' => ['label' => 'Cancel membership', 'icon' => 'x']],
                'paused' => ['resume' => ['label' => 'Resume', 'icon' => 'play'], 'cancel' => ['label' => 'Cancel membership', 'icon' => 'x']],
                default => [],
            };
        }

        return match ($record->status) {
            'booked' => ['check_in' => ['label' => 'Check in', 'icon' => 'log-in'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'checked_in' => ['complete' => ['label' => 'Finished', 'icon' => 'check']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'members') {
            $status = ['pause' => 'paused', 'resume' => 'active', 'cancel' => 'cancelled'][$action];
            $record->update(['status' => $status]);
            if ($status === 'cancelled') {
                $upcoming = $this->linked('bookings', 'member', $record)->where('status', 'booked')->whereDate('occurs_on', '>=', today()->toDateString())->get();
                $upcoming->each(fn (Record $booking) => $booking->update(['status' => 'cancelled']));

                return $record->title.'\'s membership cancelled'.($upcoming->isNotEmpty() ? ' with '.$upcoming->count().' upcoming '.str('booking')->plural($upcoming->count()) : '').'.';
            }

            return $record->title.'\'s membership is '.$status.'.';
        }
        $status = ['check_in' => 'checked_in', 'complete' => 'completed', 'cancel' => 'cancelled'][$action];
        $record->update(['status' => $status]);

        return $record->title.' '.str_replace('_', ' ', $status).'.';
    }

    public function homeCards(): array
    {
        $members = $this->records('members')->where('status', 'active')->get();
        $today = $this->records('bookings')->whereIn('status', ['booked', 'checked_in'])->whereDate('occurs_on', today()->toDateString())->get()->sortBy(fn (Record $booking) => (string) $booking->value('start_time'));
        $names = $this->records('members')->pluck('title', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Members', 'icon' => 'users', 'stats' => [
                ['label' => 'Active members', 'value' => $members->count()],
                ['label' => 'Monthly fees', 'value' => $this->money($members->sum(fn (Record $member) => (float) $member->amount))],
                ['label' => 'Bookings today', 'value' => $today->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Today\'s bookings', 'icon' => 'armchair', 'empty' => 'Nothing booked today.',
                'rows' => $today->map(fn (Record $booking) => ['label' => $booking->title, 'sub' => $names[(int) $booking->value('member')] ?? null, 'value' => $booking->value('start_time').($booking->value('end_time') ? '–'.$booking->value('end_time') : ''), 'href' => $booking->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $bookings = $this->dated('bookings', $from, $to)->where('status', '!=', 'cancelled')->get();

        return [['title' => 'Use by desk or room', 'columns' => ['Desk or room', 'Bookings', 'Hours', 'Charges'], 'rows' => $bookings
            ->groupBy(fn (Record $booking) => $booking->title)->sortKeys()
            ->map(fn ($group, string $space) => [$space, $group->count(), round($group->sum(fn (Record $booking) => $this->number($booking, '_hours')), 1), $this->money($group->sum(fn (Record $booking) => (float) $booking->amount))])
            ->values()->all()]];
    }
}
