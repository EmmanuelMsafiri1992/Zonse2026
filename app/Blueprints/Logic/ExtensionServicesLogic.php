<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Extension services: a visit is only done once the advice is written down and its follow-up
 * comes after it; training days cannot count more women than attendees and are only held with
 * people there; each farmer shows when they were last visited, and the home screen lists due
 * follow-ups and farmers nobody has seen for three months.
 */
class ExtensionServicesLogic extends AppLogic
{
    public const UNVISITED_DAYS = 90;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'visits') {
            if ($payload['status'] === 'done' && blank($data['advice'] ?? null)) {
                $errors['data.advice'] = 'Write down the advice given.';
            }
            if (filled($data['follow_up'] ?? null) && filled($payload['occurs_on'] ?? null) && Carbon::parse($data['follow_up'])->lte(Carbon::parse($payload['occurs_on']))) {
                $errors['data.follow_up'] = 'The follow-up must come after the visit.';
            }
        }

        if ($entity->key === 'trainings') {
            if ((float) ($data['women_attendees'] ?? 0) > (float) ($data['attendees'] ?? 0)) {
                $errors['data.women_attendees'] = 'More women than attendees.';
            }
            if ($payload['status'] === 'held' && (float) ($data['attendees'] ?? 0) <= 0) {
                $errors['data.attendees'] = 'Record how many attended.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'farmers' && $record->exists) {
            $done = $this->linked('visits', 'farmer', $record)->where('status', 'done')->orderByDesc('occurs_on')->get();
            $this->put($record, ['_visits' => $done->count(), '_last_visit' => $done->first()?->occurs_on?->toDateString()]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'visits') {
            $this->recalculate($this->parent($record, 'farmer'));
            $this->recalculate($this->previousParent($record, 'farmer'));
        }
    }

    public function deleted(Record $record): void
    {
        $this->saved($record);
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'farmers') {
            return [];
        }

        $last = $record->value('_last_visit') ? Carbon::parse($record->value('_last_visit')) : null;

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Extension contact', 'icon' => 'map-pin', 'stats' => [
            ['label' => 'Visits', 'value' => (string) (int) $record->value('_visits')],
            ['label' => 'Last visit', 'value' => $last ? $last->format('d M Y') : 'Never', 'tone' => ! $last || $last->lt(today()->subDays(self::UNVISITED_DAYS)) ? 'warning' : null],
        ]]]];
    }

    public function homeCards(): array
    {
        $farmers = $this->records('farmers')->where('status', 'active')->get();
        $names = $farmers->pluck('title', 'id');
        $followUps = $this->records('visits')->where('status', 'done')->get()
            ->filter(fn (Record $visit) => filled($visit->value('follow_up')) && Carbon::parse($visit->value('follow_up'))->lte(today()->addDays(7)))
            ->reject(fn (Record $visit) => $this->linked('visits', 'farmer', (int) $visit->value('farmer'))->whereKeyNot($visit->id)->whereDate('occurs_on', '>', $visit->occurs_on ?? today())->exists())
            ->sortBy(fn (Record $visit) => $visit->value('follow_up'));
        $unvisited = $farmers->filter(fn (Record $farmer) => ! $farmer->value('_last_visit') || Carbon::parse($farmer->value('_last_visit'))->lt(today()->subDays(self::UNVISITED_DAYS)));

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Follow-ups due', 'icon' => 'calendar-check', 'empty' => 'No follow-ups due this week.',
                'rows' => $followUps->take(15)->map(fn (Record $visit) => [
                    'label' => $names[$visit->value('farmer')] ?? $visit->title, 'sub' => $visit->value('topic'), 'value' => Carbon::parse($visit->value('follow_up'))->format('d M'),
                    'href' => $visit->url(), 'tone' => Carbon::parse($visit->value('follow_up'))->lt(today()) ? 'danger' : null,
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Reach', 'icon' => 'users', 'stats' => [
                ['label' => 'Active farmers', 'value' => (string) $farmers->count()],
                ['label' => 'Not seen in 90 days', 'value' => (string) $unvisited->count(), 'tone' => $unvisited->count() ? 'warning' : null],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $visits = $this->dated('visits', $from, $to)->where('status', 'done')->get();
        $trainings = $this->dated('trainings', $from, $to)->where('status', 'held')->get();

        $monthly = collect($this->months($from, $to))->map(function ($label, $month) use ($visits, $trainings) {
            $inMonth = fn ($records) => $records->filter(fn (Record $record) => ($record->occurs_on ?? $record->created_at)->format('Y-m') === $month);
            $held = $inMonth($trainings);
            $people = $held->sum(fn (Record $training) => $this->number($training, 'attendees'));
            $women = $held->sum(fn (Record $training) => $this->number($training, 'women_attendees'));

            return [$label, $inMonth($visits)->count(), $held->count(), (int) $people, $people > 0 ? round($women / $people * 100).'%' : '—'];
        })->values()->all();

        $topics = $visits->groupBy(fn (Record $visit) => (string) ($visit->value('topic') ?: 'Not given'))->sortKeys()->map(fn ($group, $topic) => [$topic, $group->count()])->values()->all();

        return [
            ['title' => 'Outreach by month', 'columns' => ['Month', 'Field visits', 'Trainings held', 'People trained', 'Women'], 'rows' => $monthly],
            ['title' => 'Visit topics', 'columns' => ['Topic', 'Visits'], 'rows' => $topics],
        ];
    }
}
