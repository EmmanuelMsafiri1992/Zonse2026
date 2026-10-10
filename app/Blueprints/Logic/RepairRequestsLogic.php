<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Maintenance requests: the fix-by date follows the urgency (urgent next day, normal within a week, low
 * within two), a request is assigned only to a contractor or a staff member, and finishing it records
 * how many days the fix took. The home page lists requests past their fix-by date and the report shows
 * repair speed and cost by category.
 */
class RepairRequestsLogic extends AppLogic
{
    /**
     * Days to fix, by urgency.
     *
     * @var array<string, int>
     */
    public const FIX_WITHIN = ['urgent' => 1, 'normal' => 7, 'low' => 14];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $errors = [];
        if (in_array($payload['status'], ['assigned', 'in_progress'], true) && blank($payload['data']['contractor'] ?? null) && blank($payload['assignee_id'] ?? null)) {
            $errors['data.contractor'] = 'Name the contractor or pick who is fixing it.';
        }
        if ((float) ($payload['amount'] ?? 0) < 0) {
            $errors['amount'] = 'The cost cannot be negative.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        $record->due_on ??= $record->occurs_on->copy()->addDays(self::FIX_WITHIN[$record->value('urgency') ?: 'normal'] ?? 7);
        $this->put($record, ['_days_to_fix' => $record->status === 'done' ? ($record->value('_days_to_fix') ?? (int) $record->occurs_on->diffInDays(today())) : null]);
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'reported' => ['assign' => ['label' => 'Assign', 'icon' => 'user-plus', 'fields' => [['name' => 'contractor', 'label' => 'Contractor', 'type' => 'text', 'value' => $record->value('contractor')]]], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'assigned' => ['start' => ['label' => 'Start work', 'icon' => 'play'], 'done' => $this->doneAction($record)],
            'in_progress' => ['done' => $this->doneAction($record)],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function doneAction(Record $record): array
    {
        return ['label' => 'Fixed', 'icon' => 'check', 'fields' => [['name' => 'amount', 'label' => 'Cost', 'type' => 'number', 'value' => $record->amount]]];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'assign':
                $contractor = trim((string) ($request->validate(['contractor' => ['nullable', 'string', 'max:255']])['contractor'] ?? ''));
                if ($contractor === '' && ! $record->assignee_id) {
                    throw ValidationException::withMessages(['contractor' => 'Name the contractor or pick who is fixing it.']);
                }
                $record->update(['status' => 'assigned', 'data' => [...$record->data, 'contractor' => $contractor ?: $record->value('contractor')]]);

                return $record->title.' assigned'.($contractor ? ' to '.$contractor : '').'; fix by '.$record->due_on->format('d M').'.';
            case 'start':
                $record->update(['status' => 'in_progress']);

                return 'Work started on '.$record->title.'.';
            case 'done':
                $cost = $request->validate(['amount' => ['nullable', 'numeric', 'min:0']])['amount'] ?? $record->amount;
                $record->update(['status' => 'done', 'amount' => $cost]);
                $days = (int) $record->value('_days_to_fix');

                return $record->title.' fixed in '.$days.' '.str('day')->plural($days).($record->due_on && today()->gt($record->due_on) ? ', after the fix-by date' : '').'.';
            default:
                $record->update(['status' => 'cancelled']);

                return $record->title.' cancelled.';
        }
    }

    public function homeCards(): array
    {
        $late = $this->records('requests')->whereIn('status', ['reported', 'assigned', 'in_progress'])->whereNotNull('due_on')->whereDate('due_on', '<', today()->toDateString())->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Past fix-by date ('.$late->count().')', 'icon' => 'hammer', 'empty' => 'Every open request is on time.',
            'rows' => $late->map(fn (Record $request) => ['label' => $request->title, 'sub' => collect([$request->value('location'), $request->value('contractor')])->filter()->implode(' · '), 'value' => (int) $request->due_on->diffInDays(today()).' days late', 'href' => $request->url(), 'tone' => $request->value('urgency') === 'urgent' ? 'danger' : 'warning'])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $requests = $this->dated('requests', $from, $to)->get();

        return [['title' => 'Repairs by category', 'columns' => ['Category', 'Reported', 'Fixed', 'Still open', 'Average days to fix', 'Cost'], 'rows' => $requests
            ->groupBy(fn (Record $request) => ucfirst((string) ($request->value('category') ?: 'other')))->sortKeys()
            ->map(function ($group, string $category) {
                $done = $group->where('status', 'done');

                return [$category, $group->count(), $done->count(), $group->whereIn('status', ['reported', 'assigned', 'in_progress'])->count(),
                    $done->isNotEmpty() ? number_format($done->avg(fn (Record $request) => (int) $request->value('_days_to_fix')), 1) : '—', $this->money($group->sum(fn (Record $request) => (float) $request->amount))];
            })->values()->all()]];
    }
}
