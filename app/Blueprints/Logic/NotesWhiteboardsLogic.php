<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Notes & whiteboards: a whiteboard keeps a picture of the board, and a checklist is written one
 * item per line ("[x]" marks an item done) so its progress is counted. Archived notes stay
 * read-only until they are brought back.
 */
class NotesWhiteboardsLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if (($data['type'] ?? null) === 'whiteboard' && blank($data['image_url'] ?? null)) {
            $errors['data.image_url'] = 'Add a picture of the whiteboard.';
        }
        if (($data['type'] ?? null) === 'checklist' && $this->items((string) ($data['body'] ?? '')) === []) {
            $errors['data.body'] = 'Write one checklist item per line.';
        }
        if ($existing?->status === 'archived' && $payload['status'] === 'archived'
            && ((string) $existing->value('body') !== (string) ($data['body'] ?? '') || $existing->title !== $payload['title'])) {
            $errors['status'] = 'This note is archived. Make it active again to change it.';
        }

        return $errors;
    }

    /**
     * Checklist lines as item => done.
     *
     * @return array<int, array{text: string, done: bool}>
     */
    public function items(string $body): array
    {
        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim(preg_replace('/^[-*]\s*/', '', trim($line)));
            if ($line === '') {
                continue;
            }
            $done = (bool) preg_match('/^\[[xX]\]/', $line);
            $items[] = ['text' => trim(preg_replace('/^\[[ xX]?\]/', '', $line)), 'done' => $done];
        }

        return $items;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->value('type') !== 'checklist') {
            $this->put($record, ['_items' => null, '_done' => null]);

            return;
        }

        $items = $this->items((string) $record->value('body'));
        $this->put($record, ['_items' => count($items), '_done' => count(array_filter($items, fn (array $item) => $item['done']))]);
    }

    public function recordCards(Record $record): array
    {
        if ($record->value('type') !== 'checklist') {
            return [];
        }

        $items = $this->items((string) $record->value('body'));
        $done = count(array_filter($items, fn (array $item) => $item['done']));

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Checklist', 'icon' => 'list-checks', 'stats' => [
            ['label' => 'Done', 'value' => $done.' of '.count($items), 'tone' => $done === count($items) ? 'success' : null],
            ['label' => 'Progress', 'value' => count($items) ? round($done / count($items) * 100).'%' : '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        $notes = $this->records('notes')->where('status', 'active')->get();
        $checklists = $notes->where('data.type', 'checklist');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Notes', 'icon' => 'sticky-note', 'stats' => [
                ['label' => 'Active notes', 'value' => (string) $notes->count()],
                ['label' => 'Shared with team', 'value' => (string) $notes->filter(fn (Record $note) => (bool) $note->value('shared'))->count()],
                ['label' => 'Open checklist items', 'value' => (string) $checklists->sum(fn (Record $note) => (int) $note->value('_items') - (int) $note->value('_done'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Shared with the team', 'icon' => 'users', 'empty' => 'Nothing shared yet.',
                'rows' => $notes->filter(fn (Record $note) => (bool) $note->value('shared'))->sortByDesc('id')->take(8)->map(fn (Record $note) => [
                    'label' => $note->title, 'sub' => ucfirst((string) $note->value('type')), 'value' => $note->occurs_on?->format('d M') ?? '', 'href' => $note->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $notes = $this->dated('notes', $from, $to)->get();

        $types = $notes->groupBy(fn (Record $note) => (string) $note->value('type'))->sortKeys()->map(fn ($group, $type) => [
            ucfirst($type), $group->where('status', 'active')->count(), $group->where('status', 'archived')->count(), $group->filter(fn (Record $note) => (bool) $note->value('shared'))->count(),
        ])->values()->all();

        $checklists = $notes->where('data.type', 'checklist')->where('status', 'active')->sortBy('title')->map(fn (Record $note) => [
            $note->title, (int) $note->value('_done').' / '.(int) $note->value('_items'), $note->value('_items') ? round((int) $note->value('_done') / (int) $note->value('_items') * 100).'%' : '—',
        ])->values()->all();

        return [
            ['title' => 'Notes by type', 'columns' => ['Type', 'Active', 'Archived', 'Shared'], 'rows' => $types],
            ['title' => 'Checklist progress', 'columns' => ['Checklist', 'Done', 'Progress'], 'rows' => $checklists],
        ];
    }
}
