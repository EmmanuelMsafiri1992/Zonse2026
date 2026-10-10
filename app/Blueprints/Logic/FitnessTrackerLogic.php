<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Health & fitness tracker: a finished workout has a duration and can't be in the future; runs, walks,
 * cycles and swims with a distance get a pace. Blood pressure is written as top/bottom (120/80) and is
 * flagged high from 140/90; each check-in shows the weight change since the last one. The home page
 * counts this week's active minutes against the 150-minute goal and lists medicines due for a refill.
 */
class FitnessTrackerLogic extends AppLogic
{
    public const WEEKLY_MINUTES = 150;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'workouts' && $payload['status'] === 'done') {
            if ((float) ($data['minutes'] ?? 0) <= 0) {
                $errors['data.minutes'] = 'How long did the workout take?';
            }
            if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
                $errors['occurs_on'] = 'A workout in the future cannot be done yet.';
            }
        }
        if ($entity->key === 'measurements' && filled($data['blood_pressure'] ?? null) && ! $this->pressure((string) $data['blood_pressure'])) {
            $errors['data.blood_pressure'] = 'Write blood pressure as top/bottom, like 120/80.';
        }
        if ($entity->key === 'measurements' && array_key_exists('weight', $data) && $data['weight'] !== null && (float) $data['weight'] < 0) {
            $errors['data.weight'] = 'Weight cannot be negative.';
        }

        return $errors;
    }

    /**
     * Systolic and diastolic readings from "120/80".
     *
     * @return array{0: int, 1: int}|null
     */
    protected function pressure(string $reading): ?array
    {
        if (! preg_match('/^\s*(\d{2,3})\s*\/\s*(\d{2,3})\s*$/', $reading, $match) || (int) $match[1] <= (int) $match[2]) {
            return null;
        }

        return [(int) $match[1], (int) $match[2]];
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'workouts') {
            $distance = $this->number($record, 'distance');
            $this->put($record, ['_pace' => $distance > 0 && $this->number($record, 'minutes') > 0 ? round($this->number($record, 'minutes') / $distance, 2) : null]);
        }
        if ($record->entity === 'measurements') {
            [$top, $bottom] = $this->pressure((string) $record->value('blood_pressure')) ?? [0, 0];
            $previous = $this->records('measurements')->whereDate('occurs_on', '<=', $record->occurs_on->toDateString())->when($record->exists, fn ($query) => $query->whereKeyNot($record->id))
                ->latest('occurs_on')->latest('id')->get()->first(fn (Record $check) => filled($check->value('weight')));
            $this->put($record, [
                '_bp_high' => $top >= 140 || $bottom >= 90,
                '_weight_change' => filled($record->value('weight')) && $previous ? round($this->number($record, 'weight') - $this->number($previous, 'weight'), 1) : null,
            ]);
        }
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'workouts' && $record->status === 'planned' => ['done' => ['label' => 'Done', 'icon' => 'check', 'fields' => [['name' => 'minutes', 'label' => 'Minutes', 'type' => 'number', 'value' => $record->value('minutes')], ['name' => 'distance', 'label' => 'Distance (km)', 'type' => 'number', 'value' => $record->value('distance')]]], 'skip' => ['label' => 'Skipped', 'icon' => 'x']],
            $record->entity === 'medications' && $record->status === 'taking' => ['refilled' => ['label' => 'Refilled', 'icon' => 'pill', 'fields' => [['name' => 'refill_date', 'label' => 'Next refill', 'type' => 'date', 'value' => today()->addDays(30)->toDateString()]]], 'stop' => ['label' => 'Stopped', 'icon' => 'square']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'done':
                $done = $request->validate(['minutes' => ['nullable', 'numeric', 'gt:0', 'max:1440'], 'distance' => ['nullable', 'numeric', 'min:0']]);
                $minutes = $done['minutes'] ?? $record->value('minutes');
                if (! $minutes) {
                    throw ValidationException::withMessages(['minutes' => 'How long did the workout take?']);
                }
                $record->update(['status' => 'done', 'occurs_on' => $record->occurs_on && $record->occurs_on->lte(today()) ? $record->occurs_on : today(), 'data' => [...$record->data, 'minutes' => $minutes, 'distance' => $done['distance'] ?? $record->value('distance')]]);

                return $record->title.' done: '.$minutes.' minutes. '.$this->weekMinutes().' of '.self::WEEKLY_MINUTES.' this week.';
            case 'skip':
                $record->update(['status' => 'skipped']);

                return $record->title.' skipped.';
            case 'refilled':
                $date = $request->validate(['refill_date' => ['required', 'date', 'after:today']])['refill_date'];
                $record->update(['data' => [...$record->data, 'refill_date' => Carbon::parse($date)->toDateString()]]);

                return $record->title.' refilled; next refill '.Carbon::parse($date)->format('d M Y').'.';
            default:
                $record->update(['status' => 'stopped']);

                return 'Stopped '.$record->title.'.';
        }
    }

    protected function weekMinutes(): int
    {
        return (int) $this->dated('workouts', today()->startOfWeek(), today()->endOfWeek())->where('status', 'done')->get()->sum(fn (Record $workout) => $this->number($workout, 'minutes'));
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'measurements') {
            return [];
        }
        $change = $record->value('_weight_change');

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Check-in', 'icon' => 'heart-pulse', 'stats' => [
            ['label' => 'Weight change', 'value' => $change === null ? '—' : ($change > 0 ? '+' : '').$change.' kg'],
            ['label' => 'Blood pressure', 'value' => $record->value('blood_pressure') ?: '—', 'tone' => $record->value('_bp_high') ? 'danger' : null],
        ]]]];
    }

    public function homeCards(): array
    {
        $minutes = $this->weekMinutes();
        $latest = $this->records('measurements')->latest('occurs_on')->latest('id')->first();
        $refills = $this->records('medications')->where('status', 'taking')->get()
            ->filter(fn (Record $medicine) => filled($medicine->value('refill_date')) && Carbon::parse($medicine->value('refill_date'))->lte(today()->addDays(7)))
            ->sortBy(fn (Record $medicine) => $medicine->value('refill_date'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'This week', 'icon' => 'dumbbell', 'stats' => [
                ['label' => 'Active minutes', 'value' => $minutes.' / '.self::WEEKLY_MINUTES, 'tone' => $minutes >= self::WEEKLY_MINUTES ? 'success' : null],
                ['label' => 'Workouts', 'value' => $this->dated('workouts', today()->startOfWeek(), today()->endOfWeek())->where('status', 'done')->count()],
                ['label' => 'Latest weight', 'value' => $latest && filled($latest->value('weight')) ? $latest->value('weight').' kg' : '—'],
                ['label' => 'Latest blood pressure', 'value' => $latest?->value('blood_pressure') ?: '—', 'tone' => $latest?->value('_bp_high') ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Refills due', 'icon' => 'pill', 'empty' => 'No refills due this week.',
                'rows' => $refills->map(fn (Record $medicine) => ['label' => $medicine->title, 'sub' => trim($medicine->value('dose').' '.$medicine->value('times')), 'value' => Carbon::parse($medicine->value('refill_date'))->format('d M'), 'href' => $medicine->url(), 'tone' => Carbon::parse($medicine->value('refill_date'))->lt(today()) ? 'danger' : 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $workouts = $this->dated('workouts', $from, $to)->where('status', 'done')->get();

        return [['title' => 'Workouts by type', 'columns' => ['Type', 'Workouts', 'Minutes', 'Distance (km)', 'Average minutes'], 'rows' => $workouts
            ->groupBy(fn (Record $workout) => ucfirst(str_replace('_', ' ', (string) $workout->value('type'))))->sortKeys()
            ->map(fn ($group, string $type) => [$type, $group->count(), (int) $group->sum(fn (Record $workout) => $this->number($workout, 'minutes')), round($group->sum(fn (Record $workout) => $this->number($workout, 'distance')), 1), (int) round($group->avg(fn (Record $workout) => $this->number($workout, 'minutes')))])
            ->values()->all()]];
    }
}
