<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Health & safety: an incident can't be dated in the future, an injury names the injured person, and an
 * incident closes only once the corrective action is written down. The home page counts the days since
 * the last lost-time injury. PPE is issued in at least one, and replacing an item closes the old issue
 * and opens a new one with the same replacement interval. Toolbox talks are marked as held.
 */
class HealthSafetyLogic extends AppLogic
{
    /**
     * Severities that count as a lost-time injury.
     *
     * @var list<string>
     */
    public const LOST_TIME = ['lost_time', 'serious', 'fatal'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'incidents') {
            if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
                $errors['occurs_on'] = 'An incident cannot be reported before it happens.';
            }
            if (($data['type'] ?? null) === 'injury' && blank($data['injured_person'] ?? null)) {
                $errors['data.injured_person'] = 'Name the injured person.';
            }
            if ($payload['status'] === 'closed' && blank($data['corrective_action'] ?? null)) {
                $errors['data.corrective_action'] = 'Write down the corrective action before closing the incident.';
            }
        }
        if ($entity->key === 'ppe') {
            if (array_key_exists('quantity', $data) && (float) $data['quantity'] < 1) {
                $errors['data.quantity'] = 'Issue at least one.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lte(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The replacement date must be after the issue date.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'ppe' && (float) $record->value('quantity') < 1) {
            $this->put($record, ['quantity' => 1]);
        }
        if ($record->entity === 'incidents' && $record->isDirty('status') && $record->status === 'closed') {
            $this->put($record, ['_days_to_close' => (int) $record->occurs_on->diffInDays(today())]);
        }
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'incidents' && $record->status === 'reported' => ['investigate' => ['label' => 'Investigate', 'icon' => 'search'], 'close' => $this->closeAction($record)],
            $record->entity === 'incidents' && $record->status === 'investigating' => ['close' => $this->closeAction($record)],
            $record->entity === 'ppe' && $record->status === 'issued' => ['replace' => ['label' => 'Replace', 'icon' => 'refresh-cw'], 'return' => ['label' => 'Returned', 'icon' => 'undo-2']],
            $record->entity === 'talks' && $record->status === 'planned' => ['held' => ['label' => 'Held', 'icon' => 'check', 'fields' => [['name' => 'attendees', 'label' => 'Who attended', 'type' => 'textarea', 'value' => $record->value('attendees')]]]],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function closeAction(Record $record): array
    {
        return ['label' => 'Close', 'icon' => 'check', 'fields' => [['name' => 'corrective_action', 'label' => 'Corrective action', 'type' => 'textarea', 'value' => $record->value('corrective_action')]]];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'investigate':
                $record->update(['status' => 'investigating']);

                return $record->title.' is under investigation.';
            case 'close':
                $taken = trim((string) ($request->validate(['corrective_action' => ['nullable', 'string', 'max:2000']])['corrective_action'] ?? $record->value('corrective_action')));
                if ($taken === '') {
                    throw ValidationException::withMessages(['corrective_action' => 'Write down the corrective action before closing the incident.']);
                }
                $record->update(['status' => 'closed', 'data' => [...$record->data, 'corrective_action' => $taken]]);

                return $record->title.' closed after '.$record->value('_days_to_close').' '.str('day')->plural((int) $record->value('_days_to_close')).'.';
            case 'return':
                $record->update(['status' => 'returned']);

                return $record->title.' returned.';
            case 'held':
                $attendees = trim((string) ($request->validate(['attendees' => ['nullable', 'string', 'max:5000']])['attendees'] ?? ''));
                $record->update(['status' => 'held', 'occurs_on' => $record->occurs_on && $record->occurs_on->lte(today()) ? $record->occurs_on : today(), 'data' => [...$record->data, 'attendees' => $attendees ?: $record->value('attendees')]]);
                $count = collect(preg_split('/[\n,]+/', (string) $record->value('attendees')))->map(fn (string $name) => trim($name))->filter()->count();

                return $record->title.' held with '.$count.' '.str('attendee')->plural($count).'.';
            default:
                $interval = $record->occurs_on && $record->due_on ? (int) $record->occurs_on->diffInDays($record->due_on) : null;
                $record->update(['status' => 'replaced']);
                $new = Record::create([
                    'workspace_id' => $record->workspace_id, 'branch_id' => $record->branch_id, 'blueprint' => $record->blueprint, 'entity' => 'ppe',
                    'title' => $record->title, 'status' => 'issued', 'occurs_on' => today(), 'due_on' => $interval ? today()->addDays($interval) : null,
                    'data' => ['employee' => $record->value('employee'), 'size' => $record->value('size'), 'quantity' => $record->value('quantity'), '_replaces' => $record->id],
                    'created_by' => $request->user()->id,
                ]);

                return $record->title.' replaced'.($new->due_on ? '; next replacement by '.$new->due_on->format('d M Y') : '').'.';
        }
    }

    /**
     * The most recent lost-time injury.
     */
    protected function lastLostTime(): ?Record
    {
        return $this->records('incidents')->where('data->type', 'injury')->whereIn('data->severity', self::LOST_TIME)->latest('occurs_on')->first();
    }

    public function homeCards(): array
    {
        $last = $this->lastLostTime();
        $open = $this->records('incidents')->where('status', '!=', 'closed')->get();
        $ppe = $this->records('ppe')->where('status', 'issued')->whereNotNull('due_on')->whereDate('due_on', '<=', today()->addDays(14)->toDateString())->orderBy('due_on')->get();
        $names = User::query()->whereIn('id', $ppe->map(fn (Record $issue) => $issue->value('employee'))->filter()->unique())->pluck('name', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Safety', 'icon' => 'hard-hat', 'stats' => [
                ['label' => 'Days without a lost-time injury', 'value' => $last ? (int) $last->occurs_on->diffInDays(today()) : 'No lost-time injuries', 'tone' => $last && $last->occurs_on->diffInDays(today()) < 30 ? 'danger' : 'success'],
                ['label' => 'Open incidents', 'value' => $open->count(), 'tone' => $open->isNotEmpty() ? 'warning' : null],
                ['label' => 'Near misses this year', 'value' => $this->records('incidents')->where('data->type', 'near_miss')->whereYear('occurs_on', today()->year)->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'PPE due for replacement', 'icon' => 'hard-hat', 'empty' => 'No PPE due in the next two weeks.',
                'rows' => $ppe->map(fn (Record $issue) => ['label' => $issue->title, 'sub' => $names[$issue->value('employee')] ?? '—', 'value' => $issue->due_on->format('d M'), 'href' => $issue->url(), 'tone' => $issue->due_on->lt(today()) ? 'danger' : 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $incidents = $this->dated('incidents', $from, $to)->get();
        $ppe = $this->dated('ppe', $from, $to)->get();

        return [
            ['title' => 'Incidents by type', 'columns' => ['Type', 'Reported', 'Lost-time', 'Still open', 'Average days to close'], 'rows' => $incidents
                ->groupBy(fn (Record $incident) => ucfirst(str_replace('_', ' ', (string) $incident->value('type'))))->sortKeys()
                ->map(function ($group, string $type) {
                    $closed = $group->where('status', 'closed');

                    return [$type, $group->count(), $group->filter(fn (Record $incident) => in_array($incident->value('severity'), self::LOST_TIME, true))->count(),
                        $group->where('status', '!=', 'closed')->count(), $closed->isNotEmpty() ? number_format($closed->avg(fn (Record $incident) => (int) $incident->value('_days_to_close')), 1) : '—'];
                })->values()->all()],
            ['title' => 'PPE issued', 'columns' => ['Item', 'Issues', 'Quantity', 'Replaced'], 'rows' => $ppe
                ->groupBy(fn (Record $issue) => trim((string) $issue->title))->sortKeys()
                ->map(fn ($group, string $item) => [$item, $group->count(), $group->sum(fn (Record $issue) => (int) $issue->value('quantity')), $group->where('status', 'replaced')->count()])
                ->values()->all()],
        ];
    }
}
