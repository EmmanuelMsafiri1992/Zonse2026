<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Documents & files: folders cannot sit inside themselves, archived folders take no new
 * documents, an approved document carries its version, and approving a newer version of a
 * document makes the older approved one obsolete. Approved documents come up for review.
 */
class DocumentsLogic extends AppLogic
{
    public const REVIEW_WARNING_DAYS = 14;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'folders') {
            if ($existing && ! empty($data['parent']) && in_array((int) $data['parent'], [$existing->id, ...$this->descendants($existing)], true)) {
                $errors['data.parent'] = 'A folder cannot go inside itself or one of its own subfolders.';
            }

            return $errors;
        }

        $folder = ! empty($data['folder']) ? $this->records('folders')->find($data['folder']) : null;
        if ($folder?->status === 'archived' && (! $existing || (int) $existing->value('folder') !== $folder->id)) {
            $errors['data.folder'] = 'The folder '.$folder->title.' is archived.';
        }
        if ($payload['status'] === 'approved' && blank($data['version'] ?? null)) {
            $errors['data.version'] = 'Give the approved document a version.';
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The review date cannot be before the document was updated.';
        }

        return $errors;
    }

    /** @return list<int> */
    protected function descendants(Record $folder): array
    {
        $ids = [];
        $level = [$folder->id];
        while ($level) {
            $level = $this->records('folders')->get()->filter(fn (Record $child) => in_array((int) $child->value('parent'), $level, true))->pluck('id')->diff($ids)->values()->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'folders') {
            $this->put($record, ['_documents' => $record->exists ? $this->linked('documents', 'folder', $record)->count() : 0]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'documents') {
            return;
        }

        if ($record->status === 'approved' && ($record->wasRecentlyCreated || $record->wasChanged('status'))) {
            $this->records('documents')->where('title', $record->title)->where('status', 'approved')->whereKeyNot($record->id)->get()
                ->filter(fn (Record $older) => (int) $older->value('folder') === (int) $record->value('folder'))
                ->each(fn (Record $older) => $older->update(['status' => 'obsolete']));
        }
        $this->recalculate($this->parent($record, 'folder'));
        $this->recalculate($this->previousParent($record, 'folder'));
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'documents') {
            $this->recalculate($this->parent($record, 'folder'));
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'folders') {
            $documents = $this->linked('documents', 'folder', $record)->orderBy('title')->get();

            return [['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'In this folder', 'icon' => 'folder-open', 'empty' => 'No documents yet.',
                'rows' => $documents->map(fn (Record $document) => [
                    'label' => $document->title, 'sub' => ucfirst(str_replace('_', ' ', $document->status)), 'value' => (string) ($document->value('version') ?? ''), 'href' => $document->url(),
                ])->values()->all(),
            ]]];
        }

        if ($record->status === 'approved' && $record->due_on && $record->due_on->lte(today()->addDays(self::REVIEW_WARNING_DAYS))) {
            return [['view' => 'apps.logic.alert-card', 'data' => ['tone' => $record->due_on->lt(today()) ? 'danger' : 'warning', 'icon' => 'calendar-clock', 'title' => 'Review due',
                'body' => 'This document is due for review on '.$record->due_on->format('d M Y').'.']]];
        }

        return [];
    }

    public function homeCards(): array
    {
        $review = $this->records('documents')->where('status', 'approved')->whereDate('due_on', '<=', today()->addDays(self::REVIEW_WARNING_DAYS))->orderBy('due_on')->get();
        $inReview = $this->records('documents')->where('status', 'in_review')->orderBy('occurs_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Due for review', 'icon' => 'calendar-clock', 'empty' => 'No reviews due in the next two weeks.',
                'rows' => $review->map(fn (Record $document) => [
                    'label' => $document->title, 'sub' => $document->value('version'), 'value' => $document->due_on->format('d M Y'), 'href' => $document->url(),
                    'tone' => $document->due_on->lt(today()) ? 'danger' : 'warning',
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Waiting for approval', 'icon' => 'file-search', 'empty' => 'Nothing is in review.',
                'rows' => $inReview->map(fn (Record $document) => ['label' => $document->title, 'sub' => $document->value('version'), 'value' => $document->occurs_on?->format('d M') ?? '', 'href' => $document->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $documents = $this->records('documents')->get();
        $folders = $this->records('folders')->pluck('title', 'id');
        $byFolder = $documents->groupBy(fn (Record $document) => $folders[$document->value('folder')] ?? 'No folder')->sortKeys()->map(fn ($group, $folder) => [
            $folder, $group->count(), $group->where('status', 'approved')->count(), $group->where('status', 'in_review')->count(), $group->where('status', 'obsolete')->count(),
        ])->values()->all();

        $schedule = $documents->where('status', 'approved')->filter(fn (Record $document) => $document->due_on && $document->due_on->betweenIncluded($from, $to))->sortBy('due_on')
            ->map(fn (Record $document) => [$document->due_on->format('d M Y'), $document->title, (string) ($document->value('version') ?? '—'), $folders[$document->value('folder')] ?? '—'])->values()->all();

        return [
            ['title' => 'Documents by folder', 'columns' => ['Folder', 'Documents', 'Approved', 'In review', 'Obsolete'], 'rows' => $byFolder],
            ['title' => 'Review schedule', 'columns' => ['Review on', 'Document', 'Version', 'Folder'], 'rows' => $schedule],
        ];
    }
}
