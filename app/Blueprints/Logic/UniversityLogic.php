<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * University & college: students register on active programmes, one registration per student
 * number and semester, fees billed default to the programme's tuition, and a student graduates only
 * with a GPA, which turns the registration into an alumnus. A programme cannot close while students
 * are registered on it.
 */
class UniversityLogic extends AppLogic
{
    public const MAX_GPA = 5;

    public const ENROLLED = ['pending', 'registered', 'deferred'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'programmes') {
            if (filled($data['duration'] ?? null) && (float) $data['duration'] <= 0) {
                $errors['data.duration'] = 'The duration must be longer than nothing.';
            }
            if ((float) ($payload['amount'] ?? 0) < 0) {
                $errors['amount'] = 'Tuition cannot be negative.';
            }
            if ($existing && $payload['status'] === 'closed' && $this->linked('registrations', 'programme', $existing)->whereIn('status', self::ENROLLED)->exists()) {
                $errors['status'] = 'Students are still registered on this programme.';
            }

            return $errors;
        }

        if ($entity->key === 'alumni') {
            if (filled($data['graduation_year'] ?? null) && ((int) $data['graduation_year'] < 1900 || (int) $data['graduation_year'] > (int) today()->year)) {
                $errors['data.graduation_year'] = 'The graduation year must be a past year.';
            }

            return $errors;
        }

        $programme = ! empty($data['programme']) ? $this->records('programmes')->find($data['programme']) : null;
        if ($programme && $programme->status !== 'active' && in_array($payload['status'], self::ENROLLED, true) && (! $existing || (int) $existing->value('programme') !== $programme->id)) {
            $errors['data.programme'] = $programme->title.' is closed to new registrations.';
        }
        $number = strtoupper(trim((string) ($data['student_number'] ?? '')));
        $semester = strtolower(trim((string) ($data['semester'] ?? '')));
        if ($number !== '') {
            $duplicate = $this->records('registrations')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $registration) => strtoupper(trim((string) $registration->value('student_number'))) === $number && strtolower(trim((string) $registration->value('semester'))) === $semester);
            if ($duplicate) {
                $errors['data.student_number'] = $duplicate->title.' is already registered as '.$number.($semester !== '' ? ' for '.$data['semester'] : '').'.';
            }
        }
        if (filled($data['gpa'] ?? null) && ((float) $data['gpa'] < 0 || (float) $data['gpa'] > self::MAX_GPA)) {
            $errors['data.gpa'] = 'The GPA is between 0 and '.self::MAX_GPA.'.';
        }
        if ($payload['status'] === 'graduated' && blank($data['gpa'] ?? null)) {
            $errors['data.gpa'] = 'Enter the final GPA before graduating the student.';
        }
        if ((float) ($payload['amount'] ?? 0) < 0) {
            $errors['amount'] = 'Fees billed cannot be negative.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'registrations') {
            $record->occurs_on ??= today();
            $this->put($record, ['student_number' => strtoupper(trim((string) $record->value('student_number')))]);
            if (blank($record->amount) && ($programme = $this->parent($record, 'programme'))) {
                $record->amount = (float) $programme->amount;
            }

            return;
        }

        if ($record->entity === 'alumni') {
            return;
        }

        $registrations = $record->exists ? $this->linked('registrations', 'programme', $record)->get() : collect();
        $alumni = $record->exists ? $this->linked('alumni', 'programme', $record)->count() : 0;
        $graded = $registrations->filter(fn (Record $registration) => filled($registration->value('gpa')));
        $this->put($record, [
            '_registered' => $registrations->where('status', 'registered')->count(),
            '_pending' => $registrations->where('status', 'pending')->count(),
            '_graduated' => $registrations->where('status', 'graduated')->count(),
            '_alumni' => $alumni,
            '_fees_billed' => round($registrations->whereIn('status', ['registered', 'graduated'])->sum('amount'), 2),
            '_average_gpa' => $graded->isEmpty() ? null : round($graded->avg(fn (Record $registration) => (float) $registration->value('gpa')), 2),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'programmes') {
            $this->recalculate($this->parent($record, 'programme'));
            $this->recalculate($this->previousParent($record, 'programme'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity !== 'programmes') {
            $this->recalculate($this->parent($record, 'programme'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'programmes') {
            return $record->status === 'active'
                ? ['close' => ['label' => 'Close programme', 'icon' => 'lock', 'confirm' => 'Close this programme to new registrations?']]
                : ['reopen' => ['label' => 'Reopen', 'icon' => 'unlock']];
        }

        if ($record->entity === 'alumni') {
            return $record->status === 'active'
                ? ['lost_contact' => ['label' => 'Lost contact', 'icon' => 'user-x']]
                : ['found' => ['label' => 'Back in touch', 'icon' => 'user-check', 'fields' => [
                    ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $record->value('email')],
                    ['name' => 'employer', 'label' => 'Employer', 'type' => 'text', 'value' => $record->value('employer')],
                ]]];
        }

        return match ($record->status) {
            'pending' => [
                'register' => ['label' => 'Register', 'icon' => 'check'],
                'defer' => ['label' => 'Defer', 'icon' => 'pause'],
            ],
            'deferred' => ['register' => ['label' => 'Register', 'icon' => 'check']],
            'registered' => [
                'graduate' => ['label' => 'Graduate', 'icon' => 'graduation-cap', 'fields' => [
                    ['name' => 'gpa', 'label' => 'Final GPA', 'type' => 'number', 'value' => $record->value('gpa')],
                ]],
                'withdraw' => ['label' => 'Withdraw', 'icon' => 'x', 'confirm' => 'Withdraw this student from the programme?'],
            ],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'close':
                if ($this->linked('registrations', 'programme', $record)->whereIn('status', self::ENROLLED)->exists()) {
                    throw ValidationException::withMessages(['status' => 'Students are still registered on this programme.']);
                }
                $record->update(['status' => 'closed']);

                return $record->title.' is closed.';
            case 'reopen':
                $record->update(['status' => 'active']);

                return $record->title.' is open again.';
            case 'lost_contact':
                $record->update(['status' => 'lost_contact']);

                return 'Marked as lost contact.';
            case 'found':
                $record->update(['status' => 'active', 'data' => [...(array) $record->data, 'email' => $request->input('email', $record->value('email')), 'employer' => $request->input('employer', $record->value('employer'))]]);

                return $record->title.' is back in touch.';
            case 'register':
                $programme = $this->parent($record, 'programme');
                if ($programme && $programme->status !== 'active') {
                    throw ValidationException::withMessages(['data.programme' => $programme->title.' is closed to new registrations.']);
                }
                $record->update(['status' => 'registered', 'occurs_on' => $record->occurs_on ?? today()]);

                return $record->title.' is registered'.($programme ? ' on '.$programme->title : '').'.';
            case 'defer':
                $record->update(['status' => 'deferred']);

                return 'Registration deferred.';
            case 'withdraw':
                $record->update(['status' => 'withdrawn']);

                return $record->title.' has withdrawn.';
        }

        $gpa = (float) $request->validate(['gpa' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_GPA]])['gpa'];
        $record->update(['status' => 'graduated', 'data' => [...(array) $record->data, 'gpa' => $gpa]]);
        $alumnus = Record::create([
            'workspace_id' => $record->workspace_id,
            'branch_id' => $record->branch_id,
            'blueprint' => $record->blueprint,
            'entity' => 'alumni',
            'title' => $record->title,
            'status' => 'active',
            'contact_id' => $record->contact_id,
            'data' => ['programme' => $record->value('programme'), 'graduation_year' => (int) today()->year, 'email' => $record->contact?->email],
        ]);

        return $record->title.' graduated with a GPA of '.rtrim(rtrim(number_format($gpa, 2), '0'), '.').' and is now alumnus '.$alumnus->number.'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'programmes') {
            return [];
        }

        $registrations = $this->linked('registrations', 'programme', $record)->orderByDesc('occurs_on')->get();
        $levels = $this->app->entities['programmes']->field('level')?->options ?? [];

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Programme', 'icon' => 'school', 'stats' => [
                ['label' => 'Level', 'value' => $levels[$record->value('level')] ?? ucfirst((string) $record->value('level'))],
                ['label' => 'Registered', 'value' => (string) (int) $record->value('_registered')],
                ['label' => 'Pending', 'value' => (string) (int) $record->value('_pending'), 'tone' => (int) $record->value('_pending') > 0 ? 'warning' : null],
                ['label' => 'Graduated', 'value' => (string) (int) $record->value('_graduated')],
                ['label' => 'Average GPA', 'value' => $record->value('_average_gpa') === null ? '—' : (string) $record->value('_average_gpa')],
                ['label' => 'Fees billed', 'value' => $this->money($this->number($record, '_fees_billed'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Registrations', 'icon' => 'id-card', 'empty' => 'Nobody is registered yet.',
                'rows' => $registrations->take(10)->map(fn (Record $registration) => [
                    'label' => $registration->title, 'sub' => $registration->value('student_number').($registration->value('semester') ? ' · '.$registration->value('semester') : ''), 'value' => ucfirst($registration->status), 'href' => $registration->url(),
                    'tone' => $registration->status === 'pending' ? 'warning' : ($registration->status === 'withdrawn' ? 'danger' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $programmes = $this->records('programmes')->get();
        $registrations = $this->records('registrations')->get();
        $pending = $registrations->where('status', 'pending')->sortBy('occurs_on');
        $names = $programmes->pluck('title', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'University', 'icon' => 'school', 'stats' => [
                ['label' => 'Active programmes', 'value' => (string) $programmes->where('status', 'active')->count()],
                ['label' => 'Registered students', 'value' => (string) $registrations->where('status', 'registered')->count()],
                ['label' => 'Pending registrations', 'value' => (string) $pending->count(), 'tone' => $pending->isNotEmpty() ? 'warning' : null],
                ['label' => 'Fees billed this year', 'value' => $this->money($registrations->whereIn('status', ['registered', 'graduated'])->filter(fn (Record $registration) => $registration->occurs_on?->isCurrentYear())->sum('amount'))],
                ['label' => 'Graduated this year', 'value' => (string) $this->records('alumni')->where('data->graduation_year', (int) today()->year)->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Pending registrations', 'icon' => 'id-card', 'empty' => 'Every registration is processed.',
                'rows' => $pending->take(10)->map(fn (Record $registration) => [
                    'label' => $registration->title, 'sub' => ($names[(int) $registration->value('programme')] ?? '').' · '.$registration->value('student_number'), 'value' => $registration->occurs_on?->format('d M Y') ?? '', 'href' => $registration->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $programmes = $this->records('programmes')->get();
        $byFaculty = $programmes->groupBy(fn (Record $programme) => $programme->value('faculty') ?: 'Unknown')->sortKeys()->map(fn ($group, $faculty) => [
            $faculty, $group->count(), $group->sum(fn (Record $programme) => (int) $programme->value('_registered')), $group->sum(fn (Record $programme) => (int) $programme->value('_graduated')), $this->money($group->sum(fn (Record $programme) => (float) $programme->value('_fees_billed'))),
        ])->values()->all();

        $registrations = $this->dated('registrations', $from, $to)->get();
        $bySemester = $registrations->groupBy(fn (Record $registration) => $registration->value('semester') ?: 'Unspecified')->sortKeys()->map(fn ($group, $semester) => [
            $semester, $group->whereIn('status', ['registered', 'graduated'])->count(), $group->where('status', 'pending')->count(), $group->where('status', 'deferred')->count(), $group->where('status', 'withdrawn')->count(), $this->money($group->whereIn('status', ['registered', 'graduated'])->sum('amount')),
        ])->values()->all();

        $alumni = $this->records('alumni')->get();
        $byYear = $alumni->groupBy(fn (Record $alumnus) => (int) $alumnus->value('graduation_year'))->sortKeysDesc()->map(fn ($group, $year) => [
            $year ?: 'Unknown', $group->count(), $group->where('status', 'active')->count(), $group->filter(fn (Record $alumnus) => filled($alumnus->value('employer')))->count(),
        ])->values()->all();

        return [
            ['title' => 'Programmes by faculty', 'columns' => ['Faculty', 'Programmes', 'Registered', 'Graduated', 'Fees billed'], 'rows' => $byFaculty],
            ['title' => 'Registrations by semester', 'columns' => ['Semester', 'Registered', 'Pending', 'Deferred', 'Withdrawn', 'Fees billed'], 'rows' => $bySemester],
            ['title' => 'Alumni by year', 'columns' => ['Year', 'Alumni', 'In touch', 'Employed'], 'rows' => $byYear],
        ];
    }
}
