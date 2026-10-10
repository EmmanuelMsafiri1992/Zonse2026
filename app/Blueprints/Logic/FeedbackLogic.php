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
 * Feedback, surveys & reviews: scores run from 0 to 10, and each response is a promoter (9–10), passive (7–8)
 * or detractor (0–6). Net Promoter Score is the share of promoters less the share of detractors. A survey
 * counts its own responses, only takes new ones while live, and live surveys past their closing date
 * close each night. Replying to a response marks it actioned.
 */
class FeedbackLogic extends AppLogic
{
    /**
     * NPS group for a 0–10 score.
     */
    public static function group(?float $score): ?string
    {
        return match (true) {
            $score === null => null,
            $score >= 9 => 'promoter',
            $score >= 7 => 'passive',
            default => 'detractor',
        };
    }

    /**
     * Net Promoter Score for a set of responses, or null when none are scored.
     *
     * @param  Collection<int, Record>  $responses
     */
    public static function nps(Collection $responses): ?int
    {
        $scored = $responses->filter(fn (Record $response) => filled($response->value('_nps')));

        return $scored->isEmpty() ? null : (int) round(($scored->where('data._nps', 'promoter')->count() - $scored->where('data._nps', 'detractor')->count()) / $scored->count() * 100);
    }

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'surveys') {
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The survey must close after it opens.';
            }

            return $errors;
        }
        if (filled($data['score'] ?? null) && ((float) $data['score'] < 0 || (float) $data['score'] > 10)) {
            $errors['data.score'] = 'Scores run from 0 to 10.';
        }
        if (! $existing && filled($data['survey'] ?? null) && ($survey = $this->records('surveys')->find($data['survey'])) && $survey->status !== 'live') {
            $errors['data.survey'] = 'Survey '.$survey->title.' is '.$survey->status.' and not taking responses.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'responses') {
            $this->put($record, ['_nps' => self::group(filled($record->value('score')) ? $this->number($record, 'score') : null)]);

            return;
        }
        if ($record->exists) {
            $this->put($record, ['responses' => $this->linked('responses', 'survey', $record)->count()]);
        }
        if ($record->status === 'live' && $record->due_on && $record->due_on->lt(today())) {
            $record->status = 'closed';
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'responses') {
            $this->parent($record, 'survey')?->save();
            $this->previousParent($record, 'survey')?->save();
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'responses') {
            $this->parent($record, 'survey')?->save();
        }
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('surveys')->where('status', 'live')->whereDate('due_on', '<', today()->toDateString())->get()
            ->each(fn (Record $survey) => $survey->save())->count();
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'surveys' && $record->status === 'draft' => ['launch' => ['label' => 'Go live', 'icon' => 'play']],
            $record->entity === 'surveys' && $record->status === 'live' => ['close' => ['label' => 'Close', 'icon' => 'lock']],
            $record->entity === 'responses' && $record->status === 'new' => ['reply' => ['label' => 'Reply', 'icon' => 'reply', 'fields' => [['name' => 'reply', 'label' => 'Our reply', 'type' => 'textarea', 'value' => $record->value('reply')]]]],
            $record->entity === 'responses' && $record->status === 'actioned' => ['close' => ['label' => 'Close', 'icon' => 'check']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'launch':
                if ($record->due_on && $record->due_on->lt(today())) {
                    throw ValidationException::withMessages(['due_on' => 'The closing date has passed; move it first.']);
                }
                $record->update(['status' => 'live', 'occurs_on' => $record->occurs_on ?? today()]);

                return $record->title.' is live'.($record->due_on ? ' until '.$record->due_on->format('d M Y') : '').'.';
            case 'reply':
                $reply = trim((string) ($request->validate(['reply' => ['nullable', 'string', 'max:5000']])['reply'] ?? ''));
                if ($reply === '') {
                    throw ValidationException::withMessages(['reply' => 'Write the reply.']);
                }
                $record->update(['status' => 'actioned', 'data' => [...$record->data, 'reply' => $reply]]);

                return 'Reply saved for '.$record->title.'.';
            default:
                $record->update(['status' => 'closed']);

                return $record->title.' closed.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'surveys') {
            return [];
        }
        $responses = $this->linked('responses', 'survey', $record)->get();
        $nps = self::nps($responses);

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Results', 'icon' => 'gauge', 'stats' => [
            ['label' => 'Responses', 'value' => $responses->count()],
            ['label' => 'NPS', 'value' => $nps ?? '—'],
            ['label' => 'Promoters / detractors', 'value' => $responses->where('data._nps', 'promoter')->count().' / '.$responses->where('data._nps', 'detractor')->count()],
        ]]]];
    }

    public function homeCards(): array
    {
        $recent = $this->records('responses')->whereDate('occurs_on', '>=', today()->subDays(90)->toDateString())->get();
        $unhappy = $this->records('responses')->where('status', 'new')->get()->where('data._nps', 'detractor')->sortBy('occurs_on');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Last 90 days', 'icon' => 'star', 'stats' => [
                ['label' => 'Responses', 'value' => $recent->count()],
                ['label' => 'NPS', 'value' => self::nps($recent) ?? '—'],
                ['label' => 'Waiting for a reply', 'value' => $this->records('responses')->where('status', 'new')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Unhappy customers to answer', 'icon' => 'frown', 'empty' => 'No unanswered detractors.',
                'rows' => $unhappy->map(fn (Record $response) => ['label' => $response->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $response->value('source'))), 'value' => 'scored '.(int) $this->number($response, 'score'), 'href' => $response->url(), 'tone' => 'danger'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $responses = $this->dated('responses', $from, $to)->get();

        return [
            ['title' => 'NPS by month', 'columns' => ['Month', 'Responses', 'Promoters', 'Detractors', 'NPS'], 'rows' => collect($this->months($from, $to))
                ->map(function (string $label, string $month) use ($responses) {
                    $inMonth = $responses->filter(fn (Record $response) => $response->occurs_on->format('Y-m') === $month);

                    return [$label, $inMonth->count(), $inMonth->where('data._nps', 'promoter')->count(), $inMonth->where('data._nps', 'detractor')->count(), self::nps($inMonth) ?? '—'];
                })->values()->all()],
            ['title' => 'Feedback by source', 'columns' => ['Source', 'Responses', 'Average score', 'NPS'], 'rows' => $responses
                ->groupBy(fn (Record $response) => ucfirst(str_replace('_', ' ', (string) $response->value('source'))))->sortKeys()
                ->map(function ($group, string $source) {
                    $scored = $group->filter(fn (Record $response) => filled($response->value('score')));

                    return [$source, $group->count(), $scored->isNotEmpty() ? number_format($scored->avg(fn (Record $response) => $this->number($response, 'score')), 1) : '—', self::nps($group) ?? '—'];
                })->values()->all()],
        ];
    }
}
