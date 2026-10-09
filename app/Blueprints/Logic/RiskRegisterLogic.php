<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Risk register: every risk is scored as likelihood times impact on a five-by-five grid and rated
 * low, medium, high or critical. A risk is mitigated only with a mitigation plan, a critical risk
 * cannot simply be accepted, and each review re-scores the risk and sets the next review date.
 * The register shows the top risks, the heat map and reviews that are overdue.
 */
class RiskRegisterLogic extends AppLogic
{
    public const OPEN = ['identified', 'assessed', 'mitigating', 'accepted'];

    public static function score(?string $likelihood, ?string $impact): int
    {
        return (int) substr((string) $likelihood, 0, 1) * (int) substr((string) $impact, 0, 1);
    }

    public static function rating(int $score): string
    {
        return match (true) {
            $score >= 15 => 'critical',
            $score >= 10 => 'high',
            $score >= 5 => 'medium',
            default => 'low',
        };
    }

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        $score = self::score($data['likelihood'] ?? null, $data['impact'] ?? null);

        if ($payload['status'] === 'mitigating' && blank($data['mitigation'] ?? null)) {
            $errors['data.mitigation'] = 'Write the mitigation plan first.';
        }
        if ($payload['status'] === 'accepted' && self::rating($score) === 'critical') {
            $errors['status'] = 'A critical risk cannot simply be accepted; mitigate it.';
        }
        if (in_array($payload['status'], ['assessed', 'mitigating', 'accepted'], true) && blank($payload['due_on'] ?? null)) {
            $errors['due_on'] = 'Set the review date.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        $score = self::score($record->value('likelihood'), $record->value('impact'));
        $open = in_array($record->status, self::OPEN, true);
        $this->put($record, [
            '_likelihood' => (int) substr((string) $record->value('likelihood'), 0, 1),
            '_impact' => (int) substr((string) $record->value('impact'), 0, 1),
            '_score' => $score,
            '_rating' => self::rating($score),
            '_review_overdue' => $open && $record->due_on?->lt(today()),
            '_reviews' => (int) $record->value('_reviews'),
            '_closed_on' => $record->status === 'closed' ? ($record->value('_closed_on') ?? today()->toDateString()) : null,
        ]);
    }

    public function actions(Record $record): array
    {
        $likelihoods = $this->app->entities['risks']->field('likelihood')?->options ?? [];
        $impacts = $this->app->entities['risks']->field('impact')?->options ?? [];
        $scoreFields = [
            ['name' => 'likelihood', 'label' => 'Likelihood', 'type' => 'select', 'options' => $likelihoods, 'value' => $record->value('likelihood')],
            ['name' => 'impact', 'label' => 'Impact', 'type' => 'select', 'options' => $impacts, 'value' => $record->value('impact')],
            ['name' => 'due_on', 'label' => 'Next review', 'type' => 'date', 'value' => today()->addMonths(3)->toDateString()],
        ];
        $review = ['label' => 'Review', 'icon' => 'calendar-check', 'fields' => $scoreFields];
        $close = ['label' => 'Close', 'icon' => 'check', 'confirm' => 'Close '.$record->title.'?'];

        return match ($record->status) {
            'identified' => ['assess' => ['label' => 'Assess', 'icon' => 'gauge', 'fields' => $scoreFields]],
            'assessed' => ['mitigate' => ['label' => 'Mitigate', 'icon' => 'shield', 'fields' => [['name' => 'mitigation', 'label' => 'Mitigation plan', 'type' => 'textarea', 'value' => $record->value('mitigation')]]], 'accept' => ['label' => 'Accept', 'icon' => 'hand'], 'close' => $close],
            'mitigating', 'accepted' => ['review' => $review, 'close' => $close],
            default => ['reopen' => ['label' => 'Reopen', 'icon' => 'undo']],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'assess':
            case 'review':
                $likelihoods = $this->app->entities['risks']->field('likelihood')?->options ?? [];
                $impacts = $this->app->entities['risks']->field('impact')?->options ?? [];
                $input = $request->validate(['likelihood' => ['required', 'string'], 'impact' => ['required', 'string'], 'due_on' => ['required', 'date', 'after:today']]);
                if (! array_key_exists($input['likelihood'], $likelihoods) || ! array_key_exists($input['impact'], $impacts)) {
                    throw ValidationException::withMessages(['likelihood' => 'Choose a likelihood and an impact from the scale.']);
                }
                $score = self::score($input['likelihood'], $input['impact']);
                $record->update([
                    'status' => $action === 'assess' ? 'assessed' : $record->status,
                    'due_on' => Carbon::parse($input['due_on']),
                    'data' => [...$record->data, 'likelihood' => $input['likelihood'], 'impact' => $input['impact'], '_reviews' => (int) $record->value('_reviews') + ($action === 'review' ? 1 : 0)],
                ]);

                return $record->title.($action === 'assess' ? ' assessed as ' : ' reviewed: now ').self::rating($score).' ('.$score.').';
            case 'mitigate':
                $plan = $request->validate(['mitigation' => ['required', 'string']])['mitigation'];
                $record->update(['status' => 'mitigating', 'data' => [...$record->data, 'mitigation' => $plan]]);

                return 'Mitigating '.$record->title.'.';
            case 'accept':
                if ($record->value('_rating') === 'critical') {
                    throw ValidationException::withMessages(['status' => 'A critical risk cannot simply be accepted; mitigate it.']);
                }
                $record->update(['status' => 'accepted']);

                return $record->title.' accepted.';
            case 'close':
                $record->update(['status' => 'closed']);

                return $record->title.' closed.';
        }

        $record->update(['status' => 'assessed', 'due_on' => today()->addMonths(3)]);

        return $record->title.' reopened.';
    }

    public function recordCards(Record $record): array
    {
        $categories = $this->app->entities['risks']->field('category')?->options ?? [];
        $likelihoods = $this->app->entities['risks']->field('likelihood')?->options ?? [];
        $impacts = $this->app->entities['risks']->field('impact')?->options ?? [];
        $tone = match ($record->value('_rating')) {
            'critical', 'high' => 'danger',
            'medium' => 'warning',
            default => 'success',
        };

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Risk', 'icon' => 'shield-alert', 'stats' => [
                ['label' => 'Category', 'value' => $categories[$record->value('category')] ?? ucfirst(str_replace('_', ' ', (string) $record->value('category')))],
                ['label' => 'Likelihood', 'value' => $likelihoods[$record->value('likelihood')] ?? '—'],
                ['label' => 'Impact', 'value' => $impacts[$record->value('impact')] ?? '—'],
                ['label' => 'Score', 'value' => (string) (int) $record->value('_score'), 'tone' => $tone],
                ['label' => 'Rating', 'value' => ucfirst((string) $record->value('_rating')), 'tone' => $tone],
                ['label' => 'Review', 'value' => $record->due_on?->format('d M Y') ?? 'Not set', 'tone' => $record->value('_review_overdue') ? 'danger' : null],
                ['label' => 'Reviews', 'value' => (string) (int) $record->value('_reviews')],
            ]]],
        ];
    }

    public function homeCards(): array
    {
        $risks = $this->records('risks')->get();
        $open = $risks->whereIn('status', self::OPEN);
        $overdue = $open->filter(fn (Record $risk) => $risk->value('_review_overdue'))->sortBy('due_on');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Risk register', 'icon' => 'shield-alert', 'stats' => [
                ['label' => 'Open risks', 'value' => (string) $open->count()],
                ['label' => 'Critical', 'value' => (string) $open->where('data._rating', 'critical')->count(), 'tone' => $open->where('data._rating', 'critical')->isNotEmpty() ? 'danger' : null],
                ['label' => 'High', 'value' => (string) $open->where('data._rating', 'high')->count(), 'tone' => $open->where('data._rating', 'high')->isNotEmpty() ? 'warning' : null],
                ['label' => 'Mitigating', 'value' => (string) $open->where('status', 'mitigating')->count()],
                ['label' => 'Reviews overdue', 'value' => (string) $overdue->count(), 'tone' => $overdue->isNotEmpty() ? 'danger' : null],
                ['label' => 'Closed this year', 'value' => (string) $risks->filter(fn (Record $risk) => $risk->status === 'closed' && filled($risk->value('_closed_on')) && Carbon::parse($risk->value('_closed_on'))->isCurrentYear())->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Top risks', 'icon' => 'flame', 'empty' => 'No open risks.',
                'rows' => $open->sortByDesc(fn (Record $risk) => (int) $risk->value('_score'))->take(10)->map(fn (Record $risk) => [
                    'label' => $risk->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $risk->value('category'))).' · '.ucfirst($risk->status), 'value' => ucfirst((string) $risk->value('_rating')).' ('.(int) $risk->value('_score').')', 'href' => $risk->url(), 'tone' => in_array($risk->value('_rating'), ['critical', 'high'], true) ? 'danger' : ($risk->value('_rating') === 'medium' ? 'warning' : null),
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Reviews overdue', 'icon' => 'calendar-clock', 'empty' => 'Every open risk has been reviewed on time.',
                'rows' => $overdue->take(10)->map(fn (Record $risk) => [
                    'label' => $risk->title, 'sub' => ucfirst((string) $risk->value('_rating')).' · '.ucfirst($risk->status), 'value' => 'Due '.$risk->due_on->format('d M Y'), 'href' => $risk->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $risks = $this->records('risks')->get();
        $open = $risks->whereIn('status', self::OPEN);
        $categories = $this->app->entities['risks']->field('category')?->options ?? [];
        $byCategory = collect($categories)->map(function (string $label, string $category) use ($open, $risks) {
            $group = $open->where('data.category', $category);

            return [$label, $group->count(), $group->whereIn('data._rating', ['critical', 'high'])->count(), $group->isEmpty() ? 0 : round($group->avg(fn (Record $risk) => (int) $risk->value('_score')), 1), $risks->where('data.category', $category)->where('status', 'closed')->count()];
        })->values()->all();

        $impacts = $this->app->entities['risks']->field('impact')?->options ?? [];
        $likelihoods = $this->app->entities['risks']->field('likelihood')?->options ?? [];
        $heatMap = collect($likelihoods)->reverse()->map(function (string $label, string $likelihood) use ($open, $impacts) {
            $row = [$label];
            foreach (array_keys($impacts) as $impact) {
                $row[] = $open->where('data.likelihood', $likelihood)->where('data.impact', $impact)->count();
            }

            return $row;
        })->values()->all();

        $byRating = collect(['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'])->map(fn (string $label, string $rating) => [
            $label, $open->where('data._rating', $rating)->count(), $open->where('data._rating', $rating)->where('status', 'mitigating')->count(), $open->where('data._rating', $rating)->filter(fn (Record $risk) => $risk->value('_review_overdue'))->count(),
        ])->values()->all();

        return [
            ['title' => 'Risks by category', 'columns' => ['Category', 'Open', 'Critical or high', 'Average score', 'Closed'], 'rows' => $byCategory],
            ['title' => 'Heat map', 'columns' => ['Likelihood \\ impact', ...array_values($impacts)], 'rows' => $heatMap],
            ['title' => 'Risks by rating', 'columns' => ['Rating', 'Open', 'Mitigating', 'Review overdue'], 'rows' => $byRating],
        ];
    }
}
