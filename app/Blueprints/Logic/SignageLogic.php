<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Digital signage: a playlist's slides are one per line, either an image link or a short line of
 * text, each shown for 3 to 300 seconds (8 if not set), so the loop length is worked out. A screen
 * goes online only with an active playlist, and a playlist that screens are showing can't be taken
 * back to draft. Screen names are unique so staff can tell them apart.
 */
class SignageLogic extends AppLogic
{
    /**
     * Seconds per slide when none is set.
     */
    public const DEFAULT_SECONDS = 8;

    /**
     * Longest line of text a slide can carry and still be read from across a room.
     */
    protected const TEXT_LIMIT = 120;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'playlists') {
            $slides = $this->slides((string) ($data['slides'] ?? ''));
            if (is_string($slides)) {
                $errors['data.slides'] = $slides;
            } elseif ($payload['status'] === 'active' && $slides === []) {
                $errors['data.slides'] = 'Add at least one slide.';
            }
            $seconds = $data['seconds_per_slide'] ?? null;
            if (filled($seconds) && ((float) $seconds < 3 || (float) $seconds > 300)) {
                $errors['data.seconds_per_slide'] = 'Show each slide for 3 to 300 seconds.';
            }
            if ($existing && $payload['status'] !== 'active' && ($showing = $this->linked('screens', 'playlist', $existing)->where('status', 'online')->count()) > 0) {
                $errors['status'] = $showing.' online '.str('screen')->plural($showing).' '.($showing === 1 ? 'is' : 'are').' showing this playlist.';
            }

            return $errors;
        }

        $name = mb_strtolower(trim((string) $payload['title']));
        if ($this->records('screens')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $screen) => mb_strtolower(trim((string) $screen->title)) === $name)) {
            $errors['title'] = 'There is already a screen called '.trim((string) $payload['title']).'.';
        }
        if ($payload['status'] === 'online') {
            $playlist = filled($data['playlist'] ?? null) ? $this->records('playlists')->find($data['playlist']) : null;
            if (! $playlist) {
                $errors['data.playlist'] = 'Choose what the screen shows before putting it online.';
            } elseif ($playlist->status !== 'active') {
                $errors['data.playlist'] = $playlist->title.' is still a draft.';
            }
        }

        return $errors;
    }

    /**
     * A playlist's slides as [kind, content] pairs, or the problem with them.
     *
     * @return list<array{0: string, 1: string}>|string
     */
    public function slides(string $text): array|string
    {
        $slides = [];
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), fn (string $line) => $line !== ''));
        foreach ($lines as $index => $line) {
            if (preg_match('#^https?://#i', $line)) {
                if (! filter_var($line, FILTER_VALIDATE_URL)) {
                    return 'Slide '.($index + 1).' is not a valid link.';
                }
                $slides[] = ['image', $line];
            } elseif (mb_strlen($line) > self::TEXT_LIMIT) {
                return 'Slide '.($index + 1).' has '.mb_strlen($line).' characters; keep text slides to '.self::TEXT_LIMIT.' so they can be read.';
            } else {
                $slides[] = ['text', $line];
            }
        }

        return $slides;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'playlists') {
            return;
        }
        if (blank($record->value('seconds_per_slide'))) {
            $this->put($record, ['seconds_per_slide' => self::DEFAULT_SECONDS]);
        }
        $slides = $this->slides((string) $record->value('slides'));
        $count = is_array($slides) ? count($slides) : 0;
        $this->put($record, ['_slides' => $count, '_loop_seconds' => (int) round($count * $this->number($record, 'seconds_per_slide'))]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'screens') {
            return $record->status === 'online' ? ['offline' => ['label' => 'Take offline', 'icon' => 'power-off']] : ['online' => ['label' => 'Put online', 'icon' => 'power']];
        }

        return $record->status === 'draft' ? ['activate' => ['label' => 'Activate', 'icon' => 'play']] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'online':
                $playlist = $this->parent($record, 'playlist');
                if (! $playlist || $playlist->status !== 'active') {
                    throw ValidationException::withMessages(['playlist' => $playlist ? $playlist->title.' is still a draft.' : 'Choose what the screen shows before putting it online.']);
                }
                $record->update(['status' => 'online', 'data' => [...$record->data, '_online_since' => now()->toDateTimeString()]]);

                return $record->title.' is showing '.$playlist->title.'.';
            case 'offline':
                $record->update(['status' => 'offline']);

                return $record->title.' is offline.';
            default:
                if ((int) $record->value('_slides') === 0) {
                    throw ValidationException::withMessages(['slides' => 'Add at least one slide.']);
                }
                $record->update(['status' => 'active']);

                return $record->title.' is active: '.$record->value('_slides').' slides in a '.$this->duration((int) $record->value('_loop_seconds')).' loop.';
        }
    }

    /**
     * Seconds in words: "45s" or "2 min 10s".
     */
    protected function duration(int $seconds): string
    {
        return $seconds < 60 ? $seconds.'s' : intdiv($seconds, 60).' min'.($seconds % 60 ? ' '.($seconds % 60).'s' : '');
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'playlists') {
            return [];
        }
        $screens = $this->linked('screens', 'playlist', $record)->orderBy('title')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Loop', 'icon' => 'repeat', 'stats' => [
                ['label' => 'Slides', 'value' => $record->value('_slides')],
                ['label' => 'Each slide', 'value' => $this->duration((int) $this->number($record, 'seconds_per_slide'))],
                ['label' => 'Loop length', 'value' => $this->duration((int) $record->value('_loop_seconds'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Shown on', 'icon' => 'tv', 'empty' => 'No screens show this playlist.',
                'rows' => $screens->map(fn (Record $screen) => ['label' => $screen->title, 'sub' => $screen->value('location'), 'value' => ucfirst($screen->status), 'href' => $screen->url(), 'tone' => $screen->status === 'online' ? 'success' : null])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $screens = $this->records('screens')->orderBy('title')->get();
        $playlists = $this->records('playlists')->pluck('title', 'id');

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Screens', 'icon' => 'tv', 'empty' => 'No screens yet.',
            'rows' => $screens->map(fn (Record $screen) => [
                'label' => $screen->title, 'sub' => $screen->value('location'), 'value' => $screen->status === 'online' ? ($playlists[$screen->value('playlist')] ?? 'Online') : 'Offline',
                'href' => $screen->url(), 'tone' => $screen->status === 'online' ? 'success' : 'danger',
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $screens = $this->records('screens')->get();
        $playlists = $this->records('playlists')->orderBy('title')->get();

        return [
            ['title' => 'Playlists', 'columns' => ['Playlist', 'Status', 'Slides', 'Loop length', 'Screens online'], 'rows' => $playlists->map(fn (Record $playlist) => [
                $playlist->title, ucfirst($playlist->status), (int) $playlist->value('_slides'), $this->duration((int) $playlist->value('_loop_seconds')),
                $screens->filter(fn (Record $screen) => (int) $screen->value('playlist') === $playlist->id && $screen->status === 'online')->count(),
            ])->values()->all()],
            ['title' => 'Screens by location', 'columns' => ['Location', 'Screens', 'Online', 'Offline'], 'rows' => $screens->groupBy(fn (Record $screen) => (string) $screen->value('location'))->sortKeys()
                ->map(fn (Collection $group, string $location) => [$location, $group->count(), $group->where('status', 'online')->count(), $group->where('status', 'offline')->count()])->values()->all()],
        ];
    }
}
