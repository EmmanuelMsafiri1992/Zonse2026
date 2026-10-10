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
 * Podcast hosting: episodes are numbered per show, taking the next number when none is given, and
 * no two episodes of a show share one. An episode moves forward from planned to recorded, edited and
 * published; publishing needs the audio file, its length and an active show. Edited episodes with a
 * release date go out on it each morning, and download counts only go up.
 */
class PodcastLogic extends AppLogic
{
    /**
     * Episode steps in order.
     */
    protected const STEPS = ['planned', 'recorded', 'edited', 'published'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key === 'shows') {
            $name = mb_strtolower(trim((string) $payload['title']));

            return $this->records('shows')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $show) => mb_strtolower(trim((string) $show->title)) === $name)
                ? ['title' => 'There is already a show called '.trim((string) $payload['title']).'.'] : [];
        }

        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];
        $show = filled($data['show'] ?? null) ? $this->records('shows')->find($data['show']) : null;

        if ($existing && array_search($status, self::STEPS, true) < array_search($existing->status, self::STEPS, true)) {
            $errors['status'] = 'An episode cannot go back to '.$status.'.';
        }
        if (filled($data['episode_number'] ?? null) && $show) {
            $number = (int) $data['episode_number'];
            if ($number < 1) {
                $errors['data.episode_number'] = 'Episodes are numbered from 1.';
            } elseif ($twin = $this->linked('episodes', 'show', $show)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->first(fn (Record $episode) => (int) $episode->value('episode_number') === $number)) {
                $errors['data.episode_number'] = $show->title.' already has episode '.$number.': '.$twin->title.'.';
            }
        }
        if ($status === 'published' && $existing?->status !== 'published') {
            if ($problem = $this->cannotPublish($show, $data['audio_url'] ?? null, (float) ($data['duration'] ?? 0))) {
                $errors[$problem[0]] = $problem[1];
            }
        }
        if ($existing && (float) ($data['downloads'] ?? 0) < $this->number($existing, 'downloads')) {
            $errors['data.downloads'] = 'Downloads only go up; it already has '.number_format($this->number($existing, 'downloads')).'.';
        }

        return $errors;
    }

    /**
     * Why an episode can't be published, as [field, message], or null when it can.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function cannotPublish(?Record $show, mixed $audio, float $duration): ?array
    {
        return match (true) {
            ! $show => ['data.show', 'Choose the show.'],
            $show->status !== 'active' => ['data.show', $show->title.' is '.str_replace('_', ' ', $show->status).'.'],
            blank($audio) => ['data.audio_url', 'Add the audio file before publishing.'],
            $duration <= 0 => ['data.duration', 'Give the episode\'s length.'],
            default => null,
        };
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'episodes') {
            return;
        }
        if (blank($record->value('episode_number')) && ($show = $this->parent($record, 'show'))) {
            $last = $this->linked('episodes', 'show', $show)->get()->max(fn (Record $episode) => (int) $episode->value('episode_number'));
            $this->put($record, ['episode_number' => (int) $last + 1]);
        }
        if ($record->isDirty('status') && $record->status === 'published') {
            $record->occurs_on ??= today();
            $this->put($record, ['_published_at' => now()->toDateTimeString()]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'episodes') {
            return [];
        }

        return match ($record->status) {
            'planned' => ['recorded' => ['label' => 'Recorded', 'icon' => 'mic']],
            'recorded' => ['edited' => ['label' => 'Edited', 'icon' => 'scissors']],
            'edited' => ['publish' => ['label' => 'Publish now', 'icon' => 'radio']],
            'published' => ['downloads' => ['label' => 'Update downloads', 'icon' => 'download', 'fields' => [['name' => 'downloads', 'label' => 'Downloads to date', 'type' => 'number', 'value' => $record->value('downloads') ?? 0]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'publish':
                if ($problem = $this->cannotPublish($this->parent($record, 'show'), $record->value('audio_url'), $this->number($record, 'duration'))) {
                    throw ValidationException::withMessages([str_replace('data.', '', $problem[0]) => $problem[1]]);
                }
                $record->update(['status' => 'published', 'occurs_on' => today()]);

                return 'Episode '.$record->value('episode_number').', '.$record->title.', is out.';
            case 'downloads':
                $downloads = (int) $request->validate(['downloads' => ['required', 'integer', 'min:'.(int) $this->number($record, 'downloads')]])['downloads'];
                $record->update(['data' => [...$record->data, 'downloads' => $downloads]]);

                return $record->title.': '.number_format($downloads).' downloads.';
            default:
                $record->update(['status' => $action]);

                return $record->title.' '.$action.'.';
        }
    }

    public function daily(Workspace $workspace): int
    {
        $published = 0;
        foreach ($this->records('episodes')->where('status', 'edited')->whereNotNull('occurs_on')->whereDate('occurs_on', '<=', today()->toDateString())->get() as $episode) {
            if (! $this->cannotPublish($this->parent($episode, 'show'), $episode->value('audio_url'), $this->number($episode, 'duration'))) {
                $episode->update(['status' => 'published']);
                $published++;
            }
        }

        return $published;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'shows') {
            return [];
        }
        $episodes = $this->linked('episodes', 'show', $record)->get();
        $out = $episodes->where('status', 'published');

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Show', 'icon' => 'radio', 'stats' => [
            ['label' => 'Episodes out', 'value' => $out->count()],
            ['label' => 'In production', 'value' => $episodes->where('status', '!=', 'published')->count()],
            ['label' => 'Downloads', 'value' => number_format($out->sum(fn (Record $episode) => $this->number($episode, 'downloads')))],
            ['label' => 'Average per episode', 'value' => $out->isNotEmpty() ? number_format($out->avg(fn (Record $episode) => $this->number($episode, 'downloads'))) : '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        $coming = $this->records('episodes')->where('status', '!=', 'published')->whereNotNull('occurs_on')->orderBy('occurs_on')->limit(10)->get();
        $shows = $this->records('shows')->pluck('title', 'id');

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Coming up', 'icon' => 'calendar-days', 'empty' => 'No release dates set.',
            'rows' => $coming->map(fn (Record $episode) => [
                'label' => $episode->title, 'sub' => ($shows[$episode->value('show')] ?? '').' · '.$episode->status, 'value' => $episode->occurs_on->format('d M'), 'href' => $episode->url(),
                'tone' => $episode->occurs_on->lte(today()->addDays(2)) && $episode->status !== 'edited' ? 'warning' : null,
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $episodes = $this->dated('episodes', $from, $to)->where('status', 'published')->get();
        $shows = $this->records('shows')->pluck('title', 'id');

        return [
            ['title' => 'Shows', 'columns' => ['Show', 'Episodes released', 'Downloads', 'Average per episode', 'Minutes released'], 'rows' => $episodes->groupBy(fn (Record $episode) => $shows[$episode->value('show')] ?? '—')->sortKeys()
                ->map(fn (Collection $group, string $show) => [$show, $group->count(), number_format($group->sum(fn (Record $episode) => $this->number($episode, 'downloads'))), number_format($group->avg(fn (Record $episode) => $this->number($episode, 'downloads'))), number_format($group->sum(fn (Record $episode) => $this->number($episode, 'duration')))])->values()->all()],
            ['title' => 'Top episodes', 'columns' => ['Episode', 'Show', 'Released', 'Downloads'], 'rows' => $episodes->sortByDesc(fn (Record $episode) => $this->number($episode, 'downloads'))->take(10)
                ->map(fn (Record $episode) => ['#'.$episode->value('episode_number').' '.$episode->title, $shows[$episode->value('show')] ?? '—', $episode->occurs_on?->format('d M Y') ?? '', number_format($this->number($episode, 'downloads'))])->values()->all()],
        ];
    }
}
