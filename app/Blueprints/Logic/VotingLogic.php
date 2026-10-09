<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Voting & elections: an election moves from draft to nominations, voting, closed and results
 * published. Candidates are nominated only while nominations are open, voters are on one register
 * per election with a unique member number and a voting code, and each votes once while voting is
 * open. Votes never exceed the voters who turned out, turnout is worked out from the register,
 * voting closes by itself at the closing date, and publishing results elects the leading candidate
 * for each position.
 */
class VotingLogic extends AppLogic
{
    public const OPEN = ['nominations', 'voting'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'elections') {
            if (filled($data['eligible_voters'] ?? null) && (int) $data['eligible_voters'] < 0) {
                $errors['data.eligible_voters'] = 'Eligible voters cannot be negative.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'Voting closes before it opens.';
            }
            if ($payload['status'] === 'voting' && $existing && $existing->status !== 'voting' && $this->linked('candidates', 'election', $existing)->where('status', 'approved')->count() < 1) {
                $errors['status'] = 'Approve at least one candidate before voting opens.';
            }
            if ($payload['status'] === 'results_published' && $existing && ! in_array($existing->status, ['closed', 'results_published'], true)) {
                $errors['status'] = 'Close voting before publishing results.';
            }

            return $errors;
        }

        $election = ! empty($data['election']) ? $this->records('elections')->find($data['election']) : null;
        $new = $election && (! $existing || (int) $existing->value('election') !== $election->id);

        if ($entity->key === 'candidates') {
            if ($new && $election->status !== 'nominations') {
                $errors['data.election'] = 'Nominations for '.$election->title.' are '.($election->status === 'draft' ? 'not open yet' : 'closed').'.';
            }
            if (filled($data['votes'] ?? null) && (int) $data['votes'] < 0) {
                $errors['data.votes'] = 'Votes cannot be negative.';
            } elseif ($election && filled($data['votes'] ?? null) && (int) $data['votes'] > 0 && ($voted = $this->linked('voters', 'election', $election)->where('status', 'voted')->count()) > 0 && (int) $data['votes'] > $voted) {
                $errors['data.votes'] = 'Only '.$voted.' voters have voted in '.$election->title.'.';
            }
            if (in_array($payload['status'], ['elected', 'not_elected'], true) && $election && ! in_array($election->status, ['closed', 'results_published'], true)) {
                $errors['status'] = 'Results come once voting in '.$election->title.' has closed.';
            }

            return $errors;
        }

        if ($new && in_array($election->status, ['closed', 'results_published'], true)) {
            $errors['data.election'] = $election->title.' has closed; the register is final.';
        }
        $number = strtoupper(trim((string) ($data['member_number'] ?? '')));
        if ($election && $number !== '' && $this->linked('voters', 'election', $election)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $voter) => strtoupper(trim((string) $voter->value('member_number'))) === $number)) {
            $errors['data.member_number'] = 'Member '.$number.' is already on the register for '.$election->title.'.';
        }
        if ($payload['status'] === 'voted' && $election && $election->status !== 'voting' && $existing?->status !== 'voted') {
            $errors['status'] = 'Voting in '.$election->title.' is not open.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'voters') {
            $this->put($record, [
                'member_number' => strtoupper(trim((string) $record->value('member_number'))) ?: null,
                'voting_code' => $record->value('voting_code') ?: strtoupper(Str::random(6)),
                '_voted_at' => $record->status === 'voted' ? ($record->value('_voted_at') ?? now()->toDateTimeString()) : null,
            ]);

            return;
        }

        if ($record->entity === 'candidates') {
            $this->put($record, ['votes' => filled($record->value('votes')) ? (int) $record->value('votes') : null]);

            return;
        }

        $candidates = $record->exists ? $this->linked('candidates', 'election', $record)->get() : collect();
        $voters = $record->exists ? $this->linked('voters', 'election', $record)->get() : collect();
        $eligible = (int) ($record->value('eligible_voters') ?: $voters->where('status', '!=', 'ineligible')->count());
        $voted = $voters->where('status', 'voted')->count();
        $leader = $candidates->whereIn('status', ['approved', 'elected'])->sortByDesc(fn (Record $candidate) => (int) $candidate->value('votes'))->first();
        $this->put($record, [
            '_candidates' => $candidates->whereIn('status', ['approved', 'elected', 'not_elected'])->count(),
            '_nominated' => $candidates->where('status', 'nominated')->count(),
            '_registered' => $voters->where('status', '!=', 'ineligible')->count(),
            '_voted' => $voted,
            'turnout' => $eligible > 0 ? (int) round($voted / $eligible * 100) : 0,
            '_votes_cast' => $candidates->whereIn('status', ['approved', 'elected', 'not_elected'])->sum(fn (Record $candidate) => (int) $candidate->value('votes')),
            '_leader' => $leader && (int) $leader->value('votes') > 0 ? $leader->title : null,
            '_closed_on' => in_array($record->status, ['closed', 'results_published'], true) ? ($record->value('_closed_on') ?? today()->toDateString()) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'elections') {
            $this->recalculate($this->parent($record, 'election'));
            $this->recalculate($this->previousParent($record, 'election'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity !== 'elections') {
            $this->recalculate($this->parent($record, 'election'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $closed = 0;
        foreach ($this->records('elections')->where('status', 'voting')->whereNotNull('due_on')->whereDate('due_on', '<', today())->get() as $election) {
            $election->update(['status' => 'closed']);
            $closed++;
        }

        return $closed;
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'elections') {
            return match ($record->status) {
                'draft' => ['open_nominations' => ['label' => 'Open nominations', 'icon' => 'user-plus']],
                'nominations' => ['open_voting' => ['label' => 'Open voting', 'icon' => 'vote', 'fields' => [
                    ['name' => 'occurs_on', 'label' => 'Voting opens', 'type' => 'date', 'value' => $record->occurs_on?->toDateString() ?? today()->toDateString()],
                    ['name' => 'due_on', 'label' => 'Voting closes', 'type' => 'date', 'value' => $record->due_on?->toDateString() ?? today()->addDays(7)->toDateString()],
                ]]],
                'voting' => ['close_voting' => ['label' => 'Close voting', 'icon' => 'lock', 'confirm' => 'Close voting in '.$record->title.'?']],
                'closed' => ['publish_results' => ['label' => 'Publish results', 'icon' => 'trophy', 'confirm' => 'Publish the results? The leading candidate for each position is elected.']],
                default => [],
            };
        }

        if ($record->entity === 'candidates') {
            return match ($record->status) {
                'nominated' => ['approve' => ['label' => 'Approve', 'icon' => 'check'], 'withdraw' => ['label' => 'Withdraw', 'icon' => 'x']],
                'approved' => ['record_votes' => ['label' => 'Record votes', 'icon' => 'vote', 'fields' => [['name' => 'votes', 'label' => 'Votes', 'type' => 'number', 'value' => $record->value('votes')]]], 'withdraw' => ['label' => 'Withdraw', 'icon' => 'x', 'confirm' => 'Withdraw '.$record->title.'?']],
                default => [],
            };
        }

        return match ($record->status) {
            'eligible' => ['mark_voted' => ['label' => 'Voted', 'icon' => 'check'], 'new_code' => ['label' => 'New voting code', 'icon' => 'refresh-cw'], 'disqualify' => ['label' => 'Ineligible', 'icon' => 'ban', 'confirm' => 'Mark '.$record->title.' as ineligible?']],
            'ineligible' => ['restore' => ['label' => 'Eligible', 'icon' => 'user-check']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'open_nominations':
                $record->update(['status' => 'nominations']);

                return 'Nominations for '.$record->title.' are open.';
            case 'open_voting':
                $input = $request->validate(['occurs_on' => ['required', 'date'], 'due_on' => ['required', 'date', 'after_or_equal:occurs_on']]);
                $approved = $this->linked('candidates', 'election', $record)->where('status', 'approved')->count();
                if ($approved < 1) {
                    throw ValidationException::withMessages(['status' => 'Approve at least one candidate before voting opens.']);
                }
                $record->update(['status' => 'voting', 'occurs_on' => Carbon::parse($input['occurs_on']), 'due_on' => Carbon::parse($input['due_on'])]);

                return 'Voting in '.$record->title.' is open until '.Carbon::parse($input['due_on'])->format('d M Y').' with '.$approved.' candidates.';
            case 'close_voting':
                $record->update(['status' => 'closed']);

                return 'Voting in '.$record->title.' closed; turnout '.(int) $record->fresh()->value('turnout').'%.';
            case 'publish_results':
                return $this->publishResults($record);
            case 'approve':
                $record->update(['status' => 'approved']);

                return $record->title.' approved'.($record->value('position') ? ' for '.$record->value('position') : '').'.';
            case 'withdraw':
                $record->update(['status' => 'withdrawn']);

                return $record->title.' withdrew.';
            case 'record_votes':
                $votes = (int) $request->validate(['votes' => ['required', 'integer', 'min:0']])['votes'];
                $election = $this->parent($record, 'election');
                if ($election && ! in_array($election->status, ['voting', 'closed'], true)) {
                    throw ValidationException::withMessages(['votes' => 'Votes are recorded while voting is open or just closed.']);
                }
                $voted = $election ? $this->linked('voters', 'election', $election)->where('status', 'voted')->count() : 0;
                if ($voted > 0 && $votes > $voted) {
                    throw ValidationException::withMessages(['votes' => 'Only '.$voted.' voters have voted.']);
                }
                $record->update(['data' => [...$record->data, 'votes' => $votes]]);

                return $record->title.': '.$votes.' votes.';
            case 'mark_voted':
                $election = $this->parent($record, 'election');
                if ($election && $election->status !== 'voting') {
                    throw ValidationException::withMessages(['status' => 'Voting in '.$election->title.' is not open.']);
                }
                $record->update(['status' => 'voted']);

                return $record->title.' voted.';
            case 'new_code':
                $record->update(['data' => [...$record->data, 'voting_code' => strtoupper(Str::random(6))]]);

                return 'New voting code '.$record->fresh()->value('voting_code').' for '.$record->title.'.';
            case 'disqualify':
                $record->update(['status' => 'ineligible']);

                return $record->title.' is ineligible.';
        }

        $record->update(['status' => 'eligible']);

        return $record->title.' is eligible to vote.';
    }

    protected function publishResults(Record $election): string
    {
        $candidates = $this->linked('candidates', 'election', $election)->where('status', 'approved')->get();
        if ($candidates->isEmpty()) {
            throw ValidationException::withMessages(['status' => 'There are no approved candidates to elect.']);
        }
        $winners = [];
        foreach ($candidates->groupBy(fn (Record $candidate) => $candidate->value('position') ?: '') as $position => $group) {
            $sorted = $group->sortByDesc(fn (Record $candidate) => (int) $candidate->value('votes'))->values();
            $winner = $sorted->first();
            if ((int) $winner->value('votes') <= 0 || ($sorted->count() > 1 && (int) $sorted[1]->value('votes') === (int) $winner->value('votes'))) {
                throw ValidationException::withMessages(['status' => ($position ? $position : 'The poll').' has no clear winner; record the votes first.']);
            }
            foreach ($sorted as $index => $candidate) {
                $candidate->update(['status' => $index === 0 ? 'elected' : 'not_elected']);
            }
            $winners[] = $winner->title.($position ? ' as '.$position : '');
        }
        $election->update(['status' => 'results_published']);

        return 'Results published: '.implode(', ', $winners).' elected.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'elections') {
            return [];
        }

        $candidates = $this->linked('candidates', 'election', $record)->whereIn('status', ['approved', 'elected', 'not_elected'])->get()->sortByDesc(fn (Record $candidate) => (int) $candidate->value('votes'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Election', 'icon' => 'vote', 'stats' => [
                ['label' => 'Stage', 'value' => ucfirst(str_replace('_', ' ', $record->status))],
                ['label' => 'Candidates', 'value' => (string) (int) $record->value('_candidates')],
                ['label' => 'Awaiting approval', 'value' => (string) (int) $record->value('_nominated'), 'tone' => (int) $record->value('_nominated') > 0 ? 'warning' : null],
                ['label' => 'Registered voters', 'value' => (string) (int) $record->value('_registered')],
                ['label' => 'Voted', 'value' => (string) (int) $record->value('_voted')],
                ['label' => 'Turnout', 'value' => (int) $record->value('turnout').'%'],
                ['label' => 'Leading', 'value' => $record->value('_leader') ?: '—'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Candidates', 'icon' => 'user-check', 'empty' => 'No approved candidates yet.',
                'rows' => $candidates->take(15)->map(fn (Record $candidate) => [
                    'label' => $candidate->title, 'sub' => $candidate->value('position') ?: 'Option', 'value' => (int) $candidate->value('votes').' votes'.($candidate->status === 'elected' ? ' · Elected' : ''), 'href' => $candidate->url(), 'tone' => $candidate->status === 'elected' ? 'success' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $elections = $this->records('elections')->get();
        $open = $elections->whereIn('status', self::OPEN)->sortBy('due_on');
        $closing = $open->filter(fn (Record $election) => $election->status === 'voting' && $election->due_on?->between(today(), today()->addDays(7)));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Elections', 'icon' => 'vote', 'stats' => [
                ['label' => 'Nominations open', 'value' => (string) $elections->where('status', 'nominations')->count()],
                ['label' => 'Voting now', 'value' => (string) $elections->where('status', 'voting')->count(), 'tone' => $elections->where('status', 'voting')->isNotEmpty() ? 'success' : null],
                ['label' => 'Closing this week', 'value' => (string) $closing->count(), 'tone' => $closing->isNotEmpty() ? 'warning' : null],
                ['label' => 'Results to publish', 'value' => (string) $elections->where('status', 'closed')->count(), 'tone' => $elections->where('status', 'closed')->isNotEmpty() ? 'warning' : null],
                ['label' => 'Votes cast', 'value' => (string) $elections->where('status', 'voting')->sum(fn (Record $election) => (int) $election->value('_voted'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Open elections', 'icon' => 'calendar-days', 'empty' => 'Nothing is open for nominations or voting.',
                'rows' => $open->take(10)->map(fn (Record $election) => [
                    'label' => $election->title, 'sub' => ucfirst($election->status).($election->due_on ? ' · closes '.$election->due_on->format('d M') : ''), 'value' => $election->status === 'voting' ? (int) $election->value('turnout').'% turnout' : (int) $election->value('_nominated').' to approve', 'href' => $election->url(), 'tone' => $election->status === 'voting' ? 'success' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $elections = $this->records('elections')->get();
        $methods = $this->app->entities['elections']->field('method')?->options ?? [];
        $electionRows = $elections->sortByDesc('occurs_on')->map(fn (Record $election) => [
            $election->title, $methods[$election->value('method')] ?? ucfirst(str_replace('_', ' ', (string) $election->value('method'))), ucfirst(str_replace('_', ' ', $election->status)), (int) $election->value('_candidates'), (int) $election->value('_registered'), (int) $election->value('_voted'), (int) $election->value('turnout').'%',
        ])->values()->all();

        $results = $this->records('candidates')->get()->whereIn('status', ['elected', 'not_elected'])->sortBy([fn (Record $a, Record $b) => (int) $a->value('election') <=> (int) $b->value('election')])->map(fn (Record $candidate) => [
            $elections->firstWhere('id', (int) $candidate->value('election'))?->title ?? '—', $candidate->value('position') ?: '—', $candidate->title, (int) $candidate->value('votes'), $candidate->status === 'elected' ? 'Elected' : 'Not elected',
        ])->values()->all();

        $byMethod = collect($methods)->map(function (string $label, string $method) use ($elections) {
            $group = $elections->where('data.method', $method)->whereIn('status', ['closed', 'results_published']);

            return [$label, $group->count(), $group->sum(fn (Record $election) => (int) $election->value('_voted')), $group->isEmpty() ? '—' : round($group->avg(fn (Record $election) => (int) $election->value('turnout'))).'%'];
        })->values()->all();

        return [
            ['title' => 'Elections', 'columns' => ['Election', 'Method', 'Stage', 'Candidates', 'Registered', 'Voted', 'Turnout'], 'rows' => $electionRows],
            ['title' => 'Results', 'columns' => ['Election', 'Position', 'Candidate', 'Votes', 'Outcome'], 'rows' => $results],
            ['title' => 'Turnout by method', 'columns' => ['Method', 'Elections held', 'Votes cast', 'Average turnout'], 'rows' => $byMethod],
        ];
    }
}
