<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Police occurrence book: every OB entry has one OB number and is never dated in the future. A case
 * docket is opened from an entry, which then shows the docket was opened, and follows the docket
 * from investigation to the prosecutor and court. Exhibits are booked against a docket, and a docket
 * is finalised only once every exhibit has been returned or disposed of.
 */
class PoliceLogic extends AppLogic
{
    public const OPEN = ['under_investigation', 'to_prosecutor', 'in_court'];

    public const HELD = ['booked_in', 'at_lab', 'in_court'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'occurrences') {
            $number = strtoupper(trim((string) ($data['ob_number'] ?? '')));
            if ($number !== '' && $this->duplicate('occurrences', 'ob_number', $number, $existing)) {
                $errors['data.ob_number'] = 'OB number '.$number.' is already in the book.';
            }
            if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
                $errors['occurs_on'] = 'An entry cannot be dated in the future.';
            }

            return $errors;
        }

        if ($entity->key === 'dockets') {
            $number = strtoupper(trim((string) ($data['docket_number'] ?? '')));
            if ($number !== '' && $this->duplicate('dockets', 'docket_number', $number, $existing)) {
                $errors['data.docket_number'] = 'Docket '.$number.' already exists.';
            }
            if ($payload['status'] === 'finalised' && $existing && ($held = $this->linked('exhibits', 'docket', $existing)->whereIn('status', self::HELD)->count()) > 0) {
                $errors['status'] = $held.' exhibits are still held.';
            }

            return $errors;
        }

        $docket = ! empty($data['docket']) ? $this->records('dockets')->find($data['docket']) : null;
        if ($docket && ! in_array($docket->status, self::OPEN, true) && (! $existing || (int) $existing->value('docket') !== $docket->id)) {
            $errors['data.docket'] = 'Docket '.$docket->title.' is closed.';
        }
        if ($payload['status'] === 'booked_in' && blank($data['storage_location'] ?? null)) {
            $errors['data.storage_location'] = 'Record where the exhibit is stored.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();

        if ($record->entity === 'occurrences') {
            $this->put($record, ['ob_number' => strtoupper(trim((string) $record->value('ob_number'))) ?: null]);

            return;
        }

        if ($record->entity === 'exhibits') {
            $this->put($record, [
                'exhibit_number' => strtoupper(trim((string) $record->value('exhibit_number'))) ?: null,
                '_released_on' => in_array($record->status, ['returned', 'disposed'], true) ? ($record->value('_released_on') ?? today()->toDateString()) : null,
            ]);

            return;
        }

        $exhibits = $record->exists ? $this->linked('exhibits', 'docket', $record)->get() : collect();
        $this->put($record, [
            'docket_number' => strtoupper(trim((string) $record->value('docket_number'))) ?: null,
            '_exhibits' => $exhibits->count(),
            '_exhibits_held' => $exhibits->whereIn('status', self::HELD)->count(),
            '_days_open' => in_array($record->status, self::OPEN, true) ? (int) $record->occurs_on->diffInDays(today()) : null,
            '_closed_on' => in_array($record->status, self::OPEN, true) ? null : ($record->value('_closed_on') ?? today()->toDateString()),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'dockets') {
            $occurrence = $this->parent($record, 'occurrence');
            if ($occurrence && $occurrence->status === 'recorded') {
                $occurrence->update(['status' => 'docket_opened']);
            }

            return;
        }

        if ($record->entity === 'exhibits') {
            $this->recalculate($this->parent($record, 'docket'));
            $this->recalculate($this->previousParent($record, 'docket'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'exhibits') {
            $this->recalculate($this->parent($record, 'docket'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'occurrences') {
            return $record->status === 'recorded'
                ? [
                    'open_docket' => ['label' => 'Open docket', 'icon' => 'folder-plus', 'fields' => [
                        ['name' => 'title', 'label' => 'Offence', 'type' => 'text'],
                        ['name' => 'docket_number', 'label' => 'Docket number', 'type' => 'text'],
                    ]],
                    'no_further_action' => ['label' => 'No further action', 'icon' => 'x'],
                ]
                : [];
        }

        if ($record->entity === 'exhibits') {
            return match ($record->status) {
                'booked_in' => ['send_to_lab' => ['label' => 'Send to lab', 'icon' => 'flask-conical'], 'to_court' => ['label' => 'To court', 'icon' => 'gavel'], 'return' => ['label' => 'Return to owner', 'icon' => 'undo'], 'dispose' => ['label' => 'Dispose', 'icon' => 'trash', 'confirm' => 'Dispose of this exhibit?']],
                'at_lab' => ['book_back' => ['label' => 'Back from lab', 'icon' => 'package'], 'to_court' => ['label' => 'To court', 'icon' => 'gavel']],
                'in_court' => ['book_back' => ['label' => 'Back from court', 'icon' => 'package'], 'return' => ['label' => 'Return to owner', 'icon' => 'undo'], 'dispose' => ['label' => 'Dispose', 'icon' => 'trash', 'confirm' => 'Dispose of this exhibit?']],
                default => [],
            };
        }

        $book = ['label' => 'Book exhibit', 'icon' => 'package-plus', 'fields' => [
            ['name' => 'title', 'label' => 'Description', 'type' => 'text'],
            ['name' => 'exhibit_number', 'label' => 'Exhibit number', 'type' => 'text'],
            ['name' => 'storage_location', 'label' => 'Storage location', 'type' => 'text', 'value' => 'SAP 13 store'],
        ]];

        return match ($record->status) {
            'under_investigation' => ['book_exhibit' => $book, 'to_prosecutor' => ['label' => 'Send to prosecutor', 'icon' => 'send'], 'close_undetected' => ['label' => 'Close undetected', 'icon' => 'folder-x', 'confirm' => 'Close '.$record->title.' as undetected?']],
            'to_prosecutor' => ['book_exhibit' => $book, 'enrol' => ['label' => 'Enrolled in court', 'icon' => 'gavel'], 'return_for_investigation' => ['label' => 'Further investigation', 'icon' => 'undo']],
            'in_court' => ['finalise' => ['label' => 'Finalise', 'icon' => 'check']],
            default => ['reopen' => ['label' => 'Reopen', 'icon' => 'folder-open']],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'open_docket':
                $input = $request->validate(['title' => ['required', 'string'], 'docket_number' => ['required', 'string']]);
                $number = strtoupper(trim($input['docket_number']));
                if ($this->duplicate('dockets', 'docket_number', $number, null)) {
                    throw ValidationException::withMessages(['docket_number' => 'Docket '.$number.' already exists.']);
                }
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'dockets', 'title' => $input['title'], 'status' => 'under_investigation',
                    'occurs_on' => today(), 'assignee_id' => $record->assignee_id, 'data' => ['occurrence' => $record->id, 'docket_number' => $number, 'complainant' => $record->value('reported_by')],
                ]);

                return 'Docket '.$number.' opened from '.$record->value('ob_number').'.';
            case 'no_further_action':
                $record->update(['status' => 'no_further_action']);

                return $record->value('ob_number').' marked no further action.';
            case 'book_exhibit':
                $input = $request->validate(['title' => ['required', 'string'], 'exhibit_number' => ['nullable', 'string'], 'storage_location' => ['required', 'string']]);
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'exhibits', 'title' => $input['title'], 'status' => 'booked_in',
                    'occurs_on' => today(), 'assignee_id' => $record->assignee_id, 'data' => ['docket' => $record->id, 'exhibit_number' => $input['exhibit_number'] ?? null, 'storage_location' => $input['storage_location']],
                ]);

                return $input['title'].' booked in to '.$input['storage_location'].'.';
            case 'to_prosecutor':
                $record->update(['status' => 'to_prosecutor']);

                return 'Docket '.$record->value('docket_number').' sent to the prosecutor.';
            case 'return_for_investigation':
            case 'reopen':
                $record->update(['status' => 'under_investigation']);

                return 'Docket '.$record->value('docket_number').' is under investigation again.';
            case 'enrol':
                $record->update(['status' => 'in_court']);

                return 'Docket '.$record->value('docket_number').' enrolled in court.';
            case 'close_undetected':
                $record->update(['status' => 'closed_undetected']);

                return 'Docket '.$record->value('docket_number').' closed undetected.';
            case 'finalise':
                $held = $this->linked('exhibits', 'docket', $record)->whereIn('status', self::HELD)->count();
                if ($held > 0) {
                    throw ValidationException::withMessages(['status' => $held.' exhibits are still held.']);
                }
                $record->update(['status' => 'finalised']);

                return 'Docket '.$record->value('docket_number').' finalised.';
        }

        $status = match ($action) {
            'send_to_lab' => 'at_lab',
            'to_court' => 'in_court',
            'book_back' => 'booked_in',
            'return' => 'returned',
            default => 'disposed',
        };
        $record->update(['status' => $status]);

        return $record->title.' '.match ($status) {
            'at_lab' => 'sent to the lab.',
            'in_court' => 'taken to court.',
            'booked_in' => 'booked back in.',
            'returned' => 'returned to its owner.',
            default => 'disposed of.',
        };
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'dockets') {
            return [];
        }

        $exhibits = $this->linked('exhibits', 'docket', $record)->latest('id')->get();
        $occurrence = $this->parent($record, 'occurrence');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Docket', 'icon' => 'folder-open', 'stats' => [
                ['label' => 'Docket number', 'value' => $record->value('docket_number') ?: '—'],
                ['label' => 'OB entry', 'value' => $occurrence?->value('ob_number') ?: '—'],
                ['label' => 'Complainant', 'value' => (string) ($record->value('complainant') ?: '—')],
                ['label' => 'Days open', 'value' => $record->value('_days_open') === null ? 'Closed' : (string) (int) $record->value('_days_open')],
                ['label' => 'Exhibits held', 'value' => (int) $record->value('_exhibits_held').' of '.(int) $record->value('_exhibits')],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Exhibits', 'icon' => 'package', 'empty' => 'No exhibits booked.',
                'rows' => $exhibits->take(15)->map(fn (Record $exhibit) => [
                    'label' => $exhibit->title, 'sub' => trim(($exhibit->value('exhibit_number') ?: '').' · '.($exhibit->value('storage_location') ?: ''), ' ·'), 'value' => ucfirst(str_replace('_', ' ', $exhibit->status)), 'href' => $exhibit->url(), 'tone' => in_array($exhibit->status, self::HELD, true) ? null : 'success',
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $occurrences = $this->records('occurrences')->get();
        $dockets = $this->records('dockets')->get();
        $open = $dockets->whereIn('status', self::OPEN);
        $oldest = $open->sortByDesc(fn (Record $docket) => (int) $docket->value('_days_open'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Station', 'icon' => 'siren', 'stats' => [
                ['label' => 'OB entries today', 'value' => (string) $occurrences->filter(fn (Record $entry) => $entry->occurs_on?->isToday())->count()],
                ['label' => 'Awaiting a decision', 'value' => (string) $occurrences->where('status', 'recorded')->count()],
                ['label' => 'Open dockets', 'value' => (string) $open->count()],
                ['label' => 'With the prosecutor', 'value' => (string) $open->where('status', 'to_prosecutor')->count()],
                ['label' => 'In court', 'value' => (string) $open->where('status', 'in_court')->count()],
                ['label' => 'Exhibits held', 'value' => (string) $this->records('exhibits')->whereIn('status', self::HELD)->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Oldest open dockets', 'icon' => 'folder-clock', 'empty' => 'No open dockets.',
                'rows' => $oldest->take(10)->map(fn (Record $docket) => [
                    'label' => $docket->title, 'sub' => ($docket->value('docket_number') ?: '').' · '.ucfirst(str_replace('_', ' ', $docket->status)), 'value' => (int) $docket->value('_days_open').' days', 'href' => $docket->url(), 'tone' => (int) $docket->value('_days_open') > 90 ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $occurrences = $this->dated('occurrences', $from, $to)->get();
        $dockets = $this->dated('dockets', $from, $to)->get();
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($occurrences, $dockets) {
            $entries = $occurrences->filter(fn (Record $entry) => $entry->occurs_on?->format('Y-m') === $month);
            $opened = $dockets->filter(fn (Record $docket) => $docket->occurs_on?->format('Y-m') === $month);

            return [$label, $entries->count(), $opened->count(), $opened->where('status', 'finalised')->count(), $opened->where('status', 'closed_undetected')->count()];
        })->values()->all();

        $statuses = ['under_investigation' => 'Under investigation', 'to_prosecutor' => 'With the prosecutor', 'in_court' => 'In court', 'closed_undetected' => 'Closed undetected', 'finalised' => 'Finalised'];
        $all = $this->records('dockets')->get();
        $byStatus = collect($statuses)->map(fn (string $label, string $status) => [$label, $all->where('status', $status)->count()])->values()->all();

        $exhibits = $this->records('exhibits')->get();
        $byStore = $exhibits->whereIn('status', self::HELD)->groupBy(fn (Record $exhibit) => $exhibit->value('storage_location') ?: 'Unknown')->sortKeys()->map(fn ($group, $store) => [$store, $group->count(), $group->where('status', 'at_lab')->count()])->values()->all();

        return [
            ['title' => 'Occurrences by month', 'columns' => ['Month', 'OB entries', 'Dockets opened', 'Finalised', 'Undetected'], 'rows' => $byMonth],
            ['title' => 'Dockets by status', 'columns' => ['Status', 'Dockets'], 'rows' => $byStatus],
            ['title' => 'Exhibits held by store', 'columns' => ['Store', 'Held', 'At the lab'], 'rows' => $byStore],
        ];
    }

    private function duplicate(string $entity, string $field, string $number, ?Record $existing): bool
    {
        return $this->records($entity)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
            ->contains(fn (Record $record) => strtoupper(trim((string) $record->value($field))) === $number);
    }
}
