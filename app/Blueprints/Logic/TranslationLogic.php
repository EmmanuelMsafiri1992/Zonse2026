<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Translation & interpreting: the languages must differ, and a written job's price is its word count times
 * the rate. A job past the quote needs a translator. Sworn translations must be proofread before they are
 * delivered. The report shows words translated by each translator.
 */
class TranslationLogic extends AppLogic
{
    /**
     * Job types priced by the word.
     *
     * @var list<string>
     */
    public const BY_THE_WORD = ['translation', 'sworn_translation', 'subtitling'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if (filled($data['source_language'] ?? null) && strcasecmp(trim((string) $data['source_language']), trim((string) ($data['target_language'] ?? ''))) === 0) {
            $errors['data.target_language'] = 'The target language must differ from the source.';
        }
        if ($payload['status'] !== 'quoted' && blank($data['translator'] ?? null)) {
            $errors['data.translator'] = 'Give the translator.';
        }
        if ($payload['status'] === 'delivered' && ($data['type'] ?? null) === 'sworn_translation' && (! $existing || ! in_array($existing->status, ['proofreading', 'delivered'], true))) {
            $errors['status'] = 'A sworn translation must be proofread before it is delivered.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if (in_array($record->value('type'), self::BY_THE_WORD, true) && $this->number($record, 'word_count') > 0 && $this->number($record, 'rate') > 0) {
            $record->amount = round($this->number($record, 'word_count') * $this->number($record, 'rate'), 2);
        }
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'quoted' => ['assign' => ['label' => 'Assign', 'icon' => 'user-check', 'fields' => [['name' => 'translator', 'label' => 'Translator', 'type' => 'select', 'value' => $record->value('translator'), 'options' => Workspace::query()->find($record->workspace_id)?->members()->orderBy('users.name')->pluck('users.name', 'users.id')->all() ?? []]]]],
            'assigned' => ['start' => ['label' => 'Start', 'icon' => 'play']],
            'translating' => [
                'proofread' => ['label' => 'Send to proofreading', 'icon' => 'spell-check'],
                ...($record->value('type') === 'sworn_translation' ? [] : ['deliver' => ['label' => 'Deliver', 'icon' => 'send']]),
            ],
            'proofreading' => ['deliver' => ['label' => 'Deliver', 'icon' => 'send']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'assign':
                $translator = (int) ($request->input('translator') ?: $request->user()->id);
                $record->update(['status' => 'assigned', 'data' => [...$record->data, 'translator' => $translator]]);

                return $record->title.' assigned to '.User::query()->whereKey($translator)->value('name').'.';
            case 'start':
                $record->update(['status' => 'translating']);

                return $record->title.' in translation.';
            case 'proofread':
                $record->update(['status' => 'proofreading']);

                return $record->title.' sent to proofreading.';
            default:
                if ($record->value('type') === 'sworn_translation' && $record->status !== 'proofreading') {
                    throw ValidationException::withMessages(['status' => 'A sworn translation must be proofread before it is delivered.']);
                }
                $record->update(['status' => 'delivered', 'data' => [...$record->data, '_delivered_on' => today()->toDateString()]]);

                return $record->title.' delivered'.($record->due_on && $record->due_on->lt(today()) ? ', '.($late = (int) $record->due_on->diffInDays(today())).' '.str('day')->plural($late).' late' : ' on time').'.';
        }
    }

    public function homeCards(): array
    {
        $open = $this->records('jobs')->where('status', '!=', 'delivered')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Due in the next 3 days', 'icon' => 'alarm-clock', 'empty' => 'Nothing due soon.',
                'rows' => $open->filter(fn (Record $job) => $job->due_on && $job->due_on->lte(today()->addDays(3)))->sortBy('due_on')
                    ->map(fn (Record $job) => ['label' => $job->title, 'sub' => $job->value('source_language').' → '.$job->value('target_language'), 'value' => $job->due_on->format('d M'), 'href' => $job->url(), 'tone' => $job->due_on->lt(today()) ? 'danger' : 'warning'])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Work in hand', 'icon' => 'languages', 'stats' => [
                ['label' => 'Quotes out', 'value' => $open->where('status', 'quoted')->count()],
                ['label' => 'In progress', 'value' => $open->whereIn('status', ['assigned', 'translating', 'proofreading'])->count()],
                ['label' => 'Words in progress', 'value' => number_format($open->where('status', '!=', 'quoted')->sum(fn (Record $job) => $this->number($job, 'word_count')))],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $jobs = $this->dated('jobs', $from, $to)->where('status', '!=', 'quoted')->get();
        $names = User::query()->whereIn('id', $jobs->map(fn (Record $job) => $job->value('translator'))->filter()->unique())->pluck('name', 'id');

        return [
            ['title' => 'Work by translator', 'columns' => ['Translator', 'Jobs', 'Delivered', 'Words', 'Value'], 'rows' => $jobs
                ->groupBy(fn (Record $job) => $names[$job->value('translator')] ?? 'Unassigned')->sortKeys()
                ->map(fn ($group, string $translator) => [$translator, $group->count(), $group->where('status', 'delivered')->count(), number_format($group->sum(fn (Record $job) => $this->number($job, 'word_count'))), $this->money($group->sum('amount'))])
                ->values()->all()],
            ['title' => 'Work by language pair', 'columns' => ['Languages', 'Jobs', 'Value'], 'rows' => $jobs
                ->groupBy(fn (Record $job) => ucfirst(trim((string) $job->value('source_language'))).' → '.ucfirst(trim((string) $job->value('target_language'))))->sortKeys()
                ->map(fn ($group, string $pair) => [$pair, $group->count(), $this->money($group->sum('amount'))])
                ->values()->all()],
        ];
    }
}
