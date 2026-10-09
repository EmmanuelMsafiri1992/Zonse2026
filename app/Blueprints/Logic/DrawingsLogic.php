<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Drawings & document control: each drawing number carries one revision at a time per status,
 * issuing a revision for construction supersedes the earlier one, and superseded drawings are
 * frozen. Transmittals list drawings by number and can only send drawings that are current;
 * a construction issue only carries drawings that are for construction.
 */
class DrawingsLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'drawings') {
            $revision = strtoupper(trim((string) ($data['revision'] ?? '')));
            if (filled($data['drawing_number'] ?? null) && $revision !== '' && $this->records('drawings')->where('data->drawing_number', $data['drawing_number'])->where('data->revision', $revision)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                $errors['data.revision'] = $data['drawing_number'].' already has a revision '.$revision.'.';
            }
            if ($existing?->status === 'superseded' && $payload['status'] !== 'superseded') {
                $errors['status'] = 'A superseded drawing stays superseded. Issue a new revision instead.';
            }
            if ($payload['status'] === 'as_built' && ! in_array($existing?->status, ['for_construction', 'as_built'], true)) {
                $errors['status'] = 'Only a drawing issued for construction can become as built.';
            }

            return $errors;
        }

        $numbers = $this->numbersIn((string) ($data['drawings'] ?? ''));
        if ($numbers->isEmpty()) {
            $errors['data.drawings'] = 'List the drawings sent, one per line, starting with the drawing number.';

            return $errors;
        }
        $register = $this->records('drawings')->where('status', '!=', 'superseded')->get()->groupBy(fn (Record $drawing) => strtoupper((string) $drawing->value('drawing_number')));
        $missing = $numbers->reject(fn (string $number) => $register->has($number));
        if ($missing->isNotEmpty()) {
            $errors['data.drawings'] = 'Not current in the register: '.$missing->implode(', ').'.';
        } elseif (($data['purpose'] ?? null) === 'construction') {
            $notIssued = $numbers->reject(fn (string $number) => $register[$number]->contains(fn (Record $drawing) => in_array($drawing->status, ['for_construction', 'as_built'], true)));
            if ($notIssued->isNotEmpty()) {
                $errors['data.drawings'] = 'Not issued for construction yet: '.$notIssued->implode(', ').'.';
            }
        }

        return $errors;
    }

    /** The drawing numbers a transmittal lists: the first word of every line, upper-cased. @return Collection<int, string> */
    protected function numbersIn(string $text): Collection
    {
        return collect(preg_split('/\R/', $text))->map(fn (string $line) => strtoupper(trim(strtok(trim($line), " \t,") ?: '')))->filter()->unique()->values();
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'transmittals') {
            $this->put($record, ['_count' => $this->numbersIn((string) $record->value('drawings'))->count()]);

            return;
        }

        $this->put($record, ['revision' => strtoupper(trim((string) $record->value('revision')))]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'drawings' || ! in_array($record->status, ['for_construction', 'as_built'], true) || ! ($record->wasRecentlyCreated || $record->wasChanged('status'))) {
            return;
        }

        foreach ($this->records('drawings')->where('data->drawing_number', $record->value('drawing_number'))->whereKeyNot($record->id)->whereIn('status', ['preliminary', 'for_approval', 'for_construction'])->get() as $older) {
            $older->update(['status' => 'superseded']);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'transmittals') {
            return $record->status === 'sent' ? ['acknowledge' => ['label' => 'Acknowledged', 'icon' => 'mail-check']] : [];
        }

        return $record->status === 'for_approval' ? ['issue' => ['label' => 'Issue for construction', 'icon' => 'stamp', 'confirm' => 'Issue revision '.$record->value('revision').' for construction? Earlier revisions are superseded.']] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'acknowledge') {
            $record->update(['status' => 'acknowledged']);

            return 'Transmittal '.$record->number.' acknowledged by '.$record->title.'.';
        }
        $record->update(['status' => 'for_construction']);

        return $record->value('drawing_number').' rev '.$record->value('revision').' is issued for construction.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'drawings') {
            return [];
        }

        $revisions = $this->records('drawings')->where('data->drawing_number', $record->value('drawing_number'))->orderByDesc('occurs_on')->orderByDesc('id')->get();
        $statuses = $this->app->entities['drawings']->statuses;

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Revision history', 'icon' => 'history', 'empty' => 'No other revisions.',
            'rows' => $revisions->map(fn (Record $revision) => [
                'label' => 'Rev '.$revision->value('revision').($revision->id === $record->id ? ' (this one)' : ''), 'sub' => $revision->occurs_on?->format('d M Y'),
                'value' => $statuses[$revision->status] ?? $revision->status, 'href' => $revision->url(),
                'tone' => $revision->status === 'superseded' ? null : ($revision->status === 'for_construction' ? 'success' : 'warning'),
            ])->values()->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $drawings = $this->records('drawings')->get();
        $current = $drawings->where('status', '!=', 'superseded');
        $waiting = $this->records('transmittals')->where('status', 'sent')->with('contact')->orderBy('occurs_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Register', 'icon' => 'pencil-ruler', 'stats' => [
                ['label' => 'Current drawings', 'value' => (string) $current->count()],
                ['label' => 'For approval', 'value' => (string) $current->where('status', 'for_approval')->count(), 'tone' => $current->where('status', 'for_approval')->isNotEmpty() ? 'warning' : null],
                ['label' => 'For construction', 'value' => (string) $current->whereIn('status', ['for_construction', 'as_built'])->count()],
                ['label' => 'Unacknowledged', 'value' => (string) $waiting->count(), 'tone' => $waiting->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Awaiting acknowledgement', 'icon' => 'send', 'empty' => 'Every transmittal has been acknowledged.',
                'rows' => $waiting->take(10)->map(fn (Record $transmittal) => [
                    'label' => $transmittal->title, 'sub' => $transmittal->occurs_on?->format('d M Y').' · '.ucfirst((string) $transmittal->value('purpose')), 'value' => (int) $transmittal->value('_count').' drawings', 'href' => $transmittal->url(),
                    'tone' => $transmittal->occurs_on?->lt(today()->subDays(7)) ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $drawings = $this->records('drawings')->get();
        $disciplines = $this->app->entities['drawings']->field('discipline')?->options ?? [];
        $byDiscipline = collect($disciplines)->map(fn (string $label, string $key) => [
            $label, $drawings->where('data.discipline', $key)->where('status', '!=', 'superseded')->count(), $drawings->where('data.discipline', $key)->where('status', 'for_approval')->count(),
            $drawings->where('data.discipline', $key)->whereIn('status', ['for_construction', 'as_built'])->count(), $drawings->where('data.discipline', $key)->where('status', 'superseded')->count(),
        ])->values()->all();

        $transmittals = $this->dated('transmittals', $from, $to)->get();
        $purposes = $this->app->entities['transmittals']->field('purpose')?->options ?? [];
        $byPurpose = collect($purposes)->map(fn (string $label, string $key) => [
            $label, $transmittals->where('data.purpose', $key)->count(), (int) $transmittals->where('data.purpose', $key)->sum(fn (Record $transmittal) => (int) $transmittal->value('_count')),
            $transmittals->where('data.purpose', $key)->where('status', 'acknowledged')->count(),
        ])->values()->all();

        return [
            ['title' => 'Register by discipline', 'columns' => ['Discipline', 'Current', 'For approval', 'For construction', 'Superseded'], 'rows' => $byDiscipline],
            ['title' => 'Transmittals by purpose', 'columns' => ['Purpose', 'Sent', 'Drawings', 'Acknowledged'], 'rows' => $byPurpose],
        ];
    }
}
