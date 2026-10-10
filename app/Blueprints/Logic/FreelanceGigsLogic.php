<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Freelancer toolkit: an hourly gig is worth its rate times the hours logged, a daily one its rate per
 * eight-hour day, and a fixed one its agreed rate. A gig is marked paid only once the invoice has gone
 * out. Each gig shows what it earned per hour. The home page shows money owed and deadlines, and the
 * reports compare earnings with business expenses month by month.
 */
class FreelanceGigsLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'gigs') {
            if ((float) ($data['rate'] ?? 0) < 0 || (float) ($data['hours'] ?? 0) < 0) {
                $errors['data.rate'] = 'The rate and hours cannot be negative.';
            }
            if ($payload['status'] === 'paid' && ! ($data['invoice_sent'] ?? false)) {
                $errors['status'] = 'Send the invoice before marking the gig paid.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The deadline cannot be before the start date.';
            }
        }
        if ($entity->key === 'expenses' && (float) ($payload['amount'] ?? 0) <= 0) {
            $errors['amount'] = 'Give an amount above zero.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity !== 'gigs') {
            return;
        }
        $rate = $this->number($record, 'rate');
        $hours = $this->number($record, 'hours');
        $total = match ($record->value('rate_type')) {
            'hourly' => round($rate * $hours, 2),
            'daily' => round($rate * $hours / 8, 2),
            default => $rate > 0 ? $rate : (float) $record->amount,
        };
        $record->amount = $total;
        $this->put($record, ['_per_hour' => $hours > 0 ? round($total / $hours, 2) : null]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'gigs') {
            return [];
        }

        return array_filter([
            'log' => in_array($record->status, ['booked', 'in_progress'], true) ? ['label' => 'Log hours', 'icon' => 'clock', 'fields' => [['name' => 'hours', 'label' => 'Hours', 'type' => 'number']]] : null,
            'deliver' => in_array($record->status, ['booked', 'in_progress'], true) ? ['label' => 'Delivered', 'icon' => 'package-check'] : null,
            'invoice' => $record->status === 'delivered' && ! $record->value('invoice_sent') ? ['label' => 'Invoice sent', 'icon' => 'send'] : null,
            'paid' => $record->status === 'delivered' && $record->value('invoice_sent') ? ['label' => 'Paid', 'icon' => 'banknote'] : null,
        ]);
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'log':
                $hours = (float) $request->validate(['hours' => ['required', 'numeric', 'gt:0', 'max:24']])['hours'];
                $record->update(['status' => 'in_progress', 'data' => [...$record->data, 'hours' => round($this->number($record, 'hours') + $hours, 2)]]);

                return 'Logged '.$hours.' hours on '.$record->title.'; '.$this->number($record, 'hours').' in total, worth '.$this->money($record->amount).'.';
            case 'deliver':
                $record->update(['status' => 'delivered']);

                return $record->title.' delivered. Send the invoice for '.$this->money($record->amount).'.';
            case 'invoice':
                $record->update(['data' => [...$record->data, 'invoice_sent' => true, '_invoiced_on' => today()->toDateString()]]);

                return 'Invoice for '.$this->money($record->amount).' sent'.($record->contact ? ' to '.$record->contact->name : '').'.';
            default:
                if (! $record->value('invoice_sent')) {
                    throw ValidationException::withMessages(['status' => 'Send the invoice before marking the gig paid.']);
                }
                $record->update(['status' => 'paid', 'data' => [...$record->data, '_paid_on' => today()->toDateString()]]);

                return $record->title.' paid: '.$this->money($record->amount).'.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'gigs') {
            return [];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Earnings', 'icon' => 'laptop', 'stats' => [
            ['label' => 'Total', 'value' => $this->money($record->amount)],
            ['label' => 'Hours', 'value' => $this->number($record, 'hours')],
            ['label' => 'Per hour', 'value' => $record->value('_per_hour') ? $this->money($this->number($record, '_per_hour')) : '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        $owed = $this->records('gigs')->where('status', 'delivered')->get();
        $deadlines = $this->records('gigs')->whereIn('status', ['booked', 'in_progress'])->whereNotNull('due_on')->whereDate('due_on', '<=', today()->addDays(7)->toDateString())->orderBy('due_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Money', 'icon' => 'banknote', 'stats' => [
                ['label' => 'Owed to you', 'value' => $this->money($owed->filter(fn (Record $gig) => $gig->value('invoice_sent'))->sum(fn (Record $gig) => (float) $gig->amount))],
                ['label' => 'Not invoiced yet', 'value' => $this->money($owed->reject(fn (Record $gig) => $gig->value('invoice_sent'))->sum(fn (Record $gig) => (float) $gig->amount)), 'tone' => $owed->contains(fn (Record $gig) => ! $gig->value('invoice_sent')) ? 'warning' : null],
                ['label' => 'Paid this month', 'value' => $this->money($this->records('gigs')->where('status', 'paid')->get()->filter(fn (Record $gig) => str_starts_with((string) $gig->value('_paid_on'), today()->format('Y-m')))->sum(fn (Record $gig) => (float) $gig->amount))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Deadlines', 'icon' => 'calendar-clock', 'empty' => 'No deadlines in the next week.',
                'rows' => $deadlines->map(fn (Record $gig) => ['label' => $gig->title, 'sub' => $gig->contact?->name, 'value' => $gig->due_on->format('D d M'), 'href' => $gig->url(), 'tone' => $gig->due_on->lt(today()) ? 'danger' : 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $paid = $this->records('gigs')->where('status', 'paid')->get()
            ->filter(fn (Record $gig) => Carbon::parse($gig->value('_paid_on') ?? $gig->occurs_on)->between($from->copy()->startOfDay(), $to->copy()->endOfDay()));
        $expenses = $this->dated('expenses', $from, $to)->get();
        $earned = [];
        foreach ($paid as $gig) {
            $month = Carbon::parse($gig->value('_paid_on') ?? $gig->occurs_on)->format('Y-m');
            $earned[$month] = ($earned[$month] ?? 0) + (float) $gig->amount;
        }
        $spent = $this->sumByMonth($expenses);

        return [
            ['title' => 'Profit by month', 'columns' => ['Month', 'Earned', 'Expenses', 'Profit'], 'rows' => collect($this->months($from, $to))
                ->map(fn (string $label, string $key) => [$label, $this->money($earned[$key] ?? 0), $this->money($spent[$key] ?? 0), $this->money(($earned[$key] ?? 0) - ($spent[$key] ?? 0))])->values()->all()],
            ['title' => 'Earnings by platform', 'columns' => ['Platform', 'Gigs paid', 'Earned', 'Average per hour'], 'rows' => $paid
                ->groupBy(fn (Record $gig) => ucfirst((string) ($gig->value('platform') ?: 'direct')))->sortKeys()
                ->map(function ($group, string $platform) {
                    $hours = $group->sum(fn (Record $gig) => $this->number($gig, 'hours'));

                    return [$platform, $group->count(), $this->money($group->sum(fn (Record $gig) => (float) $gig->amount)), $hours > 0 ? $this->money($group->sum(fn (Record $gig) => (float) $gig->amount) / $hours) : '—'];
                })->values()->all()],
        ];
    }
}
