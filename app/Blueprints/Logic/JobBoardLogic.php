<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Job board / freelance marketplace: a posting closes 30 days after it goes up unless a closing date is set,
 * and open postings past their date close each night. Only open postings take applications, each email
 * applies once per posting, and bids are only for contract and freelance work. Applications move
 * received → shortlisted → interview → hired, and hiring someone fills the posting.
 */
class JobBoardLogic extends AppLogic
{
    /**
     * The next stage for each application stage.
     *
     * @var array<string, string>
     */
    public const NEXT = ['received' => 'shortlisted', 'shortlisted' => 'interview', 'interview' => 'hired'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'jobs') {
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The closing date must be after the posting date.';
            }
            if ((float) ($data['budget'] ?? 0) < 0) {
                $errors['data.budget'] = 'The budget cannot be negative.';
            }

            return $errors;
        }
        $job = filled($data['job'] ?? null) ? $this->records('jobs')->find($data['job']) : null;
        if (! $job) {
            return $errors;
        }
        if (! $existing && $job->status !== 'open') {
            $errors['data.job'] = $job->title.' is '.$job->status.' and not taking applications.';
        }
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        if ($email !== '' && $this->linked('applications', 'job', $job)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $application) => mb_strtolower(trim((string) $application->value('email'))) === $email)) {
            $errors['data.email'] = $email.' has already applied for '.$job->title.'.';
        }
        if ((float) ($data['bid'] ?? 0) > 0 && ! in_array($job->value('type'), ['contract', 'freelance_gig'], true)) {
            $errors['data.bid'] = 'Bids are only for contract and freelance work.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity !== 'jobs') {
            return;
        }
        $record->due_on ??= $record->occurs_on->copy()->addDays(30);
        if ($record->status === 'open' && $record->due_on->lt(today())) {
            $record->status = 'closed';
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'applications' && $record->status === 'hired' && ($job = $this->parent($record, 'job')) && $job->status !== 'filled') {
            $job->update(['status' => 'filled']);
        }
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('jobs')->where('status', 'open')->whereDate('due_on', '<', today()->toDateString())->get()
            ->each(fn (Record $job) => $job->save())->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'jobs') {
            return match ($record->status) {
                'draft', 'closed' => ['publish' => ['label' => 'Open for applications', 'icon' => 'megaphone']],
                'open' => ['close' => ['label' => 'Close', 'icon' => 'lock']],
                default => [],
            };
        }
        $next = self::NEXT[$record->status] ?? null;

        return $next ? ['advance' => ['label' => ucfirst($next), 'icon' => $next === 'hired' ? 'check' : 'arrow-right'], 'reject' => ['label' => 'Reject', 'icon' => 'x']] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'publish':
                $due = $record->due_on && $record->due_on->gte(today()) ? $record->due_on : today()->addDays(30);
                $record->update(['status' => 'open', 'due_on' => $due]);

                return $record->title.' is open until '.$due->format('d M Y').'.';
            case 'close':
                $record->update(['status' => 'closed']);

                return $record->title.' closed.';
            case 'reject':
                $record->update(['status' => 'rejected']);

                return $record->title.' rejected.';
            default:
                $next = self::NEXT[$record->status];
                $record->update(['status' => $next]);
                $job = $this->parent($record, 'job')?->title ?? 'the job';

                return match ($next) {
                    'hired' => $record->title.' hired for '.$job.'; the posting is filled.',
                    'interview' => $record->title.' invited to interview for '.$job.'.',
                    default => $record->title.' shortlisted for '.$job.'.',
                };
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'jobs') {
            return [];
        }
        $applications = $this->linked('applications', 'job', $record)->orderBy('occurs_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => $applications->count().' '.str('application')->plural($applications->count()), 'icon' => 'file-user', 'empty' => 'No applications yet.',
            'rows' => $applications->map(fn (Record $application) => ['label' => $application->title, 'sub' => $application->value('email'), 'value' => ucfirst($application->status), 'href' => $application->url(), 'tone' => match ($application->status) {
                'hired' => 'success', 'rejected' => 'muted', default => null,
            }])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $open = $this->records('jobs')->where('status', 'open')->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Job board', 'icon' => 'briefcase', 'stats' => [
            ['label' => 'Open postings', 'value' => $open->count()],
            ['label' => 'New applications', 'value' => $this->records('applications')->where('status', 'received')->count()],
            ['label' => 'Closing in 7 days', 'value' => $open->filter(fn (Record $job) => $job->due_on && $job->due_on->lte(today()->addDays(7)))->count()],
        ]]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $applications = $this->records('applications')->get()->groupBy(fn (Record $application) => (int) $application->value('job'));
        $posted = $this->dated('jobs', $from, $to)->orderBy('occurs_on')->get();

        return [
            ['title' => 'Postings and applications', 'columns' => ['Posting', 'Type', 'Applications', 'Shortlisted or further', 'Hired'], 'rows' => $posted
                ->map(function (Record $job) use ($applications) {
                    $forJob = $applications->get($job->id, collect());

                    return [$job->title, ucfirst(str_replace('_', ' ', (string) $job->value('type'))), $forJob->count(), $forJob->whereIn('status', ['shortlisted', 'interview', 'hired'])->count(), $forJob->where('status', 'hired')->count()];
                })->all()],
            ['title' => 'Posting fees by month', 'columns' => ['Month', 'Postings', 'Fees'], 'rows' => collect($this->months($from, $to))
                ->map(function (string $label, string $month) use ($posted) {
                    $jobs = $posted->filter(fn (Record $job) => $job->occurs_on?->format('Y-m') === $month);

                    return [$label, $jobs->count(), $this->money($jobs->sum('amount'))];
                })->values()->all()],
        ];
    }
}
