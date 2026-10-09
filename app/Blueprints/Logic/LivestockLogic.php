<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Livestock: tag numbers are unique and a mother must be a female already on the register;
 * treatments put an animal under a withdrawal period during which it cannot be sold or
 * slaughtered; weighings work out daily weight gain since the previous weighing.
 */
class LivestockLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];

        if ($entity->key === 'animals') {
            $errors = [];
            $duplicate = $this->records('animals')->where('title', $payload['title'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists();
            if ($duplicate) {
                $errors['title'] = 'Tag '.$payload['title'].' is already on the register.';
            }
            if (filled($data['dam_tag'] ?? null)) {
                $dam = $this->records('animals')->where('title', $data['dam_tag'])->first();
                if (! $dam) {
                    $errors['data.dam_tag'] = 'There is no animal tagged '.$data['dam_tag'].'.';
                } elseif ($dam->value('sex') === 'male') {
                    $errors['data.dam_tag'] = $dam->title.' is male.';
                }
            }
            if (filled($data['date_of_birth'] ?? null) && Carbon::parse($data['date_of_birth'])->isFuture()) {
                $errors['data.date_of_birth'] = 'The date of birth cannot be in the future.';
            }
            if ($existing && in_array($payload['status'], ['sold', 'slaughtered'], true) && $existing->status === 'alive' && ($until = $this->withdrawalUntil($existing))) {
                $errors['status'] = $existing->title.' is under a withdrawal period until '.$until->format('d M Y').'.';
            }

            return $errors;
        }

        if (in_array($entity->key, ['treatments', 'weighings'], true) && ! empty($data['animal']) && ! $existing) {
            $animal = $this->records('animals')->find($data['animal']);
            if ($animal && $animal->status !== 'alive') {
                return ['data.animal' => $animal->title.' is '.$animal->status.'.'];
            }
        }

        return [];
    }

    /** The last day of the longest withdrawal still running from given treatments. */
    public function withdrawalUntil(Record $animal): ?Carbon
    {
        $until = $this->linked('treatments', 'animal', $animal)->where('status', 'given')->get()
            ->filter(fn (Record $treatment) => $treatment->occurs_on && $this->number($treatment, 'withdrawal_days') > 0)
            ->map(fn (Record $treatment) => $treatment->occurs_on->copy()->addDays((int) $this->number($treatment, 'withdrawal_days')))
            ->max();

        return $until && $until->gte(today()) ? $until : null;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'weighings' && ($animal = $this->parent($record, 'animal'))) {
            $previous = $this->linked('weighings', 'animal', $animal)->when($record->exists, fn ($query) => $query->whereKeyNot($record->id))
                ->whereNotNull('occurs_on')->whereDate('occurs_on', '<', $record->occurs_on ?? today())->orderByDesc('occurs_on')->first();
            $gain = null;
            if ($previous && $record->occurs_on) {
                $days = (int) $previous->occurs_on->diffInDays($record->occurs_on);
                $gain = $days > 0 ? round(($this->number($record, 'weight_kg') - $this->number($previous, 'weight_kg')) / $days, 3) : null;
            }
            $this->put($record, ['_daily_gain' => $gain]);
        }

        if ($record->entity === 'animals' && $record->exists) {
            $latest = $this->linked('weighings', 'animal', $record)->orderByDesc('occurs_on')->orderByDesc('id')->first();
            $this->put($record, ['_weight' => $latest ? $this->number($latest, 'weight_kg') : null, '_daily_gain' => $latest?->value('_daily_gain'), '_withdrawal_until' => $this->withdrawalUntil($record)?->toDateString()]);
        }
    }

    public function saved(Record $record): void
    {
        if (in_array($record->entity, ['treatments', 'weighings'], true)) {
            $this->recalculate($this->parent($record, 'animal'));
        }
    }

    public function deleted(Record $record): void
    {
        $this->saved($record);
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'animals') {
            return [];
        }

        $until = $this->withdrawalUntil($record);
        $age = $record->value('date_of_birth') ? Carbon::parse($record->value('date_of_birth')) : null;

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Animal', 'icon' => 'beef', 'stats' => [
            ['label' => 'Age', 'value' => $age ? (int) $age->diffInMonths(today()).' months' : '—'],
            ['label' => 'Last weight', 'value' => $record->value('_weight') !== null ? $record->value('_weight').' kg' : '—'],
            ['label' => 'Daily gain', 'value' => $record->value('_daily_gain') !== null ? round((float) $record->value('_daily_gain') * 1000).' g/day' : '—'],
            ['label' => 'Withdrawal', 'value' => $until ? 'Until '.$until->format('d M Y') : 'Clear', 'tone' => $until ? 'danger' : 'success'],
        ]]]];
    }

    public function homeCards(): array
    {
        $due = $this->records('treatments')->where('status', 'scheduled')->whereDate('due_on', '<=', today()->addDays(7))->orderBy('due_on')->get();
        $tags = $this->records('animals')->pluck('title', 'id');

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Treatments due', 'icon' => 'syringe', 'empty' => 'No treatments due this week.',
            'rows' => $due->take(15)->map(fn (Record $treatment) => [
                'label' => $treatment->title, 'sub' => $tags[$treatment->value('animal')] ?? '—',
                'value' => $treatment->due_on->format('d M'), 'href' => $treatment->url(), 'tone' => $treatment->due_on->lt(today()) ? 'danger' : null,
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $herd = $this->records('animals')->where('status', 'alive')->get()->groupBy(fn (Record $animal) => (string) $animal->value('species'))->sortKeys()
            ->map(fn ($group, $species) => [ucfirst($species), $group->where('data.sex', 'female')->count(), $group->where('data.sex', 'male')->count(), $group->count()])->values()->all();

        $tags = $this->records('animals')->pluck('title', 'id');
        $growth = $this->dated('weighings', $from, $to)->get()->groupBy(fn (Record $weighing) => (int) $weighing->value('animal'))->map(function ($group, $animal) use ($tags) {
            $sorted = $group->sortBy(fn (Record $weighing) => $weighing->occurs_on?->timestamp);
            $first = $sorted->first();
            $last = $sorted->last();
            $days = $first->occurs_on && $last->occurs_on ? (int) $first->occurs_on->diffInDays($last->occurs_on) : 0;
            $gained = $this->number($last, 'weight_kg') - $this->number($first, 'weight_kg');

            return [$tags[$animal] ?? '—', $this->number($first, 'weight_kg'), $this->number($last, 'weight_kg'), round($gained, 1), $days > 0 ? round($gained / $days * 1000).' g' : '—'];
        })->sortBy(0)->values()->all();

        $treatments = $this->dated('treatments', $from, $to)->where('status', 'given')->get();

        return [
            ['title' => 'Herd on hand', 'columns' => ['Species', 'Female', 'Male', 'Total'], 'rows' => $herd],
            ['title' => 'Weight gain', 'columns' => ['Tag', 'First (kg)', 'Last (kg)', 'Gained (kg)', 'Daily gain'], 'rows' => $growth],
            ['title' => 'Treatment costs', 'columns' => ['Treatments', 'Cost'], 'rows' => [[$treatments->count(), $this->money($treatments->sum('amount'))]]],
        ];
    }
}
