<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Forms & approvals: only active forms take submissions, every question needs an answer, and a
 * submission with an approver goes straight to pending approval. Only the named approver (or a
 * workspace owner/admin) decides it, a rejection gives its reason, and decided submissions are
 * locked. Turnaround is tracked for the reports.
 */
class FormsApprovalsLogic extends AppLogic
{
    public const DECIDED = ['approved', 'rejected'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'forms') {
            if ($payload['status'] === 'active' && $this->questions((string) ($data['questions'] ?? '')) === []) {
                $errors['data.questions'] = 'Add at least one question.';
            }

            return $errors;
        }

        $form = ! empty($data['form']) ? $this->records('forms')->find($data['form']) : null;
        if ($form && $form->status !== 'active' && (! $existing || (int) $existing->value('form') !== $form->id)) {
            $errors['data.form'] = $form->title.' is '.$form->status.' and is not taking submissions.';
        }
        if ($form) {
            $questions = count($this->questions((string) $form->value('questions')));
            $answers = count($this->questions((string) ($data['answers'] ?? '')));
            if ($answers < $questions) {
                $errors['data.answers'] = 'The form asks '.$questions.' questions; answer each one on its own line ('.$answers.' given).';
            }
        }

        if (in_array($existing?->status, self::DECIDED, true) && $payload['status'] !== $existing->status) {
            $errors['status'] = 'This submission is already '.$existing->status.'.';
        }
        if ($payload['status'] === 'pending_approval' && blank($data['approver'] ?? null)) {
            $errors['data.approver'] = 'Choose who approves this.';
        }
        if ($payload['status'] === 'rejected' && blank($data['comments'] ?? null)) {
            $errors['data.comments'] = 'Say why it is rejected.';
        }
        if (in_array($payload['status'], self::DECIDED, true) && $existing?->status !== $payload['status'] && ! $this->mayDecide($data['approver'] ?? null)) {
            $errors['status'] = 'Only the named approver can decide this submission.';
        }

        return $errors;
    }

    /** @return list<string> */
    public function questions(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: [])));
    }

    protected function mayDecide(mixed $approver): bool
    {
        $user = auth()->user();
        if (! $user || blank($approver) || (int) $approver === $user->id) {
            return true;
        }

        $workspace = app(WorkspaceContext::class)->get();

        return $workspace !== null && $user->isAdminOf($workspace);
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'forms') {
            $this->put($record, ['_submissions' => $record->exists ? $this->linked('submissions', 'form', $record)->count() : 0]);

            return;
        }

        $record->occurs_on ??= today();
        if ($record->status === 'submitted' && filled($record->value('approver'))) {
            $record->status = 'pending_approval';
        }
        if (in_array($record->status, self::DECIDED, true) && ! $record->value('_decided_on')) {
            $this->put($record, ['_decided_on' => today()->toDateString(), '_turnaround_days' => (int) $record->occurs_on->diffInDays(today())]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'submissions') {
            $this->recalculate($this->parent($record, 'form'));
            $this->recalculate($this->previousParent($record, 'form'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'submissions') {
            $this->recalculate($this->parent($record, 'form'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'submissions' || $record->status !== 'pending_approval' || ! $this->mayDecide($record->value('approver'))) {
            return [];
        }

        return [
            'approve' => ['label' => 'Approve', 'icon' => 'check', 'confirm' => 'Approve '.$record->title.'?'],
            'reject' => ['label' => 'Reject', 'icon' => 'x', 'fields' => [['name' => 'comments', 'label' => 'Why is it rejected?', 'type' => 'text']]],
        ];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        abort_unless($record->status === 'pending_approval' && $this->mayDecide($record->value('approver')), 403);

        if ($action === 'approve') {
            $record->update(['status' => 'approved']);

            return $record->title.' is approved.';
        }

        $comments = $request->validate(['comments' => ['required', 'string', 'max:1000']])['comments'];
        $record->update(['status' => 'rejected', 'data' => [...(array) $record->data, 'comments' => $comments]]);

        return $record->title.' is rejected.';
    }

    public function homeCards(): array
    {
        $pending = $this->records('submissions')->where('status', 'pending_approval')->orderBy('occurs_on')->get();
        $mine = $pending->filter(fn (Record $submission) => (int) $submission->value('approver') === (int) auth()->id());

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Approvals', 'icon' => 'list-checks', 'stats' => [
                ['label' => 'Waiting for you', 'value' => (string) $mine->count(), 'tone' => $mine->isNotEmpty() ? 'warning' : null],
                ['label' => 'Pending in total', 'value' => (string) $pending->count()],
                ['label' => 'Oldest waiting', 'value' => $pending->first()?->occurs_on ? (int) $pending->first()->occurs_on->diffInDays(today()).' days' : '—'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Waiting for your approval', 'icon' => 'file-check', 'empty' => 'Nothing is waiting for you.',
                'rows' => $mine->map(fn (Record $submission) => [
                    'label' => $submission->title, 'sub' => $this->parent($submission, 'form')?->title, 'value' => $submission->occurs_on?->format('d M') ?? '', 'href' => $submission->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $submissions = $this->dated('submissions', $from, $to)->get();
        $forms = $this->records('forms')->pluck('title', 'id');
        $decided = fn ($group) => $group->whereIn('status', self::DECIDED);

        $byForm = $submissions->groupBy(fn (Record $submission) => $forms[$submission->value('form')] ?? 'Unknown form')->sortKeys()->map(fn ($group, $form) => [
            $form, $group->count(), $group->where('status', 'approved')->count(), $group->where('status', 'rejected')->count(), $group->whereIn('status', ['submitted', 'pending_approval'])->count(),
            $decided($group)->isNotEmpty() ? round($decided($group)->avg(fn (Record $submission) => (int) $submission->value('_turnaround_days')), 1).' days' : '—',
        ])->values()->all();

        return [['title' => 'Submissions by form', 'columns' => ['Form', 'Submitted', 'Approved', 'Rejected', 'Waiting', 'Average turnaround'], 'rows' => $byForm]];
    }
}
