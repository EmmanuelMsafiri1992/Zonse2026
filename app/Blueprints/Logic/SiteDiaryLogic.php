<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Site diary: one entry per site per day, never dated in the future, and locked once signed off.
 * Entries move draft → submitted → signed off in one click, delays are flagged on the entry, and
 * reports total labour per site and the weather and delays over the period.
 */
class SiteDiaryLogic extends AppLogic
{
    public const DETAILS = ['weather', 'workers_on_site', 'work_done', 'deliveries', 'delays', 'photos_url'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ((float) ($data['workers_on_site'] ?? 0) < 0) {
            $errors['data.workers_on_site'] = 'Workers on site cannot be negative.';
        }
        $date = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today();
        if ($date->gt(today())) {
            $errors['occurs_on'] = 'A diary entry cannot be dated in the future.';
        }
        if ($this->records('entries')->where('title', $payload['title'])->whereDate('occurs_on', $date->toDateString())->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
            $errors['title'] = 'There is already an entry for '.$payload['title'].' on '.$date->format('d M Y').'.';
        }
        if ($existing?->status === 'signed_off' && $payload['status'] === 'signed_off') {
            foreach (self::DETAILS as $key) {
                if ((string) ($data[$key] ?? '') !== (string) ($existing->value($key) ?? '')) {
                    $errors['status'] = 'This entry is signed off. Reopen it to change the details.';
                    break;
                }
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        $this->put($record, ['_has_delays' => filled($record->value('delays'))]);
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'draft' => ['submit' => ['label' => 'Submit', 'icon' => 'send']],
            'submitted' => ['sign_off' => ['label' => 'Sign off', 'icon' => 'check-check', 'confirm' => 'Sign off this entry? It is locked afterwards.']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $record->update(['status' => $action === 'submit' ? 'submitted' : 'signed_off']);

        return $record->title.' for '.$record->occurs_on->format('d M').' is '.($action === 'submit' ? 'submitted' : 'signed off').'.';
    }

    public function recordCards(Record $record): array
    {
        if (blank($record->value('delays'))) {
            return [];
        }

        return [['view' => 'apps.logic.alert-card', 'data' => ['tone' => 'warning', 'icon' => 'clock-alert', 'title' => 'Delays reported', 'body' => $record->value('delays')]]];
    }

    public function homeCards(): array
    {
        $week = $this->dated('entries', today()->startOfWeek(), today()->endOfWeek())->get();
        $waiting = $this->records('entries')->where('status', 'submitted')->orderBy('occurs_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'This week', 'icon' => 'notebook-pen', 'stats' => [
                ['label' => 'Entries', 'value' => (string) $week->count()],
                ['label' => 'Workers a day', 'value' => $week->isEmpty() ? '—' : (string) round($week->avg(fn (Record $entry) => $this->number($entry, 'workers_on_site')))],
                ['label' => 'Rain days', 'value' => (string) $week->whereIn('data.weather', ['rain', 'storm'])->count()],
                ['label' => 'With delays', 'value' => (string) $week->where('data._has_delays', true)->count(), 'tone' => $week->where('data._has_delays', true)->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Waiting for sign-off', 'icon' => 'file-check', 'empty' => 'Every submitted entry is signed off.',
                'rows' => $waiting->take(10)->map(fn (Record $entry) => [
                    'label' => $entry->title, 'sub' => $entry->occurs_on->format('D d M'), 'value' => (int) $entry->value('workers_on_site').' on site', 'href' => $entry->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $entries = $this->dated('entries', $from, $to)->get();

        $labour = $entries->groupBy('title')->sortKeys()->map(fn ($group, $site) => [
            $site, $group->count(), round($group->avg(fn (Record $entry) => $this->number($entry, 'workers_on_site')), 1), (int) $group->sum(fn (Record $entry) => $this->number($entry, 'workers_on_site')),
        ])->values()->all();

        $weather = collect(['sunny', 'cloudy', 'rain', 'storm', 'windy'])->map(fn (string $kind) => [ucfirst($kind), $entries->where('data.weather', $kind)->count()])->all();

        $delays = $entries->where('data._has_delays', true)->sortBy('occurs_on')->map(fn (Record $entry) => [
            $entry->occurs_on->format('d M Y'), $entry->title, str($entry->value('delays'))->limit(80)->toString(),
        ])->values()->all();

        return [
            ['title' => 'Labour by site', 'columns' => ['Site', 'Days', 'Workers a day', 'Worker-days'], 'rows' => $labour],
            ['title' => 'Weather', 'columns' => ['Weather', 'Days'], 'rows' => $weather],
            ['title' => 'Delays & issues', 'columns' => ['Date', 'Site', 'Delay'], 'rows' => $delays],
        ];
    }
}
