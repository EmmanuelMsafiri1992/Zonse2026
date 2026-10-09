<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Dietitian practice: each client's BMI is worked out from height and weight, and every check-in
 * updates the current weight, the change since the start and the BMI now. A client has one active
 * meal plan at a time (activating a new plan ends the old one), plans carry a review date, and the
 * home page lists the reviews that are due so nobody drifts without a follow-up.
 */
class NutritionLogic extends AppLogic
{
    /**
     * A sensible range for a daily calorie target.
     */
    protected const CALORIES = [800, 6000];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'clients') {
            if (filled($data['height'] ?? null) && ($data['height'] < 50 || $data['height'] > 250)) {
                $errors['data.height'] = 'Enter the height in centimetres, between 50 and 250.';
            }
            if (filled($data['start_weight'] ?? null) && ($data['start_weight'] < 2 || $data['start_weight'] > 400)) {
                $errors['data.start_weight'] = 'Enter the weight in kilograms, between 2 and 400.';
            }

            return $errors;
        }

        $client = filled($data['client'] ?? null) ? $this->records('clients')->find($data['client']) : null;
        if ($client?->status === 'completed' && ! $existing) {
            $errors['data.client'] = $client->title.' has completed the programme.';
        }

        if ($entity->key === 'plans') {
            if (filled($data['calories'] ?? null) && ($data['calories'] < self::CALORIES[0] || $data['calories'] > self::CALORIES[1])) {
                $errors['data.calories'] = 'Set a daily target between '.number_format(self::CALORIES[0]).' and '.number_format(self::CALORIES[1]).' calories.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lte(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The review must be after the plan starts.';
            }
            if ($payload['status'] === 'active' && blank($payload['due_on'] ?? null)) {
                $errors['due_on'] = 'Set a review date for the active plan.';
            }

            return $errors;
        }

        if (blank($data['weight'] ?? null) || $data['weight'] <= 0) {
            $errors['data.weight'] = 'Enter the weight.';
        }
        if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->isFuture()) {
            $errors['occurs_on'] = 'A check-in cannot be in the future.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'clients') {
            return;
        }
        $last = $record->exists ? $this->linked('checkins', 'client', $record)->orderByDesc('occurs_on')->orderByDesc('id')->first() : null;
        $start = $this->number($record, 'start_weight');
        $current = $last ? $this->number($last, 'weight') : $start;
        $this->put($record, [
            '_bmi_start' => $this->bmi($record, $start),
            '_current_weight' => $current ?: null,
            '_change' => $start && $current ? round($current - $start, 1) : null,
            '_bmi_now' => $this->bmi($record, $current),
            '_last_checkin' => $last?->occurs_on?->toDateString(),
        ]);
    }

    /**
     * Body-mass index for a weight at the client's height, to one decimal.
     */
    protected function bmi(Record $client, float $weight): ?float
    {
        $metres = $this->number($client, 'height') / 100;

        return $metres > 0 && $weight > 0 ? round($weight / ($metres * $metres), 1) : null;
    }

    /**
     * The WHO band a BMI falls in.
     */
    protected function band(?float $bmi): string
    {
        return match (true) {
            $bmi === null => '—',
            $bmi < 18.5 => 'Underweight',
            $bmi < 25 => 'Healthy',
            $bmi < 30 => 'Overweight',
            default => 'Obese',
        };
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'checkins') {
            $this->recalculate($this->parent($record, 'client'));
            $this->recalculate($this->previousParent($record, 'client'));
        }
        if ($record->entity === 'plans' && $record->status === 'active' && ($record->wasRecentlyCreated || $record->wasChanged('status'))) {
            $this->linked('plans', 'client', (int) $record->value('client'))->where('status', 'active')->whereKeyNot($record->id)->get()
                ->each(fn (Record $plan) => $plan->update(['status' => 'ended']));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'checkins') {
            $this->recalculate($this->parent($record, 'client'));
        }
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'plans' && $record->status === 'draft' => ['activate' => ['label' => 'Start plan', 'icon' => 'play', 'fields' => [['name' => 'review', 'label' => 'Review on', 'type' => 'date', 'value' => $record->due_on?->toDateString() ?? today()->addWeeks(4)->toDateString()]]]],
            $record->entity === 'plans' && $record->status === 'active' => ['review' => ['label' => 'Reviewed', 'icon' => 'calendar-check', 'fields' => [['name' => 'review', 'label' => 'Next review', 'type' => 'date', 'value' => today()->addWeeks(4)->toDateString()]]], 'end' => ['label' => 'End plan', 'icon' => 'square']],
            $record->entity === 'clients' && $record->status === 'active' => ['complete' => ['label' => 'Programme completed', 'icon' => 'flag']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'complete') {
            $record->update(['status' => 'completed']);
            $this->linked('plans', 'client', $record)->where('status', 'active')->get()->each(fn (Record $plan) => $plan->update(['status' => 'ended']));
            $change = $record->fresh()->value('_change');

            return $record->title.' completed the programme'.($change !== null ? ' ('.($change > 0 ? '+' : '').$change.' kg).' : '.');
        }
        if ($action === 'end') {
            $record->update(['status' => 'ended']);

            return 'Meal plan ended.';
        }
        $review = $request->validate(['review' => ['required', 'date', 'after:today']])['review'];
        $record->update(['status' => 'active', 'occurs_on' => $record->occurs_on ?? today(), 'due_on' => $review]);
        $client = $this->parent($record, 'client');

        return ($action === 'activate' ? 'Meal plan started for ' : 'Plan reviewed for ').($client?->title ?? $record->title).'; next review '.Carbon::parse($review)->format('d M Y').'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'clients') {
            return [];
        }
        $checkins = $this->linked('checkins', 'client', $record)->orderByDesc('occurs_on')->limit(8)->get();
        $change = $record->value('_change');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Progress', 'icon' => 'scale', 'stats' => [
                ['label' => 'Start weight', 'value' => $record->value('start_weight') ? $record->value('start_weight').' kg' : '—'],
                ['label' => 'Current weight', 'value' => $record->value('_current_weight') ? $record->value('_current_weight').' kg' : '—'],
                ['label' => 'Change', 'value' => $change === null ? '—' : ($change > 0 ? '+' : '').$change.' kg'],
                ['label' => 'BMI now', 'value' => $record->value('_bmi_now') === null ? '—' : $record->value('_bmi_now').' · '.$this->band($record->value('_bmi_now'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Check-ins', 'icon' => 'scale', 'empty' => 'No check-ins yet.',
                'rows' => $checkins->map(fn (Record $checkin) => ['label' => $checkin->occurs_on?->format('d M Y') ?? $checkin->title, 'value' => $checkin->value('weight').' kg', 'href' => $checkin->url()])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $plans = $this->records('plans')->where('status', 'active')->get();
        $due = $plans->filter(fn (Record $plan) => $plan->due_on && $plan->due_on->lte(today()->addWeek()))->sortBy('due_on');
        $clients = $this->records('clients')->where('status', 'active')->pluck('title', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Clients', 'icon' => 'salad', 'stats' => [
                ['label' => 'Active clients', 'value' => (string) $clients->count()],
                ['label' => 'Active plans', 'value' => (string) $plans->count()],
                ['label' => 'Reviews overdue', 'value' => (string) $due->filter(fn (Record $plan) => $plan->due_on->lt(today()))->count(), 'tone' => $due->contains(fn (Record $plan) => $plan->due_on->lt(today())) ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Reviews due', 'icon' => 'calendar-check', 'empty' => 'No reviews due this week.',
                'rows' => $due->map(fn (Record $plan) => [
                    'label' => $clients[$plan->value('client')] ?? $plan->title, 'sub' => $plan->title, 'value' => $plan->due_on->format('d M'), 'href' => $plan->url(), 'tone' => $plan->due_on->lt(today()) ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $clients = $this->records('clients')->get();
        $progress = $clients->sortBy('title')->map(fn (Record $client) => [
            $client->title, str_replace('_', ' ', ucfirst((string) $client->value('goal'))), $client->value('start_weight') ? $client->value('start_weight').' kg' : '—',
            $client->value('_current_weight') ? $client->value('_current_weight').' kg' : '—',
            $client->value('_change') === null ? '—' : ($client->value('_change') > 0 ? '+' : '').$client->value('_change').' kg',
            $this->band($client->value('_bmi_now')),
        ])->values()->all();

        $byGoal = $clients->groupBy(fn (Record $client) => str_replace('_', ' ', ucfirst((string) ($client->value('goal') ?: 'other'))))->sortKeys()->map(function (Collection $group, string $goal) {
            $changes = $group->filter(fn (Record $client) => $client->value('_change') !== null);

            return [$goal, $group->count(), $changes->isEmpty() ? '—' : round($changes->avg(fn (Record $client) => (float) $client->value('_change')), 1).' kg'];
        })->values()->all();

        $checkins = $this->dated('checkins', $from, $to)->get();
        $byMonth = collect($this->months($from, $to))->map(fn (string $label, string $month) => [$label, $checkins->filter(fn (Record $checkin) => $checkin->occurs_on?->format('Y-m') === $month)->count()])->values()->all();

        return [
            ['title' => 'Client progress', 'columns' => ['Client', 'Goal', 'Start', 'Now', 'Change', 'BMI band'], 'rows' => $progress],
            ['title' => 'Average change by goal', 'columns' => ['Goal', 'Clients', 'Average change'], 'rows' => $byGoal],
            ['title' => 'Check-ins by month', 'columns' => ['Month', 'Check-ins'], 'rows' => $byMonth],
        ];
    }
}
