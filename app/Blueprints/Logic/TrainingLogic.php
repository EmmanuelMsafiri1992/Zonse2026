<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Training & certifications: a course can't end before it starts and is run, then completed. A
 * certification's status follows its expiry date — valid, expiring within 60 days, or expired — and is
 * brought up to date each morning; certificate numbers are unique. Each course shows how many people
 * it certified and its cost per certificate, and reports total training spend by type.
 */
class TrainingLogic extends AppLogic
{
    /**
     * Days before expiry that a certification counts as expiring.
     */
    public const WARNING_DAYS = 60;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $errors = [];
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = $entity->key === 'courses' ? 'The course cannot end before it starts.' : 'It cannot expire before it was issued.';
        }
        if ($entity->key === 'courses' && (float) ($payload['amount'] ?? 0) < 0) {
            $errors['amount'] = 'The cost cannot be negative.';
        }
        $number = mb_strtoupper(trim((string) ($payload['data']['certificate_number'] ?? '')));
        if ($entity->key === 'certifications' && $number !== '') {
            $twin = $this->records('certifications')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $certificate) => mb_strtoupper(trim((string) $certificate->value('certificate_number'))) === $number);
            if ($twin) {
                $errors['data.certificate_number'] = 'Certificate '.$number.' is already recorded for '.$this->name($twin->value('employee')).'.';
            }
        }

        return $errors;
    }

    /**
     * A certification's status for its expiry date.
     */
    public function standing(?Carbon $expires): string
    {
        return match (true) {
            ! $expires => 'valid',
            $expires->lt(today()) => 'expired',
            $expires->lte(today()->addDays(self::WARNING_DAYS)) => 'expiring',
            default => 'valid',
        };
    }

    protected function name(mixed $userId): string
    {
        return (string) (filled($userId) ? User::query()->whereKey($userId)->value('name') : null) ?: 'Unknown';
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'certifications') {
            $record->status = $this->standing($record->due_on);
        }
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity !== 'courses' => [],
            $record->status === 'planned' => ['start' => ['label' => 'Start course', 'icon' => 'play']],
            $record->status === 'running' => ['complete' => ['label' => 'Completed', 'icon' => 'check']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'start') {
            $record->update(['status' => 'running']);

            return $record->title.' has started.';
        }
        $record->update(['status' => 'completed', 'due_on' => $record->due_on ?? today()]);

        return $record->title.' completed. Record each attendee\'s certificate under Certifications.';
    }

    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        foreach ($this->records('certifications')->where('status', '!=', 'expired')->get() as $certificate) {
            if ($certificate->status !== $this->standing($certificate->due_on)) {
                $certificate->save();
                $changed++;
            }
        }

        return $changed;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'courses') {
            return [];
        }
        $certified = $this->linked('certifications', 'course', $record)->count();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Course', 'icon' => 'presentation', 'stats' => [
            ['label' => 'Certified', 'value' => $certified],
            ['label' => 'Cost per certificate', 'value' => $certified ? $this->money((float) $record->amount / $certified) : '—'],
            ['label' => 'Days', 'value' => $record->occurs_on && $record->due_on ? (int) $record->occurs_on->diffInDays($record->due_on) + 1 : '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        $lapsing = $this->records('certifications')->whereIn('status', ['expiring', 'expired'])->orderBy('due_on')->get();
        $names = User::query()->whereIn('id', $lapsing->map(fn (Record $certificate) => $certificate->value('employee'))->filter()->unique())->pluck('name', 'id');

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Certifications to renew', 'icon' => 'file-badge', 'empty' => 'Every certification is in date.',
            'rows' => $lapsing->map(fn (Record $certificate) => [
                'label' => $names[$certificate->value('employee')] ?? 'Unknown', 'sub' => $certificate->title,
                'value' => $certificate->due_on?->format('d M Y'), 'href' => $certificate->url(), 'tone' => $certificate->status === 'expired' ? 'danger' : 'warning',
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $courses = $this->dated('courses', $from, $to)->get();
        $certified = $this->records('certifications')->get()->countBy(fn (Record $certificate) => (int) $certificate->value('course'));

        return [['title' => 'Training spend by type', 'columns' => ['Type', 'Courses', 'Completed', 'Certified', 'Spend'], 'rows' => $courses
            ->groupBy(fn (Record $course) => ucfirst((string) ($course->value('type') ?: 'other')))->sortKeys()
            ->map(fn ($group, string $type) => [
                $type, $group->count(), $group->where('status', 'completed')->count(), $group->sum(fn (Record $course) => $certified[$course->id] ?? 0),
                $this->money($group->sum(fn (Record $course) => (float) $course->amount)),
            ])->values()->all()]];
    }
}
