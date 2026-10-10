<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Legal practice: time is logged in hours of up to 24 a day, and its value is the hours times the rate.
 * Time and court dates only go on open matters. A matter can't be closed while it has unbilled time; bill it
 * or write it off first. Billing a matter marks all its unbilled time as billed. Postponing a hearing books
 * the new date and keeps the old one as postponed.
 */
class LegalLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'matters') {
            if ($payload['status'] === 'closed' && $existing && $existing->status !== 'closed' && ($hours = $this->unbilled($existing)->sum(fn (Record $entry) => $this->number($entry, 'hours'))) > 0) {
                $errors['status'] = $existing->title.' still has '.$this->hours($hours).' unbilled; bill or write it off first.';
            }

            return $errors;
        }
        if (! $existing && filled($data['matter'] ?? null) && ($matter = $this->records('matters')->find($data['matter'])) && $matter->status !== 'open') {
            $errors['data.matter'] = $matter->title.' is '.str_replace('_', ' ', $matter->status).'.';
        }
        if ($entity->key === 'time_entries' && ((float) ($data['hours'] ?? 0) <= 0 || (float) ($data['hours'] ?? 0) > 24)) {
            $errors['data.hours'] = 'Hours must be more than 0 and no more than 24.';
        }

        return $errors;
    }

    /**
     * A matter's unbilled time entries.
     *
     * @return Collection<int, Record>
     */
    protected function unbilled(Record $matter)
    {
        return $this->linked('time_entries', 'matter', $matter)->where('status', 'unbilled')->get();
    }

    /**
     * Hours written for people, like "1 hour" or "2.5 hours".
     */
    protected function hours(float $hours): string
    {
        $hours = round($hours, 2);

        return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.').' '.str('hour')->plural($hours == 1 ? 1 : 2);
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'time_entries') {
            $record->amount = round($this->number($record, 'hours') * $this->number($record, 'rate'), 2);
        }
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'matters' && $record->status === 'open' => [
                ...($this->unbilled($record)->isNotEmpty() ? ['bill' => ['label' => 'Bill unbilled time', 'icon' => 'receipt']] : []),
                'close' => ['label' => 'Close matter', 'icon' => 'archive'],
            ],
            $record->entity === 'time_entries' && $record->status === 'unbilled' => ['write_off' => ['label' => 'Write off', 'icon' => 'eraser']],
            $record->entity === 'court_dates' && $record->status === 'scheduled' => [
                'attended' => ['label' => 'Attended', 'icon' => 'check', 'fields' => [['name' => 'outcome', 'label' => 'Outcome', 'type' => 'textarea', 'value' => '']]],
                'postpone' => ['label' => 'Postponed', 'icon' => 'calendar-x', 'fields' => [['name' => 'date', 'label' => 'New date', 'type' => 'date', 'value' => '']]],
            ],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'bill':
                $entries = $this->unbilled($record);
                $entries->each(fn (Record $entry) => $entry->update(['status' => 'billed']));

                return 'Billed '.$this->hours($entries->sum(fn (Record $entry) => $this->number($entry, 'hours'))).' ('.$this->money($entries->sum('amount')).') on '.$record->title.'.';
            case 'close':
                if (($hours = $this->unbilled($record)->sum(fn (Record $entry) => $this->number($entry, 'hours'))) > 0) {
                    throw ValidationException::withMessages(['status' => $record->title.' still has '.$this->hours($hours).' unbilled; bill or write it off first.']);
                }
                $record->update(['status' => 'closed']);

                return $record->title.' closed.';
            case 'write_off':
                $record->update(['status' => 'written_off']);

                return $this->hours($this->number($record, 'hours')).' on '.$record->title.' written off.';
            case 'attended':
                $outcome = trim((string) ($request->validate(['outcome' => ['nullable', 'string']])['outcome'] ?? ''));
                $record->update(['status' => 'attended', 'data' => [...$record->data, 'outcome' => $outcome ?: $record->value('outcome')]]);

                return $record->title.' attended.';
            default:
                $date = Carbon::parse($request->validate(['date' => ['required', 'date', 'after:today']], ['date.required' => 'Give the new date.'])['date']);
                $record->update(['status' => 'postponed']);
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'court_dates', 'title' => $record->title,
                    'status' => 'scheduled', 'occurs_on' => $date, 'assignee_id' => $record->assignee_id, 'data' => collect($record->data)->except('outcome')->all(),
                ]);

                return $record->title.' postponed to '.$date->format('d M Y').'.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'matters') {
            return [];
        }
        $entries = $this->linked('time_entries', 'matter', $record)->get();
        $hearings = $this->linked('court_dates', 'matter', $record)->where('status', 'scheduled')->orderBy('occurs_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Time', 'icon' => 'timer', 'stats' => [
                ['label' => 'Unbilled', 'value' => $this->hours($entries->where('status', 'unbilled')->sum(fn (Record $entry) => $this->number($entry, 'hours'))).' · '.$this->money($entries->where('status', 'unbilled')->sum('amount'))],
                ['label' => 'Billed', 'value' => $this->money($entries->where('status', 'billed')->sum('amount'))],
                ['label' => 'Written off', 'value' => $this->money($entries->where('status', 'written_off')->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Coming hearings', 'icon' => 'gavel', 'empty' => 'No hearings booked.',
                'rows' => $hearings->map(fn (Record $hearing) => ['label' => $hearing->title, 'sub' => (string) $hearing->value('court'), 'value' => $hearing->occurs_on->format('d M Y').(filled($hearing->value('time')) ? ' '.substr((string) $hearing->value('time'), 0, 5) : ''), 'href' => $hearing->url()])->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $matters = $this->records('matters')->get()->keyBy('id');

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Court dates in the next 14 days', 'icon' => 'gavel', 'empty' => 'No court dates in the next two weeks.',
                'rows' => $this->records('court_dates')->where('status', 'scheduled')->whereDate('occurs_on', '>=', today()->toDateString())->whereDate('occurs_on', '<=', today()->addDays(14)->toDateString())->orderBy('occurs_on')->get()
                    ->map(fn (Record $hearing) => ['label' => $hearing->title, 'sub' => $matters->get((int) $hearing->value('matter'))?->title ?? '', 'value' => $hearing->occurs_on->format('d M'), 'href' => $hearing->url(), 'tone' => $hearing->occurs_on->lte(today()->addDays(2)) ? 'warning' : null])->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Practice', 'icon' => 'scale', 'stats' => [
                ['label' => 'Open matters', 'value' => $matters->where('status', 'open')->count()],
                ['label' => 'Unbilled time', 'value' => $this->money($this->records('time_entries')->where('status', 'unbilled')->sum('amount'))],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $entries = $this->dated('time_entries', $from, $to)->get();
        $names = User::query()->whereIn('id', $entries->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');
        $row = fn ($group, string $label) => [$label, round($group->sum(fn (Record $entry) => $this->number($entry, 'hours')), 2), $this->money($group->where('status', 'billed')->sum('amount')), $this->money($group->where('status', 'unbilled')->sum('amount')), $this->money($group->where('status', 'written_off')->sum('amount'))];
        $matters = $this->records('matters')->get()->keyBy('id');

        return [
            ['title' => 'Time by matter', 'columns' => ['Matter', 'Hours', 'Billed', 'Unbilled', 'Written off'], 'rows' => $entries
                ->groupBy(fn (Record $entry) => $matters->get((int) $entry->value('matter'))?->title ?? 'No matter')->sortKeys()->map($row)->values()->all()],
            ['title' => 'Time by lawyer', 'columns' => ['Lawyer', 'Hours', 'Billed', 'Unbilled', 'Written off'], 'rows' => $entries
                ->groupBy(fn (Record $entry) => $names[$entry->assignee_id] ?? 'Unassigned')->sortKeys()->map($row)->values()->all()],
        ];
    }
}
