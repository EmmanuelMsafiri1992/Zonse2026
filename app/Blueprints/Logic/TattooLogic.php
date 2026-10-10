<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Tattoo & piercing studio: a booked tattoo needs a deposit, and the deposit can't be more than the price.
 * Work can't start until the consent form is signed and the client's age is verified. Finishing a piece
 * marks it as healing and a touch-up can be booked six weeks on at no charge. The report shows each
 * artist's work and takings.
 */
class TattooLogic extends AppLogic
{
    /**
     * Weeks after a session that a touch-up is booked.
     */
    public const TOUCH_UP_WEEKS = 6;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        $deposit = (float) ($data['deposit'] ?? 0);
        if ($payload['status'] === 'booked' && ($data['type'] ?? null) === 'tattoo' && $deposit <= 0) {
            $errors['data.deposit'] = 'Take a deposit to book a tattoo.';
        }
        if ($deposit > 0 && (float) ($payload['amount'] ?? 0) > 0 && $deposit > (float) $payload['amount']) {
            $errors['data.deposit'] = 'The deposit is more than the price.';
        }
        if ($payload['status'] === 'in_progress' && ($problem = $this->notReady($data))) {
            $errors['status'] = $problem;
        }

        return $errors;
    }

    /**
     * Why work can't start on a booking, if there is a reason.
     *
     * @param  array<string, mixed>  $data
     */
    protected function notReady(array $data): ?string
    {
        return match (true) {
            ! filter_var($data['consent_signed'] ?? false, FILTER_VALIDATE_BOOLEAN) => 'The consent form must be signed before work starts.',
            ! filter_var($data['over_18_verified'] ?? false, FILTER_VALIDATE_BOOLEAN) => 'Verify the client\'s age before work starts.',
            default => null,
        };
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'consultation' => ['book' => ['label' => 'Book in', 'icon' => 'calendar-check', 'fields' => [
                ['name' => 'date', 'label' => 'Appointment', 'type' => 'date', 'value' => $record->occurs_on?->toDateString()],
                ['name' => 'deposit', 'label' => 'Deposit', 'type' => 'number', 'value' => $record->value('deposit')],
            ]], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'booked' => ['start' => ['label' => 'Start', 'icon' => 'pen-tool'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'in_progress' => ['finish' => ['label' => 'Finished', 'icon' => 'check']],
            'healed' => $record->value('type') === 'touch_up' ? [] : ['touch_up' => ['label' => 'Book touch-up', 'icon' => 'refresh-cw']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'book':
                $input = $request->validate(['date' => ['required', 'date', 'after_or_equal:today'], 'deposit' => ['nullable', 'numeric', 'min:0']]);
                $deposit = (float) ($input['deposit'] ?? 0);
                if ($record->value('type') === 'tattoo' && $deposit <= 0) {
                    throw ValidationException::withMessages(['deposit' => 'Take a deposit to book a tattoo.']);
                }
                $record->update(['status' => 'booked', 'occurs_on' => $input['date'], 'data' => [...$record->data, 'deposit' => $deposit]]);

                return $record->title.' booked for '.$record->occurs_on->format('d M Y').'.';
            case 'cancel':
                $record->update(['status' => 'cancelled']);

                return $record->title.'\'s booking cancelled'.($this->number($record, 'deposit') > 0 ? '; the deposit of '.$this->money($this->number($record, 'deposit')).' is kept' : '').'.';
            case 'start':
                if ($problem = $this->notReady($record->data)) {
                    throw ValidationException::withMessages(['status' => $problem]);
                }
                $record->update(['status' => 'in_progress']);

                return 'Work on '.$record->title.' started.';
            case 'finish':
                $record->update(['status' => 'healed', 'data' => [...$record->data, '_finished_on' => today()->toDateString()]]);

                return $record->title.' finished'.($record->amount > 0 ? '; '.$this->money(max(0, $record->amount - $this->number($record, 'deposit'))).' to pay after the deposit' : '').'.';
            default:
                $date = today()->addWeeks(self::TOUCH_UP_WEEKS);
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'bookings', 'title' => $record->title,
                    'status' => 'booked', 'occurs_on' => $date, 'amount' => 0, 'contact_id' => $record->contact_id,
                    'data' => [...collect($record->data)->except(['_finished_on', 'deposit'])->all(), 'type' => 'touch_up', 'deposit' => 0, '_touch_up_of' => $record->id],
                ]);
                $record->update(['status' => 'touch_up']);

                return 'Touch-up for '.$record->title.' booked for '.$date->format('d M Y').'.';
        }
    }

    public function homeCards(): array
    {
        $today = $this->records('bookings')->whereIn('status', ['booked', 'in_progress'])->whereDate('occurs_on', today()->toDateString())->get();
        $names = User::query()->whereIn('id', $today->map(fn (Record $booking) => $booking->value('artist'))->filter()->unique())->pluck('name', 'id');

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Today', 'icon' => 'pen-tool', 'empty' => 'No appointments today.',
                'rows' => $today->map(fn (Record $booking) => [
                    'label' => $booking->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $booking->value('type'))).' · '.($names[$booking->value('artist')] ?? 'No artist'),
                    'value' => $this->notReady($booking->data) ? 'Forms missing' : 'Ready', 'href' => $booking->url(), 'tone' => $this->notReady($booking->data) ? 'warning' : 'success',
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Book', 'icon' => 'calendar', 'stats' => [
                ['label' => 'Consultations', 'value' => $this->records('bookings')->where('status', 'consultation')->count()],
                ['label' => 'Booked ahead', 'value' => $this->records('bookings')->where('status', 'booked')->whereDate('occurs_on', '>', today()->toDateString())->count()],
                ['label' => 'Deposits held', 'value' => $this->money($this->records('bookings')->where('status', 'booked')->get()->sum(fn (Record $booking) => $this->number($booking, 'deposit')))],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $done = $this->dated('bookings', $from, $to)->whereIn('status', ['healed', 'touch_up'])->get();
        $names = User::query()->whereIn('id', $done->map(fn (Record $booking) => $booking->value('artist'))->filter()->unique())->pluck('name', 'id');

        return [['title' => 'Work by artist', 'columns' => ['Artist', 'Tattoos', 'Piercings', 'Other', 'Takings'], 'rows' => $done
            ->groupBy(fn (Record $booking) => $names[$booking->value('artist')] ?? 'No artist')->sortKeys()
            ->map(fn ($group, string $artist) => [
                $artist,
                $group->filter(fn (Record $booking) => $booking->value('type') === 'tattoo')->count(),
                $group->filter(fn (Record $booking) => $booking->value('type') === 'piercing')->count(),
                $group->filter(fn (Record $booking) => ! in_array($booking->value('type'), ['tattoo', 'piercing'], true))->count(),
                $this->money($group->sum('amount')),
            ])->values()->all()]];
    }
}
