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
 * Catering & event food orders: the menu is written one dish per line, optionally priced per head
 * ("Chicken stew @ 45"), and the quote is the guests times the per-head price until it is changed by
 * hand. The kitchen cooks for the guests plus a 5% margin, and the staff needed follow the style of
 * service when left blank. A job moves forward from enquiry to invoiced, a quote needs a price and a
 * confirmed job needs a date. Each day, quotes whose event date has passed are closed as lost.
 */
class CateringLogic extends AppLogic
{
    /**
     * The order of the steps, so a job only moves forward.
     *
     * @var list<string>
     */
    protected const STEPS = ['enquiry', 'quoted', 'confirmed', 'delivered', 'invoiced'];

    /**
     * Guests each member of staff looks after, by style of service.
     *
     * @var array<string, int>
     */
    protected const GUESTS_PER_STAFF = ['plated' => 10, 'canapes' => 20, 'buffet' => 25, 'boxed' => 50, 'drop_off' => 50];

    /**
     * Extra portions cooked on top of the guest count.
     */
    protected const SPARE = 0.05;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($existing && in_array($existing->status, ['invoiced', 'cancelled'], true) && $status !== $existing->status) {
            $errors['status'] = 'This job is '.$existing->status.' and closed.';
        }
        if ($existing && $status !== 'cancelled' && array_search($status, self::STEPS, true) < array_search($existing->status, self::STEPS, true)) {
            $errors['status'] = 'A job cannot go back a step.';
        }
        if (filled($data['guests'] ?? null) && (int) $data['guests'] < 1) {
            $errors['data.guests'] = 'Cater for at least one guest.';
        }
        if (in_array($status, ['confirmed', 'delivered', 'invoiced'], true) && blank($payload['occurs_on'] ?? null)) {
            $errors['occurs_on'] = 'Set the event date before confirming.';
        }
        if ($status === 'confirmed' && (! $existing || $existing->status !== 'confirmed') && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->lt(today())) {
            $errors['occurs_on'] = 'The event date has passed.';
        }
        [, $problems] = $this->parseMenu((string) ($data['menu'] ?? ''));
        if ($problems) {
            $errors['data.menu'] = implode(' ', $problems);
        }

        return $errors;
    }

    /**
     * Read menu lines, with an optional price per head after "@".
     *
     * @return array{0: list<array{dish: string, per_head: float|null}>, 1: list<string>}
     */
    protected function parseMenu(string $menu): array
    {
        $dishes = [];
        $problems = [];
        foreach (preg_split('/\R/', $menu) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! preg_match('/^\s*[-*•]?\s*(.+?)\s*(?:@\s*(\S+))?\s*$/u', $line, $match)) {
                continue;
            }
            if (isset($match[2]) && ! is_numeric($match[2])) {
                $problems[] = 'The price for "'.$match[1].'" should be a number per head.';

                continue;
            }
            $dishes[] = ['dish' => $match[1], 'per_head' => isset($match[2]) ? (float) $match[2] : null];
        }

        return [$dishes, $problems];
    }

    public function saving(Record $record): void
    {
        [$dishes] = $this->parseMenu((string) $record->value('menu'));
        $guests = max(1, (int) $record->value('guests'));
        $perHead = round(collect($dishes)->sum('per_head'), 2);
        $quoted = $perHead * $guests;

        $previous = (float) $record->value('_per_head') * (int) $record->value('_guests');
        $byHand = $record->amount !== null && abs((float) $record->amount - $previous) >= 0.01;
        if ($perHead > 0 && ! $byHand) {
            $record->amount = $quoted;
        }

        $service = (string) ($record->value('service') ?: 'buffet');
        $staff = (int) ceil($guests / (self::GUESTS_PER_STAFF[$service] ?? 25));
        if (blank($record->value('staff_needed'))) {
            $this->put($record, ['staff_needed' => $staff]);
        }

        $this->put($record, [
            '_dishes' => $dishes,
            '_per_head' => $perHead,
            '_guests' => $guests,
            '_portions' => (int) ceil($guests * (1 + self::SPARE)),
            '_staff_suggested' => $staff,
        ]);
    }

    public function actions(Record $record): array
    {
        $cancel = ['label' => 'Cancel', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]];

        return match ($record->status) {
            'enquiry' => ['quote' => ['label' => 'Send quote', 'icon' => 'send'], 'cancel' => $cancel],
            'quoted' => ['confirm' => ['label' => 'Confirm job', 'icon' => 'check'], 'cancel' => $cancel],
            'confirmed' => ['deliver' => ['label' => 'Delivered', 'icon' => 'truck'], 'cancel' => $cancel],
            'delivered' => ['invoice' => ['label' => 'Invoiced', 'icon' => 'receipt']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'quote':
                if ((float) $record->amount <= 0) {
                    throw ValidationException::withMessages(['amount' => 'Price the menu or enter the quote first.']);
                }
                $record->update(['status' => 'quoted', 'data' => [...$record->data, '_quoted_on' => today()->toDateString()]]);

                return 'Quote for '.$record->title.' sent: '.$this->money($record->amount).' for '.(int) $record->value('guests').' guests.';
            case 'confirm':
                if (! $record->occurs_on) {
                    throw ValidationException::withMessages(['occurs_on' => 'Set the event date before confirming.']);
                }
                if ($record->occurs_on->lt(today())) {
                    throw ValidationException::withMessages(['occurs_on' => 'The event date has passed.']);
                }
                $record->update(['status' => 'confirmed']);

                return $record->title.' confirmed for '.$record->occurs_on->format('d M Y').': cook '.(int) $record->value('_portions').' portions, '.(int) $record->value('staff_needed').' staff.';
            case 'deliver':
                $record->update(['status' => 'delivered']);

                return $record->title.' delivered.';
            case 'invoice':
                $record->update(['status' => 'invoiced']);

                return $record->title.' invoiced for '.$this->money($record->amount).'.';
            default:
                $reason = trim((string) $request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
                $record->update(['status' => 'cancelled', 'data' => [...$record->data, '_cancel_reason' => $reason]]);

                return $record->title.' cancelled: '.$reason.'.';
        }
    }

    public function daily(Workspace $workspace): int
    {
        $lost = $this->records('events')->whereIn('status', ['enquiry', 'quoted'])->whereNotNull('occurs_on')->where('occurs_on', '<', today()->startOfDay())->get();
        $lost->each(fn (Record $job) => $job->update(['status' => 'cancelled', 'data' => [...$job->data, '_cancel_reason' => 'Event date passed without confirmation']]));

        return $lost->count();
    }

    public function recordCards(Record $record): array
    {
        $portions = (int) $record->value('_portions');

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Production sheet', 'icon' => 'chef-hat', 'empty' => 'No dishes on the menu.',
            'rows' => collect($record->value('_dishes') ?? [])->map(fn (array $dish) => [
                'label' => $dish['dish'],
                'sub' => $dish['per_head'] !== null ? $this->money($dish['per_head']).' per head' : null,
                'value' => $portions.' portions',
            ])->push(['label' => 'Staff', 'value' => (int) $record->value('staff_needed').' (suggested '.(int) $record->value('_staff_suggested').')'])->values()->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $coming = $this->records('events')->where('status', 'confirmed')->whereBetween('occurs_on', [today()->startOfDay(), today()->addDays(13)->endOfDay()])->orderBy('occurs_on')->get();
        $quotes = $this->records('events')->where('status', 'quoted')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Catering', 'icon' => 'cooking-pot', 'stats' => [
                ['label' => 'Jobs in the next 2 weeks', 'value' => (string) $coming->count()],
                ['label' => 'Portions to cook', 'value' => number_format($coming->sum(fn (Record $job) => (int) $job->value('_portions')))],
                ['label' => 'Staff needed', 'value' => (string) $coming->sum(fn (Record $job) => (int) $job->value('staff_needed'))],
                ['label' => 'Quotes waiting', 'value' => $quotes->count().' · '.$this->money($quotes->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Coming up', 'icon' => 'calendar-days', 'empty' => 'No confirmed jobs in the next two weeks.',
                'rows' => $coming->map(fn (Record $job) => ['label' => $job->title, 'sub' => $job->occurs_on->format('D d M').($job->value('venue') ? ' · '.$job->value('venue') : ''), 'value' => (int) $job->value('_portions').' portions', 'href' => $job->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $jobs = $this->dated('events', $from, $to)->get();
        $won = $jobs->whereIn('status', ['confirmed', 'delivered', 'invoiced']);

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($won) {
            $group = $won->filter(fn (Record $job) => ($job->occurs_on ?? $job->created_at)->format('Y-m') === $month);

            return [$label, $group->count(), $group->sum(fn (Record $job) => (int) $job->value('guests')), $this->money($group->sum('amount'))];
        })->values()->all();

        $byService = $won->groupBy(fn (Record $job) => $job->value('service') ?: 'buffet')->sortKeys()
            ->map(fn (Collection $group, string $service) => [ucfirst(str_replace('_', ' ', $service)), $group->count(), $this->money($group->sum('amount') / max(1, $group->sum(fn (Record $job) => (int) $job->value('guests'))))])->values()->all();

        $quoted = $jobs->filter(fn (Record $job) => $job->value('_quoted_on') !== null || $job->status !== 'enquiry');
        $lost = $quoted->where('status', 'cancelled');

        return [
            ['title' => 'Jobs by month', 'columns' => ['Month', 'Jobs', 'Guests', 'Revenue'], 'rows' => $byMonth],
            ['title' => 'By style of service', 'columns' => ['Service', 'Jobs', 'Average per head'], 'rows' => $byService],
            ['title' => 'Quotes won and lost', 'columns' => ['Quoted', 'Won', 'Lost', 'Win rate'], 'rows' => [[
                $quoted->count(), $won->count(), $lost->count(), $won->count() + $lost->count() ? (int) round($won->count() / ($won->count() + $lost->count()) * 100).'%' : '—',
            ]]],
        ];
    }
}
