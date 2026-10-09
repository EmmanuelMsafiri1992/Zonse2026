<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Sports leagues: a fixture is between two different active teams, neither playing twice the same
 * day, and is played only with both scores in. Each team keeps its played, won, drawn, lost, goals
 * and points from its played fixtures, players wear one jersey number per team and move between
 * teams by transfer, and a team is not withdrawn with fixtures still to play.
 */
class SportsLeaguesLogic extends AppLogic
{
    public const WIN_POINTS = 3;

    public const DRAW_POINTS = 1;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'teams') {
            if ((float) ($payload['amount'] ?? 0) < 0) {
                $errors['amount'] = 'The affiliation fee cannot be negative.';
            }
            if ($payload['status'] === 'withdrawn' && $existing && $existing->status !== 'withdrawn' && ($pending = $this->fixturesFor($existing)->where('status', 'scheduled')->count()) > 0) {
                $errors['status'] = $existing->title.' still has '.$pending.' fixtures to play.';
            }

            return $errors;
        }

        if ($entity->key === 'players') {
            $team = ! empty($data['team']) ? $this->records('teams')->find($data['team']) : null;
            if ($team && $team->status !== 'active' && (! $existing || (int) $existing->value('team') !== $team->id)) {
                $errors['data.team'] = $team->title.' has withdrawn from the league.';
            }
            if ($team && filled($data['jersey_number'] ?? null) && $this->linked('players', 'team', $team)->where('status', '!=', 'transferred')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $player) => (int) $player->value('jersey_number') === (int) $data['jersey_number'])) {
                $errors['data.jersey_number'] = 'Number '.(int) $data['jersey_number'].' is already worn at '.$team->title.'.';
            }
            if (filled($data['date_of_birth'] ?? null) && Carbon::parse($data['date_of_birth'])->gt(today())) {
                $errors['data.date_of_birth'] = 'The date of birth is in the future.';
            }

            return $errors;
        }

        $home = ! empty($data['home_team']) ? $this->records('teams')->find($data['home_team']) : null;
        $away = ! empty($data['away_team']) ? $this->records('teams')->find($data['away_team']) : null;
        if ($home && $away && $home->id === $away->id) {
            $errors['data.away_team'] = 'A team cannot play itself.';
        }
        foreach (['home_team' => $home, 'away_team' => $away] as $field => $team) {
            if ($team && $team->status !== 'active' && $payload['status'] === 'scheduled') {
                $errors['data.'.$field] = $team->title.' has withdrawn from the league.';
            }
        }
        $day = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : null;
        if ($day && $payload['status'] === 'scheduled' && ! isset($errors['data.away_team'])) {
            foreach ([$home, $away] as $team) {
                if ($team && $this->fixturesFor($team)->where('status', 'scheduled')->whereDate('occurs_on', $day)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                    $errors['occurs_on'] = $team->title.' already plays on '.$day->format('d M Y').'.';
                }
            }
        }
        if ($payload['status'] === 'played') {
            if (! filled($data['home_score'] ?? null) || ! filled($data['away_score'] ?? null)) {
                $errors['data.home_score'] = 'Enter both scores to mark the match played.';
            } elseif ((int) $data['home_score'] < 0 || (int) $data['away_score'] < 0) {
                $errors['data.home_score'] = 'Scores cannot be negative.';
            }
            if ($day && $day->gt(today())) {
                $errors['status'] = 'The match has not been played yet.';
            }
        }

        return $errors;
    }

    protected function fixturesFor(Record $team)
    {
        return $this->records('fixtures')->where(fn ($query) => $query->where('data->home_team', $team->id)->orWhere('data->away_team', $team->id));
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'fixtures') {
            $record->occurs_on ??= today();
            $played = $record->status === 'played';
            $home = (int) $record->value('home_score');
            $away = (int) $record->value('away_score');
            $homeTeam = $this->parent($record, 'home_team');
            $awayTeam = $this->parent($record, 'away_team');
            if (blank($record->title) || $record->title === 'Match') {
                $record->title = ($homeTeam?->title ?? 'Home').' v '.($awayTeam?->title ?? 'Away');
            }
            $this->put($record, [
                '_result' => $played ? $home.'–'.$away : null,
                '_winner' => $played ? ($home > $away ? $homeTeam?->title : ($away > $home ? $awayTeam?->title : 'Draw')) : null,
            ]);

            return;
        }

        if ($record->entity === 'players') {
            $this->put($record, ['_age' => filled($record->value('date_of_birth')) ? (int) Carbon::parse($record->value('date_of_birth'))->diffInYears(today()) : null]);

            return;
        }

        $fixtures = $record->exists ? $this->fixturesFor($record)->where('status', 'played')->get() : collect();
        $won = $drawn = $lost = $for = $against = 0;
        foreach ($fixtures as $fixture) {
            $isHome = (int) $fixture->value('home_team') === $record->id;
            $scored = (int) $fixture->value($isHome ? 'home_score' : 'away_score');
            $conceded = (int) $fixture->value($isHome ? 'away_score' : 'home_score');
            $for += $scored;
            $against += $conceded;
            if ($scored > $conceded) {
                $won++;
            } elseif ($scored === $conceded) {
                $drawn++;
            } else {
                $lost++;
            }
        }
        $players = $record->exists ? $this->linked('players', 'team', $record)->get() : collect();
        $this->put($record, [
            '_played' => $fixtures->count(),
            '_won' => $won,
            '_drawn' => $drawn,
            '_lost' => $lost,
            '_goals_for' => $for,
            '_goals_against' => $against,
            '_goal_difference' => $for - $against,
            '_points' => $won * self::WIN_POINTS + $drawn * self::DRAW_POINTS,
            '_players' => $players->where('status', '!=', 'transferred')->count(),
            '_unavailable' => $players->whereIn('status', ['suspended', 'injured'])->count(),
            '_next_fixture' => $record->exists ? $this->fixturesFor($record)->where('status', 'scheduled')->whereDate('occurs_on', '>=', today())->orderBy('occurs_on')->first()?->occurs_on?->toDateString() : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'fixtures') {
            foreach (['home_team', 'away_team'] as $field) {
                $this->recalculate($this->parent($record, $field));
                $this->recalculate($this->previousParent($record, $field));
            }
        } elseif ($record->entity === 'players') {
            $this->recalculate($this->parent($record, 'team'));
            $this->recalculate($this->previousParent($record, 'team'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'fixtures') {
            $this->recalculate($this->parent($record, 'home_team'));
            $this->recalculate($this->parent($record, 'away_team'));
        } elseif ($record->entity === 'players') {
            $this->recalculate($this->parent($record, 'team'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'teams') {
            return $record->status === 'active'
                ? ['withdraw' => ['label' => 'Withdraw', 'icon' => 'log-out', 'confirm' => 'Withdraw '.$record->title.' from the league?']]
                : ['reinstate' => ['label' => 'Reinstate', 'icon' => 'shield']];
        }

        if ($record->entity === 'players') {
            $teams = $this->records('teams')->where('status', 'active')->whereKeyNot((int) $record->value('team'))->orderBy('title')->pluck('title', 'id')->all();
            $transfer = ['label' => 'Transfer', 'icon' => 'arrow-right-left', 'fields' => [['name' => 'team', 'label' => 'To team', 'type' => 'select', 'options' => $teams]]];

            return match ($record->status) {
                'registered' => ['suspend' => ['label' => 'Suspend', 'icon' => 'ban'], 'injure' => ['label' => 'Injured', 'icon' => 'bandage'], 'transfer' => $transfer],
                'suspended', 'injured' => ['clear' => ['label' => 'Available again', 'icon' => 'check'], 'transfer' => $transfer],
                default => [],
            };
        }

        return match ($record->status) {
            'scheduled' => [
                'record_result' => ['label' => 'Record result', 'icon' => 'trophy', 'fields' => [
                    ['name' => 'home_score', 'label' => 'Home score', 'type' => 'number', 'value' => $record->value('home_score') ?? 0],
                    ['name' => 'away_score', 'label' => 'Away score', 'type' => 'number', 'value' => $record->value('away_score') ?? 0],
                ]],
                'postpone' => ['label' => 'Postpone', 'icon' => 'calendar-clock', 'fields' => [['name' => 'occurs_on', 'label' => 'New date', 'type' => 'date', 'value' => $record->occurs_on?->addWeek()->toDateString() ?? today()->addWeek()->toDateString()]]],
                'abandon' => ['label' => 'Abandoned', 'icon' => 'x', 'confirm' => 'Mark this match abandoned?'],
            ],
            'postponed' => ['reschedule' => ['label' => 'Reschedule', 'icon' => 'calendar-plus', 'fields' => [['name' => 'occurs_on', 'label' => 'New date', 'type' => 'date', 'value' => today()->addWeek()->toDateString()]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'withdraw':
                $pending = $this->fixturesFor($record)->where('status', 'scheduled')->count();
                if ($pending > 0) {
                    throw ValidationException::withMessages(['status' => $record->title.' still has '.$pending.' fixtures to play.']);
                }
                $record->update(['status' => 'withdrawn']);

                return $record->title.' withdrew from the league.';
            case 'reinstate':
                $record->update(['status' => 'active']);

                return $record->title.' is back in the league.';
            case 'suspend':
                $record->update(['status' => 'suspended']);

                return $record->title.' is suspended.';
            case 'injure':
                $record->update(['status' => 'injured']);

                return $record->title.' is injured.';
            case 'clear':
                $record->update(['status' => 'registered']);

                return $record->title.' is available again.';
            case 'transfer':
                $team = $this->records('teams')->find((int) $request->validate(['team' => ['required', 'integer']])['team']);
                if (! $team || $team->status !== 'active' || $team->id === (int) $record->value('team')) {
                    throw ValidationException::withMessages(['team' => 'Choose another active team.']);
                }
                $from = $this->parent($record, 'team');
                $record->update(['status' => 'registered', 'data' => [...$record->data, 'team' => $team->id, 'jersey_number' => null]]);

                return $record->title.' transferred from '.($from?->title ?? 'no team').' to '.$team->title.'.';
            case 'record_result':
                $input = $request->validate(['home_score' => ['required', 'integer', 'min:0'], 'away_score' => ['required', 'integer', 'min:0']]);
                if ($record->occurs_on?->gt(today())) {
                    throw ValidationException::withMessages(['status' => 'The match has not been played yet.']);
                }
                $record->update(['status' => 'played', 'data' => [...$record->data, 'home_score' => (int) $input['home_score'], 'away_score' => (int) $input['away_score']]]);

                return ($this->parent($record, 'home_team')?->title ?? 'Home').' '.(int) $input['home_score'].'–'.(int) $input['away_score'].' '.($this->parent($record, 'away_team')?->title ?? 'Away').'.';
            case 'postpone':
            case 'reschedule':
                $day = Carbon::parse($request->validate(['occurs_on' => ['required', 'date', 'after_or_equal:today']])['occurs_on']);
                foreach (['home_team', 'away_team'] as $field) {
                    $team = $this->parent($record, $field);
                    if ($team && $this->fixturesFor($team)->where('status', 'scheduled')->whereDate('occurs_on', $day)->whereKeyNot($record->id)->exists()) {
                        throw ValidationException::withMessages(['occurs_on' => $team->title.' already plays on '.$day->format('d M Y').'.']);
                    }
                }
                $record->update(['status' => 'scheduled', 'occurs_on' => $day]);

                return $record->title.' moved to '.$day->format('d M Y').'.';
        }

        $record->update(['status' => 'abandoned']);

        return $record->title.' abandoned.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'teams') {
            return [];
        }

        $players = $this->linked('players', 'team', $record)->where('status', '!=', 'transferred')->get()->sortBy(fn (Record $player) => (int) ($player->value('jersey_number') ?: 999));
        $fixtures = $this->fixturesFor($record)->orderByDesc('occurs_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Team', 'icon' => 'shield', 'stats' => [
                ['label' => 'Played', 'value' => (string) (int) $record->value('_played')],
                ['label' => 'W-D-L', 'value' => (int) $record->value('_won').'-'.(int) $record->value('_drawn').'-'.(int) $record->value('_lost')],
                ['label' => 'Goals', 'value' => (int) $record->value('_goals_for').' for, '.(int) $record->value('_goals_against').' against'],
                ['label' => 'Points', 'value' => (string) (int) $record->value('_points'), 'tone' => 'success'],
                ['label' => 'Squad', 'value' => (int) $record->value('_players').' players', 'tone' => (int) $record->value('_unavailable') > 0 ? 'warning' : null],
                ['label' => 'Unavailable', 'value' => (string) (int) $record->value('_unavailable')],
                ['label' => 'Next match', 'value' => $record->value('_next_fixture') ? Carbon::parse($record->value('_next_fixture'))->format('d M Y') : 'None'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Squad', 'icon' => 'users', 'empty' => 'No players registered.',
                'rows' => $players->take(25)->map(fn (Record $player) => [
                    'label' => ($player->value('jersey_number') ? '#'.(int) $player->value('jersey_number').' ' : '').$player->title, 'sub' => implode(' · ', array_filter([$player->value('position'), $player->value('_age') ? $player->value('_age').' yrs' : null])), 'value' => ucfirst($player->status), 'href' => $player->url(), 'tone' => in_array($player->status, ['suspended', 'injured'], true) ? 'warning' : null,
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Fixtures', 'icon' => 'trophy', 'empty' => 'No fixtures yet.',
                'rows' => $fixtures->take(10)->map(fn (Record $fixture) => [
                    'label' => $fixture->title, 'sub' => $fixture->occurs_on?->format('d M Y').($fixture->value('venue') ? ' · '.$fixture->value('venue') : ''), 'value' => $fixture->status === 'played' ? $fixture->value('_result') : ucfirst($fixture->status), 'href' => $fixture->url(), 'tone' => $fixture->status === 'played' ? ($fixture->value('_winner') === $record->title ? 'success' : ($fixture->value('_winner') === 'Draw' ? null : 'danger')) : null,
                ])->values()->all(),
            ]],
        ];
    }

    /** Active teams ordered as a league table: points, then goal difference, then goals scored. */
    protected function table()
    {
        return $this->records('teams')->where('status', 'active')->get()->sortBy([
            fn (Record $a, Record $b) => (int) $b->value('_points') <=> (int) $a->value('_points'),
            fn (Record $a, Record $b) => (int) $b->value('_goal_difference') <=> (int) $a->value('_goal_difference'),
            fn (Record $a, Record $b) => (int) $b->value('_goals_for') <=> (int) $a->value('_goals_for'),
            fn (Record $a, Record $b) => strcmp($a->title, $b->title),
        ])->values();
    }

    public function homeCards(): array
    {
        $fixtures = $this->records('fixtures')->get();
        $week = $fixtures->filter(fn (Record $fixture) => $fixture->status === 'scheduled' && $fixture->occurs_on?->between(today(), today()->addDays(7)))->sortBy('occurs_on');
        $overdue = $fixtures->filter(fn (Record $fixture) => $fixture->status === 'scheduled' && $fixture->occurs_on?->lt(today()));
        $table = $this->table();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'League', 'icon' => 'trophy', 'stats' => [
                ['label' => 'Teams', 'value' => (string) $table->count()],
                ['label' => 'Players', 'value' => (string) $this->records('players')->where('status', '!=', 'transferred')->count()],
                ['label' => 'Matches played', 'value' => (string) $fixtures->where('status', 'played')->count()],
                ['label' => 'This week', 'value' => (string) $week->count()],
                ['label' => 'Results to enter', 'value' => (string) $overdue->count(), 'tone' => $overdue->isNotEmpty() ? 'warning' : null],
                ['label' => 'Leader', 'value' => $table->first()?->title ?? '—', 'tone' => 'success'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'League table', 'icon' => 'list-ordered', 'empty' => 'No active teams.',
                'rows' => $table->take(12)->values()->map(fn (Record $team, int $index) => [
                    'label' => ($index + 1).'. '.$team->title, 'sub' => 'P'.(int) $team->value('_played').' W'.(int) $team->value('_won').' D'.(int) $team->value('_drawn').' L'.(int) $team->value('_lost').' GD '.((int) $team->value('_goal_difference') >= 0 ? '+' : '').(int) $team->value('_goal_difference'), 'value' => (int) $team->value('_points').' pts', 'href' => $team->url(), 'tone' => $index === 0 ? 'success' : null,
                ])->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Fixtures this week', 'icon' => 'calendar-days', 'empty' => 'No matches this week.',
                'rows' => $week->take(10)->map(fn (Record $fixture) => [
                    'label' => $fixture->title, 'sub' => implode(' · ', array_filter([$fixture->value('venue'), $fixture->value('kick_off')])), 'value' => $fixture->occurs_on->format('D d M'), 'href' => $fixture->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $table = $this->table()->map(fn (Record $team, int $index) => [
            $index + 1, $team->title, $team->value('division') ?: '—', (int) $team->value('_played'), (int) $team->value('_won'), (int) $team->value('_drawn'), (int) $team->value('_lost'), (int) $team->value('_goals_for').'-'.(int) $team->value('_goals_against'), (int) $team->value('_goal_difference'), (int) $team->value('_points'),
        ])->all();

        $fixtures = $this->dated('fixtures', $from, $to)->get()->sortBy('occurs_on')->map(fn (Record $fixture) => [
            $fixture->occurs_on?->format('d M Y'), $fixture->title, $fixture->value('venue') ?: '—', ucfirst($fixture->status), $fixture->value('_result') ?? '—', $fixture->value('_winner') ?? '—',
        ])->values()->all();

        $teams = $this->records('teams')->get();
        $byTeam = $this->records('players')->get()->where('status', '!=', 'transferred')->groupBy(fn (Record $player) => (int) $player->value('team'))->map(fn ($group, int $teamId) => [
            $teams->firstWhere('id', $teamId)?->title ?? '—', $group->count(), $group->where('status', 'registered')->count(), $group->where('status', 'injured')->count(), $group->where('status', 'suspended')->count(), $group->filter(fn (Record $player) => $player->value('_age'))->avg(fn (Record $player) => (int) $player->value('_age')) ? round($group->filter(fn (Record $player) => $player->value('_age'))->avg(fn (Record $player) => (int) $player->value('_age')), 1) : '—',
        ])->sortBy(0)->values()->all();

        return [
            ['title' => 'League table', 'columns' => ['#', 'Team', 'Division', 'P', 'W', 'D', 'L', 'Goals', 'GD', 'Pts'], 'rows' => $table],
            ['title' => 'Fixtures', 'columns' => ['Date', 'Match', 'Venue', 'Status', 'Result', 'Winner'], 'rows' => $fixtures],
            ['title' => 'Players by team', 'columns' => ['Team', 'Players', 'Available', 'Injured', 'Suspended', 'Average age'], 'rows' => $byTeam],
        ];
    }
}
