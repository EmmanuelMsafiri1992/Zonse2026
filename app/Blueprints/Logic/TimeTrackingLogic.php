<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Time tracking: hours come from the start and end times when both are given, nobody logs more
 * than 24 hours in a day, and each entry is valued at hours × rate (non-billable work is worth
 * nothing to the client). Billed entries are locked, and a client's unbilled work can be marked
 * billed in one go.
 */
class TimeTrackingLogic extends AppLogic
{
    public const MAX_HOURS_PER_DAY = 24;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        $hours = $this->hoursFrom($data);

        if ($hours === false) {
            return ['data.end_time' => 'The end time must be after the start time.'];
        }
        $hours ??= (float) ($data['hours'] ?? 0);
        if ($hours <= 0) {
            $errors['data.hours'] = 'Enter the hours worked.';
        }

        if ($existing?->status === 'billed' && ((float) $existing->value('hours') !== $hours || (float) $existing->value('rate') !== (float) ($data['rate'] ?? 0))) {
            $errors['data.hours'] = 'This time is already billed, so its hours and rate are locked.';
        }

        if ($hours > 0 && filled($payload['occurs_on'] ?? null)) {
            $logged = (float) $this->records('entries')->whereDate('occurs_on', $payload['occurs_on'])
                ->when(filled($payload['assignee_id'] ?? null), fn ($query) => $query->where('assignee_id', $payload['assignee_id']), fn ($query) => $query->whereNull('assignee_id'))
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->sum(fn (Record $entry) => $this->number($entry, 'hours'));
            if ($logged + $hours > self::MAX_HOURS_PER_DAY) {
                $errors['data.hours'] = 'Only '.max(0, self::MAX_HOURS_PER_DAY - $logged).' hours are left to log on that day.';
            }
        }

        return $errors;
    }

    /**
     * Hours between the start and end times: null when either is missing, false when the end is not after the start.
     *
     * @param  array<string, mixed>  $data
     */
    protected function hoursFrom(array $data): float|false|null
    {
        if (blank($data['start_time'] ?? null) || blank($data['end_time'] ?? null)) {
            return null;
        }

        $start = Carbon::parse($data['start_time']);
        $end = Carbon::parse($data['end_time']);

        return $end->gt($start) ? round($start->diffInMinutes($end) / 60, 2) : false;
    }

    public function saving(Record $record): void
    {
        $hours = $this->hoursFrom((array) $record->data);
        if (is_float($hours)) {
            $this->put($record, ['hours' => $hours]);
        }

        $record->amount = $record->status === 'non_billable' ? 0 : round($this->number($record, 'hours') * $this->number($record, 'rate'), 2);
    }

    public function actions(Record $record): array
    {
        if ($record->status !== 'unbilled' || ! $record->contact_id) {
            return [];
        }

        return ['bill_client' => ['label' => 'Mark client\'s time billed', 'icon' => 'receipt',
            'confirm' => 'Mark all unbilled time for '.$record->contact?->name.' as billed?']];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $entries = $this->records('entries')->where('status', 'unbilled')->where('contact_id', $record->contact_id)->get();
        foreach ($entries as $entry) {
            $entry->update(['status' => 'billed']);
        }

        return $entries->count().' entries ('.$entries->sum(fn (Record $entry) => $this->number($entry, 'hours')).' hours, '.$this->money($entries->sum('amount')).') are marked billed.';
    }

    public function homeCards(): array
    {
        $week = $this->records('entries')->whereBetween('occurs_on', [today()->startOfWeek(), today()->endOfWeek()])->get();
        $hours = $week->sum(fn (Record $entry) => $this->number($entry, 'hours'));
        $billable = $week->where('status', '!=', 'non_billable')->sum(fn (Record $entry) => $this->number($entry, 'hours'));
        $unbilled = $this->records('entries')->where('status', 'unbilled')->with('contact')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'This week', 'icon' => 'timer', 'stats' => [
                ['label' => 'Hours logged', 'value' => (string) round($hours, 2)],
                ['label' => 'Billable', 'value' => $hours > 0 ? round($billable / $hours * 100).'%' : '—'],
                ['label' => 'Unbilled value', 'value' => $this->money($unbilled->sum('amount')), 'tone' => $unbilled->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Unbilled by client', 'icon' => 'receipt', 'empty' => 'Everything is billed.',
                'rows' => $unbilled->groupBy(fn (Record $entry) => $entry->contact?->name ?? 'No client')->sortKeys()->map(fn ($group, $client) => [
                    'label' => $client, 'sub' => $group->sum(fn (Record $entry) => $this->number($entry, 'hours')).' hours', 'value' => $this->money($group->sum('amount')),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $entries = $this->dated('entries', $from, $to)->with('contact')->get();
        $hours = fn ($group) => round($group->sum(fn (Record $entry) => $this->number($entry, 'hours')), 2);

        $projects = $entries->groupBy(fn (Record $entry) => (string) ($entry->value('project') ?: 'No project'))->sortKeys()->map(fn ($group, $project) => [
            $project, $hours($group), $hours($group->where('status', '!=', 'non_billable')), $this->money($group->sum('amount')),
        ])->values()->all();

        $names = User::query()->whereIn('id', $entries->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');
        $people = $entries->groupBy(fn (Record $entry) => $names[$entry->assignee_id] ?? 'Unassigned')->sortKeys()->map(fn ($group, $person) => [
            $person, $hours($group), $hours($group->where('status', '!=', 'non_billable')), $this->money($group->sum('amount')),
        ])->values()->all();

        $unbilled = $entries->where('status', 'unbilled')->sortBy('occurs_on')->map(fn (Record $entry) => [
            $entry->occurs_on?->format('d M Y') ?? '—', $entry->contact?->name ?? '—', $entry->title, $this->number($entry, 'hours'), $this->money($entry->amount),
        ])->values()->all();

        return [
            ['title' => 'Hours by project', 'columns' => ['Project', 'Hours', 'Billable hours', 'Value'], 'rows' => $projects],
            ['title' => 'Hours by person', 'columns' => ['Person', 'Hours', 'Billable hours', 'Value'], 'rows' => $people],
            ['title' => 'Unbilled work', 'columns' => ['Date', 'Client', 'Work', 'Hours', 'Value'], 'rows' => $unbilled],
        ];
    }
}
