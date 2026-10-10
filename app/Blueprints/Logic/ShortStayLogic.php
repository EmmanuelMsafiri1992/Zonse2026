<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Short-stay rental: a stay checks out after it checks in, can't overlap another stay at the same listing
 * and can't be booked at a blocked or unlisted one. Its payout defaults to the nights times the nightly
 * rate plus the cleaning fee. Checking in hands over the door code, and the next guest can't check in
 * until the turnover clean after the last one is done. The report shows occupancy and income per listing.
 */
class ShortStayLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key !== 'stays' || blank($data['listing'] ?? null)) {
            return $errors;
        }
        $listing = $this->records('listings')->find($data['listing']);
        if ($listing && ! $existing && $listing->status !== 'active') {
            $errors['data.listing'] = $listing->title.' is '.$listing->status.' and cannot take bookings.';
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null)) {
            $in = Carbon::parse($payload['occurs_on']);
            $out = Carbon::parse($payload['due_on']);
            if ($out->lte($in)) {
                $errors['due_on'] = 'Check-out must be after check-in.';
            } elseif ($payload['status'] !== 'cancelled' && ($clash = $this->clash((int) $data['listing'], $in, $out, $existing?->id))) {
                $errors['occurs_on'] = ($listing?->title ?? 'This listing').' is booked by '.$clash->title.' from '.$clash->occurs_on->format('d M').' to '.$clash->due_on->format('d M').'.';
            }
        }
        if (array_key_exists('guests', $data) && (float) $data['guests'] < 0) {
            $errors['data.guests'] = 'Guests cannot be negative.';
        }

        return $errors;
    }

    /**
     * Another live stay at the listing whose nights overlap these.
     */
    protected function clash(int $listing, Carbon $in, Carbon $out, ?int $except): ?Record
    {
        return $this->linked('stays', 'listing', $listing)->where('status', '!=', 'cancelled')->when($except, fn ($query) => $query->whereKeyNot($except))
            ->whereDate('occurs_on', '<', $out->toDateString())->whereDate('due_on', '>', $in->toDateString())->first();
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'stays' || ! $record->occurs_on || ! $record->due_on) {
            return;
        }
        $nights = (int) $record->occurs_on->diffInDays($record->due_on);
        $this->put($record, ['_nights' => $nights]);
        if ((float) $record->amount <= 0 && ($listing = $this->parent($record, 'listing'))) {
            $record->amount = round($nights * $this->number($listing, 'nightly_rate') + $this->number($listing, 'cleaning_fee'), 2);
        }
    }

    /**
     * The last guest to check out of a listing, when their turnover clean is still to do.
     */
    protected function uncleaned(int $listing, ?int $except = null): ?Record
    {
        return $this->linked('stays', 'listing', $listing)->where('status', 'checked_out')->when($except, fn ($query) => $query->whereKeyNot($except))->get()
            ->first(fn (Record $stay) => ! $stay->value('cleaning_done'));
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'stays') {
            return [];
        }

        return match ($record->status) {
            'booked' => ['check_in' => ['label' => 'Check in', 'icon' => 'log-in', 'fields' => [['name' => 'access_code', 'label' => 'Door / key code', 'type' => 'text', 'value' => $record->value('access_code')]]], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'checked_in' => ['check_out' => ['label' => 'Check out', 'icon' => 'log-out']],
            'checked_out' => $record->value('cleaning_done') ? [] : ['clean' => ['label' => 'Turnover clean done', 'icon' => 'sparkles']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'check_in':
                if ($dirty = $this->uncleaned((int) $record->value('listing'), $record->id)) {
                    throw ValidationException::withMessages(['access_code' => 'The turnover clean after '.$dirty->title.' is not done yet.']);
                }
                $code = trim((string) ($request->validate(['access_code' => ['nullable', 'string', 'max:50']])['access_code'] ?? '')) ?: $record->value('access_code');
                $record->update(['status' => 'checked_in', 'data' => [...$record->data, 'access_code' => $code]]);

                return $record->title.' checked in'.($code ? '; door code '.$code : '').'.';
            case 'check_out':
                $record->update(['status' => 'checked_out', 'data' => [...$record->data, 'cleaning_done' => false]]);

                return $record->title.' checked out. '.($this->parent($record, 'listing')?->title ?? 'The listing').' needs a turnover clean.';
            case 'clean':
                $record->update(['data' => [...$record->data, 'cleaning_done' => true]]);

                return ($this->parent($record, 'listing')?->title ?? 'The listing').' is clean and ready.';
            default:
                $record->update(['status' => 'cancelled']);

                return $record->title.'\'s stay cancelled.';
        }
    }

    public function homeCards(): array
    {
        $arrivals = $this->records('stays')->where('status', 'booked')->whereDate('occurs_on', today()->toDateString())->get();
        $departures = $this->records('stays')->where('status', 'checked_in')->whereDate('due_on', '<=', today()->toDateString())->get();
        $cleans = $this->records('stays')->where('status', 'checked_out')->get()->reject(fn (Record $stay) => $stay->value('cleaning_done'));
        $row = fn (Record $stay, string $value, ?string $tone = null) => ['label' => $stay->title, 'sub' => $this->parent($stay, 'listing')?->title, 'value' => $value, 'href' => $stay->url(), 'tone' => $tone];

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Today', 'icon' => 'calendar-check', 'empty' => 'No arrivals, departures or cleans today.',
            'rows' => [
                ...$arrivals->map(fn (Record $stay) => $row($stay, 'arriving'))->all(),
                ...$departures->map(fn (Record $stay) => $row($stay, 'leaving', $stay->due_on->lt(today()) ? 'danger' : null))->all(),
                ...$cleans->map(fn (Record $stay) => $row($stay, 'needs a clean', 'warning'))->all(),
            ],
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $days = max(1, (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1);
        $stays = $this->records('stays')->where('status', '!=', 'cancelled')->whereNotNull('occurs_on')->whereNotNull('due_on')
            ->whereDate('occurs_on', '<=', $to->toDateString())->whereDate('due_on', '>', $from->toDateString())->get()->groupBy(fn (Record $stay) => (int) $stay->value('listing'));

        return [['title' => 'Occupancy by listing', 'columns' => ['Listing', 'Stays', 'Nights booked', 'Occupancy', 'Payouts'], 'rows' => $this->records('listings')->orderBy('title')->get()
            ->map(function (Record $listing) use ($stays, $days, $from, $to) {
                $group = $stays[$listing->id] ?? collect();
                $nights = $group->sum(fn (Record $stay) => max(0, (int) Carbon::parse(max($stay->occurs_on, $from->copy()->startOfDay()))->diffInDays(Carbon::parse(min($stay->due_on, $to->copy()->addDay()->startOfDay())))));

                return [$listing->title, $group->count(), $nights, round(min($nights, $days) / $days * 100).'%', $this->money($group->sum(fn (Record $stay) => (float) $stay->amount))];
            })->values()->all()]];
    }
}
