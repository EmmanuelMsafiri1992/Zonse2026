<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * CV & portfolio: an experience entry can't end before it starts, and it is current until its end date
 * passes. A portfolio piece needs a link or a description before it is published. A job application
 * gets a follow-up date a week after applying, moves through interview and offer, and accepting an
 * offer needs the salary. The reports show which sources turn into interviews and offers.
 */
class CareerLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if (in_array($entity->key, ['experience', 'applications'], true) && filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = $entity->key === 'experience' ? 'The end date cannot be before the start date.' : 'Follow up after you have applied.';
        }
        if ($entity->key === 'portfolio' && $payload['status'] === 'published' && blank($data['link'] ?? null) && blank($data['description'] ?? null)) {
            $errors['data.description'] = 'Add a link or a description before publishing.';
        }
        if ($entity->key === 'applications' && $payload['status'] === 'accepted' && (float) ($data['salary'] ?? 0) <= 0) {
            $errors['data.salary'] = 'Record the salary offered before accepting.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'experience') {
            $record->status = $record->due_on && $record->due_on->lt(today()) ? 'past' : 'current';
        }
        if ($record->entity === 'applications') {
            $record->occurs_on ??= today();
            if (! $record->exists && ! $record->due_on && $record->status === 'applied') {
                $record->due_on = $record->occurs_on->copy()->addWeek();
            }
        }
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'applications' && $record->status === 'applied' => ['interview' => $this->interviewAction(), 'reject' => ['label' => 'Rejected', 'icon' => 'x']],
            $record->entity === 'applications' && $record->status === 'interview' => ['offer' => ['label' => 'Offer', 'icon' => 'mail-check', 'fields' => [['name' => 'salary', 'label' => 'Salary offered', 'type' => 'number', 'value' => $record->value('salary')]]], 'interview' => $this->interviewAction(), 'reject' => ['label' => 'Rejected', 'icon' => 'x']],
            $record->entity === 'applications' && $record->status === 'offer' => ['accept' => ['label' => 'Accept', 'icon' => 'check'], 'reject' => ['label' => 'Turned down', 'icon' => 'x']],
            $record->entity === 'portfolio' && $record->status !== 'published' => ['publish' => ['label' => 'Publish', 'icon' => 'globe']],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function interviewAction(): array
    {
        return ['label' => 'Interview booked', 'icon' => 'calendar', 'fields' => [['name' => 'due_on', 'label' => 'Interview date', 'type' => 'date']]];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'interview':
                $date = $request->validate(['due_on' => ['required', 'date']])['due_on'];
                $record->update(['status' => 'interview', 'due_on' => $date, 'data' => [...$record->data, '_interviewed' => true]]);

                return 'Interview with '.$record->title.' on '.$record->due_on->format('D d M Y').'.';
            case 'offer':
                $salary = $request->validate(['salary' => ['nullable', 'numeric', 'min:0']])['salary'] ?? $record->value('salary');
                $record->update(['status' => 'offer', 'due_on' => today()->addDays(3), 'data' => [...$record->data, 'salary' => $salary]]);

                return 'Offer from '.$record->title.($salary ? ' at '.$this->money($salary) : '').'.';
            case 'accept':
                $this->guard($record, 'accepted');
                $record->update(['status' => 'accepted', 'due_on' => null]);

                return 'Congratulations, you accepted '.$record->title.'.';
            case 'reject':
                $record->update(['status' => 'rejected', 'due_on' => null]);

                return $record->title.' closed.';
            default:
                $this->guard($record, 'published');
                $record->update(['status' => 'published']);

                return $record->title.' is published.';
        }
    }

    /**
     * Run the form rules against a status change made from an action.
     */
    protected function guard(Record $record, string $status): void
    {
        $errors = $this->validate($this->app()->entity($record->entity), ['status' => $status, 'data' => (array) $record->data, 'occurs_on' => null, 'due_on' => null], $record);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function homeCards(): array
    {
        $open = $this->records('applications')->whereIn('status', ['applied', 'interview', 'offer'])->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Job hunt ('.$open->count().' open)', 'icon' => 'send', 'empty' => 'No open applications.',
            'rows' => $open->map(fn (Record $application) => [
                'label' => $application->title, 'sub' => ucfirst($application->status),
                'value' => $application->due_on ? ($application->status === 'interview' ? 'interview ' : 'follow up ').$application->due_on->format('d M') : '—',
                'href' => $application->url(), 'tone' => $application->due_on && $application->due_on->lte(today()) ? 'warning' : null,
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $applications = $this->dated('applications', $from, $to)->get();
        $reached = fn ($group, array $statuses) => $group->filter(fn (Record $application) => in_array($application->status, $statuses, true))->count();

        return [['title' => 'Applications by source', 'columns' => ['Source', 'Applied', 'Interviews', 'Offers', 'Interview rate'], 'rows' => $applications
            ->groupBy(fn (Record $application) => (string) ($application->value('source') ?: 'Unknown'))->sortKeys()
            ->map(function ($group, string $source) use ($reached) {
                $interviews = $reached($group, ['interview', 'offer', 'accepted']) + $group->where('status', 'rejected')->filter(fn (Record $application) => $application->value('_interviewed'))->count();

                return [$source, $group->count(), $interviews, $reached($group, ['offer', 'accepted']), round($interviews / $group->count() * 100).'%'];
            })->values()->all()]];
    }
}
