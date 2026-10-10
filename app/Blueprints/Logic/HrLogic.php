<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Employees (HR): employee numbers and ID numbers are unique however they are typed. Signing a contract
 * carries its salary and employment type onto the employee and closes the contract it replaces; a
 * fixed-term contract needs an end date. HR documents and signed contracts expire on their dates each
 * morning, and the home page lists what runs out within 30 days.
 */
class HrLogic extends AppLogic
{
    /**
     * Days ahead that contracts and documents are flagged as running out.
     */
    public const WARNING_DAYS = 30;

    /**
     * The employee's employment type for each contract type.
     *
     * @var array<string, string>
     */
    public const EMPLOYMENT_TYPES = ['permanent' => 'permanent', 'fixed_term' => 'fixed_term', 'casual' => 'casual', 'internship' => 'intern'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ((float) ($payload['amount'] ?? 0) < 0) {
            $errors['amount'] = 'The salary cannot be negative.';
        }
        if ($entity->key === 'employees') {
            foreach (['employee_number' => 'Employee number', 'id_number' => 'ID number'] as $field => $label) {
                $value = $this->normalise((string) ($data[$field] ?? ''));
                if ($value === '') {
                    continue;
                }
                $twin = $this->records('employees')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                    ->first(fn (Record $employee) => $this->normalise((string) $employee->value($field)) === $value);
                if ($twin) {
                    $errors['data.'.$field] = $label.' '.$data[$field].' belongs to '.$twin->title.'.';
                }
            }
        }
        if ($entity->key === 'contracts') {
            if (($data['type'] ?? null) === 'fixed_term' && blank($payload['due_on'] ?? null)) {
                $errors['due_on'] = 'A fixed-term contract needs an end date.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The contract cannot end before it starts.';
            }
            $employee = filled($data['employee'] ?? null) ? $this->records('employees')->find($data['employee']) : null;
            if ($employee?->status === 'exited' && in_array($payload['status'], ['draft', 'signed'], true)) {
                $errors['data.employee'] = $employee->title.' has left the company.';
            }
        }

        return $errors;
    }

    /**
     * A number with spaces and dashes removed, upper-cased, for comparing.
     */
    protected function normalise(string $value): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($value));
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'employees') {
            if (filled($record->value('employee_number'))) {
                $this->put($record, ['employee_number' => mb_strtoupper(trim((string) $record->value('employee_number')))]);
            }
            if ($record->isDirty('status') && $record->status === 'exited') {
                $this->put($record, ['_exited_on' => today()->toDateString()]);
            }

            return;
        }
        if ($record->entity === 'documents') {
            $record->status = $record->due_on && $record->due_on->lt(today()) ? 'expired' : 'valid';

            return;
        }
        if ($record->status === 'signed' && $record->due_on && $record->due_on->lt(today())) {
            $record->status = 'expired';
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'contracts' || $record->status !== 'signed' || ! ($record->wasRecentlyCreated || $record->wasChanged('status'))) {
            return;
        }
        $employee = $this->parent($record, 'employee');
        if (! $employee) {
            return;
        }
        $this->linked('contracts', 'employee', $employee)->whereKeyNot($record->id)->where('status', 'signed')->get()
            ->each(fn (Record $older) => $older->update(['status' => 'expired', 'data' => [...$older->data, '_replaced_by' => $record->id]]));
        $employee->update([
            'amount' => (float) $record->amount > 0 ? $record->amount : $employee->amount,
            'data' => [...$employee->data, 'employment_type' => self::EMPLOYMENT_TYPES[$record->value('type')] ?? $employee->value('employment_type')],
        ]);
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'contracts' && $record->status === 'draft' => ['sign' => ['label' => 'Signed', 'icon' => 'file-signature']],
            $record->entity === 'contracts' && $record->status === 'signed' => ['terminate' => ['label' => 'Terminate', 'icon' => 'file-x']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $employee = $this->parent($record, 'employee')?->title ?? $record->title;
        if ($action === 'sign') {
            $record->update(['status' => 'signed']);

            return 'Contract for '.$employee.' signed'.($record->due_on ? ', ending '.$record->due_on->format('d M Y') : '').'.';
        }
        $record->update(['status' => 'terminated', 'data' => [...$record->data, '_terminated_on' => today()->toDateString()]]);

        return 'Contract for '.$employee.' terminated.';
    }

    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        $stale = $this->records('documents')->where('status', 'valid')->whereNotNull('due_on')->whereDate('due_on', '<', today()->toDateString())->get()
            ->merge($this->records('contracts')->where('status', 'signed')->whereNotNull('due_on')->whereDate('due_on', '<', today()->toDateString())->get());
        foreach ($stale as $record) {
            $record->save();
            $changed++;
        }

        return $changed;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'employees') {
            return [];
        }
        $contract = $this->linked('contracts', 'employee', $record)->where('status', 'signed')->latest('occurs_on')->first();
        $documents = $this->linked('documents', 'employee', $record)->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Employment', 'icon' => 'user-round', 'stats' => [
            ['label' => 'Service', 'value' => $record->occurs_on ? $this->service($record->occurs_on) : '—'],
            ['label' => 'Contract', 'value' => $contract ? ($contract->due_on ? 'Ends '.$contract->due_on->format('d M Y') : 'Open-ended') : 'None signed', 'tone' => $contract ? null : 'warning'],
            ['label' => 'Documents', 'value' => $documents->count()],
            ['label' => 'Expired documents', 'value' => $documents->where('status', 'expired')->count(), 'tone' => $documents->where('status', 'expired')->isNotEmpty() ? 'danger' : null],
        ]]]];
    }

    /**
     * Length of service in words: "3 months" or "2 years 4 months".
     */
    protected function service(Carbon $started): string
    {
        $months = max(0, (int) $started->diffInMonths(today()));
        $years = intdiv($months, 12);

        return trim(($years ? $years.' '.str('year')->plural($years).' ' : '').(($months % 12) || ! $years ? ($months % 12).' '.str('month')->plural($months % 12) : ''));
    }

    public function homeCards(): array
    {
        $soon = today()->addDays(self::WARNING_DAYS)->toDateString();
        $employees = $this->records('employees')->pluck('title', 'id');
        $ending = $this->records('contracts')->where('status', 'signed')->whereNotNull('due_on')->whereDate('due_on', '<=', $soon)->orderBy('due_on')->get()
            ->merge($this->records('documents')->whereNotNull('due_on')->whereDate('due_on', '<=', $soon)->orderBy('due_on')->get())->sortBy('due_on');
        $staff = $this->records('employees')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Headcount', 'icon' => 'users', 'stats' => [
                ['label' => 'Employed', 'value' => $staff->where('status', '!=', 'exited')->count()],
                ['label' => 'On probation', 'value' => $staff->where('status', 'probation')->count()],
                ['label' => 'On leave', 'value' => $staff->where('status', 'on_leave')->count()],
                ['label' => 'Monthly basic pay', 'value' => $this->money($staff->where('status', '!=', 'exited')->sum(fn (Record $employee) => (float) $employee->amount))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Running out', 'icon' => 'calendar-clock', 'empty' => 'No contracts or documents run out in the next '.self::WARNING_DAYS.' days.',
                'rows' => $ending->map(fn (Record $item) => [
                    'label' => $employees[$item->value('employee')] ?? $item->title, 'sub' => $item->entity === 'contracts' ? 'Contract' : ucfirst(str_replace('_', ' ', (string) $item->value('type'))),
                    'value' => $item->due_on->format('d M Y'), 'href' => $item->url(), 'tone' => $item->due_on->lt(today()) ? 'danger' : 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $staff = $this->records('employees')->get();

        return [['title' => 'Headcount by department', 'columns' => ['Department', 'Employed', 'Joined in period', 'Left in period', 'Monthly basic pay'], 'rows' => $staff
            ->groupBy(fn (Record $employee) => trim((string) $employee->value('department')) ?: 'No department')->sortKeys()
            ->map(fn ($group, string $department) => [
                $department,
                $group->where('status', '!=', 'exited')->count(),
                $group->filter(fn (Record $employee) => $employee->occurs_on && $employee->occurs_on->between($from, $to))->count(),
                $group->filter(fn (Record $employee) => $employee->value('_exited_on') && Carbon::parse($employee->value('_exited_on'))->between($from, $to))->count(),
                $this->money($group->where('status', '!=', 'exited')->sum(fn (Record $employee) => (float) $employee->amount)),
            ])->values()->all()]];
    }
}
