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
 * Facility management & cleaning schedules: checks are logged only for open areas and never for a future
 * date, and a check that found a problem says what it was. Each area remembers when it was last cleaned,
 * and it is overdue once its frequency has passed with no clean. An issue is closed by writing down the fix.
 * The report shows each area's checks and how many were done.
 */
class CleaningScheduleLogic extends AppLogic
{
    /**
     * Days an area may go without a clean, by frequency.
     *
     * @var array<string, int>
     */
    public const ALLOWED_GAP = ['hourly' => 0, 'daily' => 1, 'weekly' => 7, 'monthly' => 31];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key !== 'checks') {
            return $errors;
        }
        $area = filled($data['area'] ?? null) ? $this->records('areas')->find($data['area']) : null;
        if ($area && ! $existing && $area->status !== 'active') {
            $errors['data.area'] = $area->title.' is closed.';
        }
        if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->isFuture()) {
            $errors['occurs_on'] = 'A check cannot be logged for a future date.';
        }
        if ($payload['status'] === 'issue' && blank($data['issues'] ?? null)) {
            $errors['data.issues'] = 'Say what the issue is.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'checks') {
            $record->occurs_on ??= today();
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'checks') {
            $this->stamp($this->parent($record, 'area'));
            $this->stamp($this->previousParent($record, 'area'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'checks') {
            $this->stamp($this->parent($record, 'area'));
        }
    }

    /**
     * Store the date of the area's latest clean.
     */
    protected function stamp(?Record $area): void
    {
        if (! $area) {
            return;
        }
        $last = $this->linked('checks', 'area', $area)->whereIn('status', ['done', 'issue'])->max('occurs_on');
        $last = $last ? Carbon::parse($last)->toDateString() : null;
        if ($area->value('_last_cleaned') !== $last) {
            $area->update(['data' => [...$area->data, '_last_cleaned' => $last]]);
        }
    }

    /**
     * Whether an open area has gone longer than its frequency allows without a clean.
     */
    public function isOverdue(Record $area): bool
    {
        if ($area->status !== 'active') {
            return false;
        }
        $last = $area->value('_last_cleaned');

        return ! $last || Carbon::parse($last)->diffInDays(today()) > (self::ALLOWED_GAP[$area->value('frequency')] ?? 1);
    }

    public function actions(Record $record): array
    {
        return $record->entity === 'checks' && $record->status === 'issue' ? ['fixed' => ['label' => 'Issue fixed', 'icon' => 'check', 'fields' => [['name' => 'fix', 'label' => 'What was done', 'type' => 'textarea']]]] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $fix = trim((string) ($request->validate(['fix' => ['nullable', 'string', 'max:2000']])['fix'] ?? ''));
        if ($fix === '') {
            throw ValidationException::withMessages(['fix' => 'Say what was done to fix it.']);
        }
        $record->update(['status' => 'done', 'data' => [...$record->data, 'issues' => trim($record->value('issues')."\nFixed: ".$fix)]]);

        return 'Issue at '.($this->parent($record, 'area')?->title ?? $record->title).' fixed.';
    }

    public function homeCards(): array
    {
        $overdue = $this->records('areas')->where('status', 'active')->orderBy('title')->get()->filter(fn (Record $area) => $this->isOverdue($area));
        $issues = $this->records('checks')->where('status', 'issue')->count();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Cleaning overdue'.($issues ? ' · '.$issues.' open '.str('issue')->plural($issues) : ''), 'icon' => 'spray-can', 'empty' => 'Every area is clean on schedule.',
            'rows' => $overdue->map(fn (Record $area) => ['label' => $area->title, 'sub' => ucfirst((string) $area->value('frequency')), 'value' => $area->value('_last_cleaned') ? 'last '.Carbon::parse($area->value('_last_cleaned'))->format('d M') : 'never cleaned', 'href' => $area->url(), 'tone' => 'warning'])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $checks = $this->dated('checks', $from, $to)->get();
        $areas = $this->records('areas')->pluck('title', 'id');
        $cleaners = User::query()->whereIn('id', $checks->map(fn (Record $check) => $check->value('cleaner'))->filter()->unique())->pluck('name', 'id');

        return [
            ['title' => 'Checks by area', 'columns' => ['Area', 'Checks', 'Done', 'Missed', 'Issues', 'Done rate'], 'rows' => $checks
                ->groupBy(fn (Record $check) => (string) ($areas[(int) $check->value('area')] ?? 'Unknown'))->sortKeys()
                ->map(fn ($group, string $area) => [$area, $group->count(), $group->where('status', 'done')->count(), $group->where('status', 'missed')->count(), $group->where('status', 'issue')->count(),
                    round($group->where('status', '!=', 'missed')->count() / $group->count() * 100).'%'])
                ->values()->all()],
            ['title' => 'Checks by cleaner', 'columns' => ['Cleaner', 'Checks', 'Missed'], 'rows' => $checks
                ->groupBy(fn (Record $check) => (string) ($cleaners[(int) $check->value('cleaner')] ?? 'Unknown'))->sortKeys()
                ->map(fn ($group, string $cleaner) => [$cleaner, $group->count(), $group->where('status', 'missed')->count()])
                ->values()->all()],
        ];
    }
}
