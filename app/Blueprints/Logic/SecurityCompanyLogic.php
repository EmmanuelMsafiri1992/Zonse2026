<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Security company: occurrences can only be logged against active sites, and every entry gets a time.
 * Incidents and alarms are escalated as soon as they are logged. An escalated entry needs a note on what was
 * done before it is closed. Each site shows its occurrence book and when it was last patrolled.
 */
class SecurityCompanyLogic extends AppLogic
{
    /**
     * Occurrence types escalated as soon as they are logged.
     *
     * @var list<string>
     */
    public const ESCALATE = ['incident', 'alarm'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'sites') {
            if ($payload['status'] === 'active' && (int) ($data['guards_required'] ?? 0) < 1) {
                $errors['data.guards_required'] = 'An active site needs at least one guard.';
            }

            return $errors;
        }
        if (! $existing && filled($data['site'] ?? null) && ($site = $this->records('sites')->find($data['site'])) && $site->status !== 'active') {
            $errors['data.site'] = $site->title.' is '.$site->status.'.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'occurrences') {
            return;
        }
        $record->occurs_on ??= today();
        if (blank($record->value('time'))) {
            $this->put($record, ['time' => now()->format('H:i')]);
        }
        if (! $record->exists && $record->status === 'logged' && in_array($record->value('type'), self::ESCALATE, true)) {
            $record->status = 'escalated';
        }
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'sites' && $record->status === 'active' => ['patrol' => ['label' => 'Log patrol', 'icon' => 'footprints', 'fields' => [['name' => 'details', 'label' => 'Notes', 'type' => 'textarea', 'value' => 'All clear.']]]],
            $record->entity === 'occurrences' && $record->status === 'logged' => ['escalate' => ['label' => 'Escalate', 'icon' => 'siren'], 'close' => ['label' => 'Close', 'icon' => 'check']],
            $record->entity === 'occurrences' && $record->status === 'escalated' => ['close' => ['label' => 'Close', 'icon' => 'check', 'fields' => [['name' => 'action_taken', 'label' => 'Action taken', 'type' => 'textarea', 'value' => '']]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'patrol':
                $details = trim((string) ($request->validate(['details' => ['nullable', 'string']])['details'] ?? '')) ?: 'All clear.';
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'occurrences', 'title' => 'Patrol at '.$record->title,
                    'status' => 'closed', 'occurs_on' => today(), 'assignee_id' => $request->user()->id, 'data' => ['site' => $record->id, 'type' => 'patrol', 'details' => $details],
                ]);

                return 'Patrol logged at '.$record->title.'.';
            case 'escalate':
                $record->update(['status' => 'escalated']);

                return $record->title.' escalated.';
            default:
                $note = trim((string) ($request->validate(['action_taken' => ['nullable', 'string']])['action_taken'] ?? ''));
                if ($record->status === 'escalated' && $note === '') {
                    throw ValidationException::withMessages(['action_taken' => 'Say what was done before closing an escalated entry.']);
                }
                $record->update(['status' => 'closed', 'data' => [...$record->data, 'details' => trim($record->value('details').($note !== '' ? "\n\nAction taken: ".$note : ''))]]);

                return $record->title.' closed.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'sites') {
            return [];
        }
        $entries = $this->linked('occurrences', 'site', $record)->orderByDesc('occurs_on')->orderByDesc('id')->get();
        $lastPatrol = $entries->first(fn (Record $entry) => $entry->value('type') === 'patrol');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Site', 'icon' => 'shield-check', 'stats' => [
                ['label' => 'Open escalations', 'value' => $entries->where('status', 'escalated')->count()],
                ['label' => 'Entries this month', 'value' => $entries->filter(fn (Record $entry) => $entry->occurs_on->isSameMonth(today()))->count()],
                ['label' => 'Last patrol', 'value' => $lastPatrol ? $lastPatrol->occurs_on->format('d M Y').' '.substr((string) $lastPatrol->value('time'), 0, 5) : '—'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Occurrence book', 'icon' => 'notebook-pen', 'empty' => 'No entries yet.',
                'rows' => $entries->take(10)->map(fn (Record $entry) => ['label' => $entry->title, 'sub' => ucfirst((string) $entry->value('type')), 'value' => $entry->occurs_on->format('d M').' '.substr((string) $entry->value('time'), 0, 5), 'href' => $entry->url(), 'tone' => $entry->status === 'escalated' ? 'danger' : null])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $sites = $this->records('sites')->get()->keyBy('id');
        $patrolled = $this->records('occurrences')->whereDate('occurs_on', today()->toDateString())->get()
            ->filter(fn (Record $entry) => $entry->value('type') === 'patrol')->map(fn (Record $entry) => (int) $entry->value('site'))->unique();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Open escalations', 'icon' => 'siren', 'empty' => 'Nothing escalated.',
                'rows' => $this->records('occurrences')->where('status', 'escalated')->orderBy('occurs_on')->get()
                    ->map(fn (Record $entry) => ['label' => $entry->title, 'sub' => $sites->get((int) $entry->value('site'))?->title ?? '', 'value' => $entry->occurs_on->format('d M'), 'href' => $entry->url(), 'tone' => 'danger'])->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Sites', 'icon' => 'building', 'stats' => [
                ['label' => 'Active sites', 'value' => $sites->where('status', 'active')->count()],
                ['label' => 'Guards required', 'value' => (int) $sites->where('status', 'active')->sum(fn (Record $site) => $this->number($site, 'guards_required'))],
                ['label' => 'Not patrolled today', 'value' => $sites->where('status', 'active')->reject(fn (Record $site) => $patrolled->contains($site->id))->count()],
                ['label' => 'Monthly fees', 'value' => $this->money($sites->where('status', 'active')->sum('amount'))],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $sites = $this->records('sites')->get()->keyBy('id');
        $types = ['patrol', 'incident', 'visitor', 'alarm', 'handover', 'other'];

        return [['title' => 'Occurrences by site', 'columns' => ['Site', ...array_map('ucfirst', $types), 'Escalated'], 'rows' => $this->dated('occurrences', $from, $to)->get()
            ->groupBy(fn (Record $entry) => $sites->get((int) $entry->value('site'))?->title ?? 'No site')->sortKeys()
            ->map(fn ($group, string $site) => [$site, ...array_map(fn (string $type) => $group->filter(fn (Record $entry) => ($entry->value('type') ?: 'other') === $type)->count(), $types), $group->filter(fn (Record $entry) => $entry->status === 'escalated' || in_array($entry->value('type'), self::ESCALATE, true))->count()])
            ->values()->all()]];
    }
}
