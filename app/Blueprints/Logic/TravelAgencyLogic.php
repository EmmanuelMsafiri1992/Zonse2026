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
 * Travel agency: a booking moves from quote to booked, ticketed and travelled. A flight can't be
 * booked without its PNR, the ticketing deadline must fall before travel and the commission can't
 * exceed the selling price. Only cancelled bookings are refunded. Each day, booked reservations that
 * missed their ticketing deadline are cancelled (the airline drops them) and ticketed trips whose
 * travel date has passed are marked travelled. Visa applications need a passport number to be
 * submitted, are decided only once submitted and collected only once approved; the home page warns
 * about visas still undecided within two weeks of travel.
 */
class TravelAgencyLogic extends AppLogic
{
    /**
     * The order of a booking's steps.
     *
     * @var list<string>
     */
    protected const STEPS = ['quoted', 'booked', 'ticketed', 'travelled'];

    /**
     * What each visa status may move to.
     *
     * @var array<string, list<string>>
     */
    protected const VISA_MOVES = [
        'documents_pending' => ['submitted'],
        'submitted' => ['approved', 'rejected'],
        'approved' => ['collected'],
        'rejected' => ['submitted'],
        'collected' => [],
    ];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        return $entity->key === 'visas' ? $this->validateVisa($payload, $existing) : $this->validateBooking($payload, $existing);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateBooking(array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($existing && $existing->status !== $status) {
            if (in_array($existing->status, ['travelled', 'refunded'], true)) {
                $errors['status'] = 'This booking is '.$existing->status.'.';
            } elseif ($status === 'refunded' && $existing->status !== 'cancelled') {
                $errors['status'] = 'Cancel the booking before refunding it.';
            } elseif ($existing->status === 'cancelled' && $status !== 'refunded') {
                $errors['status'] = 'This booking is cancelled.';
            } elseif (in_array($status, self::STEPS, true) && array_search($status, self::STEPS, true) < array_search($existing->status, self::STEPS, true)) {
                $errors['status'] = 'A booking cannot go back a step.';
            }
        }
        if (! $existing && $status === 'refunded') {
            $errors['status'] = 'Cancel the booking before refunding it.';
        }
        if (($data['type'] ?? null) === 'flight' && in_array($status, ['booked', 'ticketed', 'travelled'], true) && blank($data['pnr'] ?? null)) {
            $errors['data.pnr'] = 'Enter the PNR from the airline.';
        }
        if (filled($payload['due_on'] ?? null) && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['due_on'])->gt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The ticketing deadline must be before the travel date.';
        }
        if (filled($data['commission'] ?? null) && filled($payload['amount'] ?? null) && (float) $data['commission'] > (float) $payload['amount']) {
            $errors['data.commission'] = 'The commission cannot be more than the selling price.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateVisa(array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($existing && $status !== $existing->status && ! in_array($status, self::VISA_MOVES[$existing->status] ?? [], true)) {
            $errors['status'] = 'A visa that is '.str_replace('_', ' ', $existing->status).' cannot be marked '.str_replace('_', ' ', $status).'.';
        }
        if ($status !== 'documents_pending' && blank($data['passport_number'] ?? null)) {
            $errors['data.passport_number'] = 'Enter the passport number before submitting.';
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(Carbon::parse($payload['due_on']))) {
            $errors['occurs_on'] = 'The application cannot be submitted after the travel date.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'bookings') {
            $price = (float) $record->amount;
            $this->put($record, ['_margin' => $price > 0 ? round($this->number($record, 'commission') / $price * 100, 1) : null]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'visas') {
            return match ($record->status) {
                'documents_pending', 'rejected' => ['submit' => ['label' => 'Submitted', 'icon' => 'send', 'fields' => [['name' => 'embassy_reference', 'label' => 'Embassy reference', 'type' => 'text', 'value' => $record->value('embassy_reference')]]]],
                'submitted' => ['approve' => ['label' => 'Approved', 'icon' => 'check'], 'reject' => ['label' => 'Rejected', 'icon' => 'x']],
                'approved' => ['collect' => ['label' => 'Passport collected', 'icon' => 'stamp']],
                default => [],
            };
        }

        $cancel = ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this booking?'];

        return match ($record->status) {
            'quoted' => ['book' => ['label' => 'Book', 'icon' => 'check', 'fields' => [['name' => 'pnr', 'label' => 'PNR / booking reference', 'type' => 'text', 'value' => $record->value('pnr')]]], 'cancel' => $cancel],
            'booked' => ['ticket' => ['label' => 'Issue ticket', 'icon' => 'ticket'], 'cancel' => $cancel],
            'ticketed' => ['cancel' => $cancel],
            'cancelled' => ['refund' => ['label' => 'Refunded', 'icon' => 'undo-2', 'fields' => [['name' => 'refund', 'label' => 'Amount refunded', 'type' => 'number', 'value' => $record->amount]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'visas') {
            if ($action === 'submit') {
                if (blank($record->value('passport_number'))) {
                    throw ValidationException::withMessages(['passport_number' => 'Enter the passport number before submitting.']);
                }
                $reference = trim((string) $request->input('embassy_reference')) ?: $record->value('embassy_reference');
                $record->update(['status' => 'submitted', 'occurs_on' => today(), 'data' => [...$record->data, 'embassy_reference' => $reference]]);

                return $record->title.'\'s '.$record->value('country').' visa submitted.';
            }
            $record->update(['status' => ['approve' => 'approved', 'reject' => 'rejected', 'collect' => 'collected'][$action]]);

            return $record->title.'\'s '.$record->value('country').' visa '.$record->status.'.';
        }

        switch ($action) {
            case 'book':
                $pnr = trim((string) $request->input('pnr')) ?: $record->value('pnr');
                if ($record->value('type') === 'flight' && blank($pnr)) {
                    throw ValidationException::withMessages(['pnr' => 'Enter the PNR from the airline.']);
                }
                $record->update(['status' => 'booked', 'data' => [...$record->data, 'pnr' => $pnr]]);

                return $record->title.' booked'.($pnr ? ' ('.$pnr.')' : '').($record->due_on ? '; ticket by '.$record->due_on->format('d M Y').'.' : '.');
            case 'ticket':
                $record->update(['status' => 'ticketed', 'data' => [...$record->data, '_ticketed_on' => today()->toDateString()]]);

                return $record->title.' ticketed.';
            case 'refund':
                $refund = (float) $request->validate(['refund' => ['required', 'numeric', 'min:0']])['refund'];
                if ($refund > (float) $record->amount) {
                    throw ValidationException::withMessages(['refund' => 'The refund cannot be more than the selling price.']);
                }
                $record->update(['status' => 'refunded', 'data' => [...$record->data, '_refund' => $refund]]);

                return $this->money($refund).' refunded to '.$record->title.'.';
            default:
                $record->update(['status' => 'cancelled']);

                return $record->title.'\'s booking cancelled.';
        }
    }

    public function daily(Workspace $workspace): int
    {
        $missed = $this->records('bookings')->where('status', 'booked')->whereNotNull('due_on')->where('due_on', '<', today()->startOfDay())->get();
        $missed->each(fn (Record $booking) => $booking->update(['status' => 'cancelled', 'data' => [...$booking->data, '_cancel_reason' => 'Ticketing deadline missed']]));
        $travelled = $this->records('bookings')->where('status', 'ticketed')->whereNotNull('occurs_on')->where('occurs_on', '<', today()->startOfDay())->get();
        $travelled->each(fn (Record $booking) => $booking->update(['status' => 'travelled']));

        return $missed->count() + $travelled->count();
    }

    public function homeCards(): array
    {
        $deadlines = $this->records('bookings')->where('status', 'booked')->whereNotNull('due_on')->where('due_on', '<=', today()->addDays(3)->endOfDay())->orderBy('due_on')->get();
        $visas = $this->records('visas')->whereIn('status', ['documents_pending', 'submitted'])->whereNotNull('due_on')->where('due_on', '<=', today()->addDays(14)->endOfDay())->orderBy('due_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Tickets to issue', 'icon' => 'ticket', 'empty' => 'No ticketing deadlines in the next three days.',
                'rows' => $deadlines->map(fn (Record $booking) => ['label' => $booking->title, 'sub' => $booking->value('route').($booking->value('pnr') ? ' · '.$booking->value('pnr') : ''), 'value' => 'by '.$booking->due_on->format('d M'), 'href' => $booking->url(), 'tone' => $booking->due_on->lte(today()) ? 'danger' : 'warning'])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Visas undecided before travel', 'icon' => 'stamp', 'empty' => 'No visas at risk.',
                'rows' => $visas->map(fn (Record $visa) => ['label' => $visa->title, 'sub' => $visa->value('country').' · '.str_replace('_', ' ', $visa->status), 'value' => 'travels '.$visa->due_on->format('d M'), 'href' => $visa->url(), 'tone' => $visa->status === 'documents_pending' ? 'danger' : 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $bookings = $this->dated('bookings', $from, $to)->get();
        $sold = $bookings->whereIn('status', ['booked', 'ticketed', 'travelled']);

        $byType = $sold->groupBy(fn (Record $booking) => (string) $booking->value('type'))->sortKeys()
            ->map(fn (Collection $group, string $type) => [ucfirst($type ?: 'other'), $group->count(), $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $booking) => $this->number($booking, 'commission')))])->values()->all();

        $bySupplier = $sold->groupBy(fn (Record $booking) => trim((string) $booking->value('supplier')) ?: '—')->sortKeys()
            ->map(function (Collection $group, string $supplier) {
                $sales = $group->sum('amount');
                $commission = $group->sum(fn (Record $booking) => $this->number($booking, 'commission'));

                return [$supplier, $group->count(), $this->money($sales), $this->money($commission), $sales > 0 ? round($commission / $sales * 100, 1).'%' : '—'];
            })->values()->all();

        $visas = $this->dated('visas', $from, $to)->whereIn('status', ['approved', 'rejected', 'collected'])->get()
            ->groupBy(fn (Record $visa) => (string) $visa->value('country'))->sortKeys()
            ->map(function (Collection $group, string $country) {
                $approved = $group->whereIn('status', ['approved', 'collected'])->count();

                return [$country, $group->count(), $approved, $group->count() - $approved, (int) round($approved / $group->count() * 100).'%'];
            })->values()->all();

        $lost = $bookings->whereIn('status', ['cancelled', 'refunded'])
            ->map(fn (Record $booking) => [$booking->title, $booking->value('route'), $booking->value('_cancel_reason') ?? ucfirst($booking->status), $this->money($booking->value('_refund'))])->values()->all();

        return [
            ['title' => 'Sales by type', 'columns' => ['Type', 'Bookings', 'Sales', 'Commission'], 'rows' => $byType],
            ['title' => 'Commission by supplier', 'columns' => ['Supplier', 'Bookings', 'Sales', 'Commission', 'Margin'], 'rows' => $bySupplier],
            ['title' => 'Visa outcomes', 'columns' => ['Country', 'Decided', 'Approved', 'Rejected', 'Approval rate'], 'rows' => $visas],
            ['title' => 'Cancellations and refunds', 'columns' => ['Traveller', 'Route', 'Reason', 'Refunded'], 'rows' => $lost],
        ];
    }
}
