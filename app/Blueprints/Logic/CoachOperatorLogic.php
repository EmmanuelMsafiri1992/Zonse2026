<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Bus & coach operator: a charter can't carry more passengers than its coach has seats, and must return on
 * or after the day it departs. A coach in maintenance can't be booked, nor can a coach whose roadworthy
 * certificate runs out before the trip ends, and a coach can't be on two charters on the same days.
 * Departing puts the coach on a trip and completing the charter makes it available again.
 */
class CoachOperatorLogic extends AppLogic
{
    /**
     * Charter statuses that hold a coach.
     *
     * @var list<string>
     */
    public const HOLDING = ['booked', 'on_trip'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key !== 'charters') {
            return $errors;
        }
        $departs = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today();
        $returns = filled($payload['due_on'] ?? null) ? Carbon::parse($payload['due_on']) : $departs->copy();
        if ($returns->lt($departs)) {
            $errors['due_on'] = 'The return date is before the departure.';
        }
        if (blank($data['coach'] ?? null) || ! ($coach = $this->records('coaches')->find($data['coach']))) {
            if (in_array($payload['status'], self::HOLDING, true)) {
                $errors['data.coach'] = 'Give the coach.';
            }

            return $errors;
        }
        if ((int) ($data['passengers'] ?? 0) > (int) $coach->value('seats')) {
            $errors['data.passengers'] = $coach->title.' only has '.(int) $coach->value('seats').' seats.';
        }
        if (! in_array($payload['status'], self::HOLDING, true)) {
            return $errors;
        }
        if ($coach->status === 'maintenance') {
            $errors['data.coach'] = $coach->title.' is in maintenance.';
        } elseif (filled($coach->value('cof_expiry')) && Carbon::parse($coach->value('cof_expiry'))->lt($returns)) {
            $errors['data.coach'] = $coach->title.'\'s roadworthy certificate runs out on '.Carbon::parse($coach->value('cof_expiry'))->format('d M Y').'.';
        } elseif ($clash = $this->linked('charters', 'coach', $coach)->whereIn('status', self::HOLDING)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
            ->first(fn (Record $other) => $other->occurs_on && $other->occurs_on->lte($returns) && ($other->due_on ?? $other->occurs_on)->gte($departs))) {
            $errors['data.coach'] = $coach->title.' is on '.$clash->title.'\'s charter from '.$clash->occurs_on->format('d M').'.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'charters') {
            $record->occurs_on ??= today();
            $record->due_on ??= $record->occurs_on->copy();
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'coaches') {
            return match ($record->status) {
                'available' => ['maintenance' => ['label' => 'Into maintenance', 'icon' => 'wrench']],
                'maintenance' => ['available' => ['label' => 'Back in service', 'icon' => 'check']],
                default => [],
            };
        }

        return match ($record->status) {
            'quoted' => ['book' => ['label' => 'Confirm booking', 'icon' => 'calendar-check'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'booked' => ['depart' => ['label' => 'Departed', 'icon' => 'bus-front'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'on_trip' => ['complete' => ['label' => 'Returned', 'icon' => 'flag']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $coach = $this->parent($record, 'coach');
        switch ($action) {
            case 'maintenance':
                $record->update(['status' => 'maintenance']);

                return $record->title.' is in maintenance.';
            case 'available':
                $record->update(['status' => 'available']);

                return $record->title.' is back in service.';
            case 'book':
                $errors = $this->validate($this->app()->entity('charters'), ['title' => $record->title, 'status' => 'booked', 'data' => $record->data, 'occurs_on' => $record->occurs_on?->toDateString(), 'due_on' => $record->due_on?->toDateString()], $record);
                if ($errors) {
                    throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn (string $message, string $key) => [str_replace('data.', '', $key) => $message])->all());
                }
                $record->update(['status' => 'booked']);

                return $record->title.'\'s charter booked on '.$coach->title.'.';
            case 'depart':
                if ($coach->status === 'on_trip') {
                    throw ValidationException::withMessages(['coach' => $coach->title.' is still on another trip.']);
                }
                $record->update(['status' => 'on_trip']);
                $coach->update(['status' => 'on_trip']);

                return $record->title.' departed on '.$coach->title.'.';
            case 'complete':
                $record->update(['status' => 'completed', 'due_on' => today()->max($record->occurs_on)]);
                $coach?->update(['status' => 'available']);

                return $record->title.'\'s charter completed.';
            default:
                $record->update(['status' => 'cancelled']);

                return $record->title.'\'s charter cancelled.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'coaches') {
            return [];
        }

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Upcoming charters', 'icon' => 'route', 'empty' => 'No charters booked.',
            'rows' => $this->linked('charters', 'coach', $record)->whereIn('status', self::HOLDING)->orderBy('occurs_on')->get()
                ->map(fn (Record $charter) => ['label' => $charter->title, 'sub' => (string) $charter->value('route'), 'value' => $charter->occurs_on->format('d M').' – '.$charter->due_on->format('d M'), 'href' => $charter->url()])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Departures in the next 7 days', 'icon' => 'bus-front', 'empty' => 'No departures this week.',
                'rows' => $this->records('charters')->where('status', 'booked')->whereDate('occurs_on', '<=', today()->addDays(7)->toDateString())->orderBy('occurs_on')->get()
                    ->map(fn (Record $charter) => ['label' => $charter->title, 'sub' => (string) $charter->value('route'), 'value' => $charter->occurs_on->format('d M').(filled($charter->value('departure_time')) ? ' '.substr((string) $charter->value('departure_time'), 0, 5) : ''), 'href' => $charter->url()])->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Roadworthy certificates due in 30 days', 'icon' => 'file-badge', 'empty' => 'No certificates due soon.',
                'rows' => $this->records('coaches')->get()->filter(fn (Record $coach) => filled($coach->value('cof_expiry')) && Carbon::parse($coach->value('cof_expiry'))->lte(today()->addDays(30)))
                    ->sortBy(fn (Record $coach) => $coach->value('cof_expiry'))
                    ->map(fn (Record $coach) => ['label' => $coach->title, 'sub' => (string) $coach->value('registration'), 'value' => Carbon::parse($coach->value('cof_expiry'))->format('d M Y'), 'href' => $coach->url(), 'tone' => Carbon::parse($coach->value('cof_expiry'))->lt(today()) ? 'danger' : 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $charters = $this->dated('charters', $from, $to)->whereIn('status', ['booked', 'on_trip', 'completed'])->get();
        $coaches = $this->records('coaches')->pluck('title', 'id');

        return [['title' => 'Charters by coach', 'columns' => ['Coach', 'Charters', 'Days out', 'Passengers', 'Revenue'], 'rows' => $charters
            ->groupBy(fn (Record $charter) => $coaches[(int) $charter->value('coach')] ?? 'No coach')->sortKeys()
            ->map(fn ($group, string $coach) => [$coach, $group->count(), $group->sum(fn (Record $charter) => (int) $charter->occurs_on->diffInDays($charter->due_on ?? $charter->occurs_on) + 1), $group->sum(fn (Record $charter) => (int) $charter->value('passengers')), $this->money($group->sum('amount'))])
            ->values()->all()]];
    }
}
