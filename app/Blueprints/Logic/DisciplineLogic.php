<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Discipline: merits and commendations earn points, demerits, detentions and suspensions cost
 * them, and each entry keeps the student's running total. Serious entries need the parent on
 * record and cannot be marked as parent informed without one, nothing is logged for a future
 * date, and students whose total drops below the at-risk line are flagged on the home page.
 */
class DisciplineLogic extends AppLogic
{
    public const POINTS = ['merit' => 1, 'commendation' => 3, 'demerit' => -1, 'detention' => -3, 'suspension' => -10];

    public const SERIOUS = ['detention', 'suspension'];

    public const AT_RISK = -5;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $type = $data['type'] ?? null;
        $errors = [];

        if (filled($data['points'] ?? null) && $type && isset(self::POINTS[$type])) {
            $points = (int) $data['points'];
            if (self::POINTS[$type] > 0 && $points < 0) {
                $errors['data.points'] = 'A '.$type.' cannot take points away.';
            } elseif (self::POINTS[$type] < 0 && $points > 0) {
                $errors['data.points'] = 'A '.$type.' cannot award points; enter a negative number or leave it blank.';
            }
        }
        if (in_array($type, self::SERIOUS, true) && blank($payload['contact_id'] ?? null)) {
            $errors['contact_id'] = 'A '.$type.' needs the parent on record.';
        }
        if ($payload['status'] === 'parent_informed' && blank($payload['contact_id'] ?? null)) {
            $errors['status'] = 'Add the parent before marking them informed.';
        }
        if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
            $errors['occurs_on'] = 'Behaviour is logged on or after the day it happened, not before.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        $type = (string) $record->value('type');
        $points = filled($record->value('points')) ? (int) $record->value('points') : (self::POINTS[$type] ?? 0);
        $others = $this->studentEntries($record->title)->when($record->exists, fn ($query) => $query->whereKeyNot($record->id))->get()->sum(fn (Record $entry) => (int) $entry->value('_points'));
        $this->put($record, [
            'points' => $points,
            '_points' => $points,
            '_student' => $this->key($record->title),
            '_running_total' => (int) $others + $points,
            '_informed_on' => in_array($record->status, ['parent_informed', 'resolved'], true) ? ($record->value('_informed_on') ?? today()->toDateString()) : null,
            '_resolved_on' => $record->status === 'resolved' ? ($record->value('_resolved_on') ?? today()->toDateString()) : null,
        ]);
    }

    protected function key(string $student): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $student)));
    }

    protected function studentEntries(string $student)
    {
        return $this->records('entries')->where('data->_student', $this->key($student));
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'recorded' => [
                'inform_parent' => ['label' => 'Parent informed', 'icon' => 'phone', 'fields' => [
                    ['name' => 'note', 'label' => 'How were they told?', 'type' => 'text'],
                ]],
                'resolve' => ['label' => 'Resolve', 'icon' => 'check'],
            ],
            'parent_informed' => ['resolve' => ['label' => 'Resolve', 'icon' => 'check']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'inform_parent') {
            if (! $record->contact_id) {
                throw ValidationException::withMessages(['contact_id' => 'Add the parent before marking them informed.']);
            }
            $note = $request->validate(['note' => ['nullable', 'string']])['note'] ?? null;
            $record->update(['status' => 'parent_informed', 'data' => [...$record->data, '_informed_on' => today()->toDateString(), '_informed_note' => $note]]);

            return ($record->contact?->name ?? 'The parent').' was informed about '.$record->title.'.';
        }

        $record->update(['status' => 'resolved']);

        return 'Entry for '.$record->title.' resolved.';
    }

    public function recordCards(Record $record): array
    {
        $entries = $this->studentEntries($record->title)->orderByDesc('occurs_on')->orderByDesc('id')->get();
        $total = (int) $entries->sum(fn (Record $entry) => (int) $entry->value('_points'));
        $types = $this->app->entities['entries']->field('type')?->options ?? [];
        $open = $entries->where('status', '!=', 'resolved')->count();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Student record', 'icon' => 'shield-alert', 'stats' => [
                ['label' => 'Net points', 'value' => ($total > 0 ? '+' : '').$total, 'tone' => $total <= self::AT_RISK ? 'danger' : ($total > 0 ? 'success' : null)],
                ['label' => 'Entries', 'value' => (string) $entries->count()],
                ['label' => 'Merits', 'value' => (string) $entries->whereIn('data.type', ['merit', 'commendation'])->count()],
                ['label' => 'Demerits', 'value' => (string) $entries->whereIn('data.type', ['demerit', 'detention', 'suspension'])->count()],
                ['label' => 'Suspensions', 'value' => (string) $entries->where('data.type', 'suspension')->count(), 'tone' => $entries->where('data.type', 'suspension')->isNotEmpty() ? 'danger' : null],
                ['label' => 'Open', 'value' => (string) $open, 'tone' => $open > 0 ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'History', 'icon' => 'history', 'empty' => 'This is the first entry.',
                'rows' => $entries->take(10)->map(fn (Record $entry) => [
                    'label' => ($types[$entry->value('type')] ?? ucfirst((string) $entry->value('type'))).': '.str($entry->value('description'))->limit(60), 'sub' => $entry->occurs_on?->format('d M Y').' · '.ucfirst(str_replace('_', ' ', $entry->status)), 'value' => ((int) $entry->value('_points') > 0 ? '+' : '').(int) $entry->value('_points'), 'href' => $entry->url(),
                    'tone' => (int) $entry->value('_points') < 0 ? 'danger' : ((int) $entry->value('_points') > 0 ? 'success' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $entries = $this->records('entries')->get();
        $month = $entries->filter(fn (Record $entry) => $entry->occurs_on?->isCurrentMonth());
        $open = $entries->where('status', '!=', 'resolved');
        $uninformed = $open->where('status', 'recorded')->filter(fn (Record $entry) => in_array($entry->value('type'), self::SERIOUS, true));
        $students = $entries->groupBy(fn (Record $entry) => $entry->value('_student'))->map(fn ($group) => ['title' => $group->first()->title, 'class' => $group->first()->value('class_name'), 'total' => (int) $group->sum(fn (Record $entry) => (int) $entry->value('_points')), 'entries' => $group->count(), 'last' => $group->sortByDesc('occurs_on')->first()]);
        $atRisk = $students->filter(fn (array $student) => $student['total'] <= self::AT_RISK)->sortBy('total');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Behaviour', 'icon' => 'shield-alert', 'stats' => [
                ['label' => 'Entries this month', 'value' => (string) $month->count()],
                ['label' => 'Merits', 'value' => (string) $month->whereIn('data.type', ['merit', 'commendation'])->count(), 'tone' => 'success'],
                ['label' => 'Demerits', 'value' => (string) $month->whereIn('data.type', ['demerit', 'detention', 'suspension'])->count()],
                ['label' => 'Suspensions', 'value' => (string) $month->where('data.type', 'suspension')->count(), 'tone' => $month->where('data.type', 'suspension')->isNotEmpty() ? 'danger' : null],
                ['label' => 'Parents to inform', 'value' => (string) $uninformed->count(), 'tone' => $uninformed->isNotEmpty() ? 'warning' : null],
                ['label' => 'Students at risk', 'value' => (string) $atRisk->count(), 'tone' => $atRisk->isNotEmpty() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Students at risk', 'icon' => 'alert-triangle', 'empty' => 'No student is below '.self::AT_RISK.' points.',
                'rows' => $atRisk->take(10)->map(fn (array $student) => [
                    'label' => $student['title'], 'sub' => ($student['class'] ?: 'No class').' · '.$student['entries'].' entries', 'value' => (string) $student['total'], 'href' => $student['last']->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $entries = $this->dated('entries', $from, $to)->get();
        $types = $this->app->entities['entries']->field('type')?->options ?? [];
        $byType = collect($types)->map(fn (string $label, string $type) => [$label, $entries->where('data.type', $type)->count(), (int) $entries->where('data.type', $type)->sum(fn (Record $entry) => (int) $entry->value('_points')), $entries->where('data.type', $type)->where('status', 'resolved')->count()])->values()->all();

        $byClass = $entries->groupBy(fn (Record $entry) => $entry->value('class_name') ?: 'No class')->sortKeys()->map(fn ($group, $class) => [
            $class, $group->count(), $group->whereIn('data.type', ['merit', 'commendation'])->count(), $group->whereIn('data.type', ['demerit', 'detention', 'suspension'])->count(), (int) $group->sum(fn (Record $entry) => (int) $entry->value('_points')),
        ])->values()->all();

        $byStudent = $entries->groupBy(fn (Record $entry) => $entry->value('_student'))->map(fn ($group) => [
            $group->first()->title, $group->first()->value('class_name') ?: '—', $group->count(), $group->whereIn('data.type', ['merit', 'commendation'])->count(), $group->whereIn('data.type', ['demerit', 'detention', 'suspension'])->count(), (int) $group->sum(fn (Record $entry) => (int) $entry->value('_points')),
        ])->sortBy(fn (array $row) => $row[5])->values()->all();

        return [
            ['title' => 'Entries by type', 'columns' => ['Type', 'Entries', 'Points', 'Resolved'], 'rows' => $byType],
            ['title' => 'Entries by class', 'columns' => ['Class', 'Entries', 'Merits', 'Demerits', 'Net points'], 'rows' => $byClass],
            ['title' => 'Students by net points', 'columns' => ['Student', 'Class', 'Entries', 'Merits', 'Demerits', 'Net points'], 'rows' => $byStudent],
        ];
    }
}
