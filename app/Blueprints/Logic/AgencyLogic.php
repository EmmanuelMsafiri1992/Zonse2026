<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Advertising agency job bags: a job moves forward from brief to billed, except that the client can
 * send work back from review, which counts a revision. Approval needs the job's value. Billing adds
 * the media booked for the job and the agency's markup on it, and waits until every booking is
 * confirmed. Media bookings move forward too; confirming one needs the media owner and its cost,
 * and a billed job takes no more bookings.
 */
class AgencyLogic extends AppLogic
{
    /**
     * Job steps in order.
     */
    protected const JOB_STEPS = ['brief', 'in_progress', 'client_review', 'approved', 'delivered', 'billed'];

    /**
     * Booking steps in order.
     */
    protected const BOOKING_STEPS = ['requested', 'confirmed', 'run', 'invoiced'];

    /**
     * Commission the agency adds to media it books, as a fraction.
     */
    public const MARKUP = 0.15;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        return $entity->key === 'jobs' ? $this->validateJob($payload, $existing) : $this->validateBooking($payload, $existing);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateJob(array $payload, ?Record $existing): array
    {
        $status = $payload['status'];
        $errors = [];
        if ($existing && $status !== $existing->status) {
            $back = array_search($status, self::JOB_STEPS, true) < array_search($existing->status, self::JOB_STEPS, true);
            if ($back && ! ($existing->status === 'client_review' && $status === 'in_progress')) {
                $errors['status'] = 'A job cannot go back to '.str_replace('_', ' ', $status).'.';
            }
            if ($status === 'billed') {
                $errors['status'] = 'Bill the job with the Bill action so its media is added.';
            }
        }
        if (in_array($status, ['approved', 'delivered', 'billed'], true) && (float) ($payload['amount'] ?? 0) <= 0) {
            $errors['amount'] = 'Agree the job value before it is approved.';
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The deadline cannot be before the job was opened.';
        }

        return $errors;
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
        $job = filled($data['job'] ?? null) ? $this->records('jobs')->find($data['job']) : null;
        if ($job?->status === 'billed' && (! $existing || (float) $existing->amount !== (float) ($payload['amount'] ?? 0) || (int) $existing->value('job') !== $job->id)) {
            $errors['data.job'] = $job->title.' has been billed; open a new job bag for more media.';
        }
        if ($existing && array_search($status, self::BOOKING_STEPS, true) < array_search($existing->status, self::BOOKING_STEPS, true)) {
            $errors['status'] = 'A booking cannot go back to '.$status.'.';
        }
        if ($status !== 'requested') {
            if (blank($data['vendor'] ?? null)) {
                $errors['data.vendor'] = 'Name the media owner before confirming.';
            }
            if ((float) ($payload['amount'] ?? 0) <= 0) {
                $errors['amount'] = 'Give the cost before confirming.';
            }
        }
        if (filled($data['insertions'] ?? null) && (int) $data['insertions'] < 1) {
            $errors['data.insertions'] = 'Book at least one insertion.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'jobs') {
            if ($record->isDirty('status') && $record->getOriginal('status') === 'client_review' && $record->status === 'in_progress') {
                $this->put($record, ['_revisions' => (int) $record->value('_revisions') + 1]);
            }
            $record->occurs_on ??= today();

            return;
        }
        if (blank($record->value('insertions'))) {
            $this->put($record, ['insertions' => 1]);
        }
    }

    /**
     * What a job bills: its value, the media booked for it and the markup on that media.
     *
     * @return array{media: float, markup: float, total: float}
     */
    public function bill(Record $job): array
    {
        $media = round((float) $this->linked('bookings', 'job', $job)->sum('amount'), 2);
        $markup = round($media * self::MARKUP, 2);

        return ['media' => $media, 'markup' => $markup, 'total' => round((float) $job->amount + $media + $markup, 2)];
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'bookings') {
            return match ($record->status) {
                'requested' => ['confirm' => ['label' => 'Confirm', 'icon' => 'check']],
                'confirmed' => ['run' => ['label' => 'Ran', 'icon' => 'radio-tower']],
                'run' => ['invoiced' => ['label' => 'Invoiced', 'icon' => 'receipt']],
                default => [],
            };
        }

        return match ($record->status) {
            'brief' => ['start' => ['label' => 'Start work', 'icon' => 'play']],
            'in_progress' => ['review' => ['label' => 'Send to client', 'icon' => 'send']],
            'client_review' => ['approve' => ['label' => 'Client approved', 'icon' => 'thumbs-up'], 'revise' => ['label' => 'Client wants changes', 'icon' => 'undo-2', 'fields' => [['name' => 'changes', 'label' => 'Changes asked for', 'type' => 'text']]]],
            'approved' => ['deliver' => ['label' => 'Delivered', 'icon' => 'package-check']],
            'delivered' => ['bill' => ['label' => 'Bill client', 'icon' => 'receipt']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'start':
                $record->update(['status' => 'in_progress']);

                return $record->title.' is in progress.';
            case 'review':
                $record->update(['status' => 'client_review']);

                return $record->title.' is with the client.';
            case 'revise':
                $changes = trim($request->validate(['changes' => ['required', 'string', 'max:255']])['changes']);
                $record->update(['status' => 'in_progress', 'data' => [...$record->data, '_last_changes' => $changes]]);

                return $record->title.' is back in progress (revision '.$record->value('_revisions').'): '.$changes.'.';
            case 'approve':
                if ((float) $record->amount <= 0) {
                    throw ValidationException::withMessages(['amount' => 'Agree the job value before it is approved.']);
                }
                $record->update(['status' => 'approved']);

                return $record->title.' approved.';
            case 'deliver':
                $record->update(['status' => 'delivered']);

                return $record->title.' delivered.';
            case 'bill':
                $waiting = $this->linked('bookings', 'job', $record)->where('status', 'requested')->count();
                if ($waiting > 0) {
                    throw ValidationException::withMessages(['bookings' => $waiting.' media '.str('booking')->plural($waiting).' still '.($waiting === 1 ? 'needs' : 'need').' confirming.']);
                }
                $bill = $this->bill($record);
                $record->update(['status' => 'billed', 'data' => [...$record->data, '_media_cost' => $bill['media'], '_markup' => $bill['markup'], '_bill_total' => $bill['total'], '_billed_on' => today()->toDateString()]]);

                return $record->title.' billed: '.$this->money($bill['total']).' ('.$this->money($record->amount).' work, '.$this->money($bill['media']).' media, '.$this->money($bill['markup']).' commission).';
            default:
                $status = $action === 'confirm' ? 'confirmed' : $action;
                if ($status === 'confirmed' && (blank($record->value('vendor')) || (float) $record->amount <= 0)) {
                    throw ValidationException::withMessages(['vendor' => 'Name the media owner and the cost before confirming.']);
                }
                $record->update(['status' => $status]);

                return $record->title.' '.$status.'.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'jobs') {
            return [];
        }
        $bill = $record->status === 'billed'
            ? ['media' => $this->number($record, '_media_cost'), 'markup' => $this->number($record, '_markup'), 'total' => $this->number($record, '_bill_total')]
            : $this->bill($record);
        $bookings = $this->linked('bookings', 'job', $record)->orderBy('occurs_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => $record->status === 'billed' ? 'Billed' : 'To bill', 'icon' => 'receipt', 'stats' => [
                ['label' => 'Job value', 'value' => $this->money($record->amount)],
                ['label' => 'Media', 'value' => $this->money($bill['media'])],
                ['label' => 'Commission ('.(self::MARKUP * 100).'%)', 'value' => $this->money($bill['markup'])],
                ['label' => 'Total', 'value' => $this->money($bill['total'])],
                ['label' => 'Revisions', 'value' => (int) $record->value('_revisions')],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Media booked', 'icon' => 'radio-tower', 'empty' => 'No media booked.',
                'rows' => $bookings->map(fn (Record $booking) => ['label' => $booking->title, 'sub' => ucfirst((string) $booking->value('media')).' · '.($booking->value('vendor') ?: 'no media owner yet'), 'value' => $this->money($booking->amount), 'href' => $booking->url(), 'tone' => $booking->status === 'requested' ? 'warning' : null])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $open = $this->records('jobs')->whereNotIn('status', ['delivered', 'billed'])->whereNotNull('due_on')->orderBy('due_on')->limit(10)->get();
        $unbilled = $this->records('jobs')->where('status', 'delivered')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Job bags', 'icon' => 'briefcase', 'stats' => [
                ['label' => 'Open jobs', 'value' => $this->records('jobs')->whereNotIn('status', ['delivered', 'billed'])->count()],
                ['label' => 'With clients', 'value' => $this->records('jobs')->where('status', 'client_review')->count()],
                ['label' => 'Delivered, not billed', 'value' => $this->money($unbilled->sum(fn (Record $job) => $this->bill($job)['total'])), 'tone' => $unbilled->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Deadlines', 'icon' => 'calendar-clock', 'empty' => 'No open deadlines.',
                'rows' => $open->map(fn (Record $job) => ['label' => $job->title, 'sub' => trim(($job->contact?->name ?? '').' · '.str_replace('_', ' ', $job->status), ' ·'), 'value' => $job->due_on->format('d M'), 'href' => $job->url(), 'tone' => $job->due_on->lt(today()) ? 'danger' : null])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $jobs = $this->dated('jobs', $from, $to)->with('contact')->get();
        $bookings = $this->dated('bookings', $from, $to)->get();

        return [
            ['title' => 'Jobs by client', 'columns' => ['Client', 'Jobs', 'Billed', 'Work billed', 'Media billed', 'Commission', 'Average revisions'], 'rows' => $jobs->groupBy(fn (Record $job) => $job->contact?->name ?? 'No client')->sortKeys()
                ->map(function (Collection $group, string $client) {
                    $billed = $group->where('status', 'billed');

                    return [$client, $group->count(), $billed->count(), $this->money($billed->sum('amount')), $this->money($billed->sum(fn (Record $job) => $this->number($job, '_media_cost'))),
                        $this->money($billed->sum(fn (Record $job) => $this->number($job, '_markup'))), number_format($group->avg(fn (Record $job) => (int) $job->value('_revisions')), 1)];
                })->values()->all()],
            ['title' => 'Media by type', 'columns' => ['Media', 'Bookings', 'Insertions', 'Cost', 'Still requested'], 'rows' => $bookings->groupBy(fn (Record $booking) => ucfirst((string) $booking->value('media')))->sortKeys()
                ->map(fn (Collection $group, string $media) => [$media, $group->count(), $group->sum(fn (Record $booking) => (int) $booking->value('insertions')), $this->money($group->sum('amount')), $group->where('status', 'requested')->count()])->values()->all()],
        ];
    }
}
