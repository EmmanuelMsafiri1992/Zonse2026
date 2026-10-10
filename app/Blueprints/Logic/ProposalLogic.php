<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Proposals & sales documents: a proposal must be priced before it goes out. Sending it dates it today
 * and keeps it valid for 30 days unless a date is set. Sent and viewed proposals past that date expire
 * each night and can't be accepted. A template can start a new draft proposal with its text as the scope.
 */
class ProposalLogic extends AppLogic
{
    public const VALID_DAYS = 30;

    /**
     * Proposal statuses still waiting on the client.
     *
     * @var list<string>
     */
    public const OUT = ['sent', 'viewed'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'proposals') {
            return [];
        }
        $errors = [];
        if (! in_array($payload['status'], ['draft', 'declined'], true) && (float) ($payload['amount'] ?? 0) <= 0) {
            $errors['amount'] = 'Price the proposal before it goes out.';
        }
        if ($payload['status'] === 'accepted' && $existing?->status !== 'accepted' && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(today())) {
            $errors['status'] = 'This proposal expired on '.Carbon::parse($payload['due_on'])->format('d M Y').'; send a new one.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'proposals' || $record->status === 'draft') {
            return;
        }
        $record->occurs_on ??= today();
        $record->due_on ??= $record->occurs_on->copy()->addDays(self::VALID_DAYS);
        if (in_array($record->status, self::OUT, true) && $record->due_on->lt(today())) {
            $record->status = 'expired';
        }
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('proposals')->whereIn('status', self::OUT)->whereDate('due_on', '<', today()->toDateString())->get()
            ->each(fn (Record $proposal) => $proposal->save())->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'templates') {
            return $record->status === 'active' ? ['use' => ['label' => 'Start a proposal', 'icon' => 'file-plus', 'fields' => [['name' => 'title', 'label' => 'Proposal title', 'type' => 'text', 'value' => '']]]] : [];
        }
        $close = ['accept' => ['label' => 'Accepted', 'icon' => 'check'], 'decline' => ['label' => 'Declined', 'icon' => 'x']];

        return match ($record->status) {
            'draft' => ['send' => ['label' => 'Send', 'icon' => 'send']],
            'sent' => ['viewed' => ['label' => 'Client viewed it', 'icon' => 'eye'], ...$close],
            'viewed' => $close,
            'expired', 'declined' => ['send' => ['label' => 'Send again', 'icon' => 'send']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'use':
                $title = trim((string) ($request->validate(['title' => ['required', 'string', 'max:255']])['title']));
                $proposal = Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'proposals',
                    'title' => $title, 'status' => 'draft', 'data' => ['scope' => $record->value('body')],
                ]);

                return 'Draft proposal '.$proposal->title.' started from '.$record->title.'.';
            case 'send':
                if ((float) $record->amount <= 0) {
                    throw ValidationException::withMessages(['amount' => 'Price the proposal before it goes out.']);
                }
                $record->update(['status' => 'sent', 'occurs_on' => today(), 'due_on' => today()->addDays(self::VALID_DAYS)]);

                return $record->title.' sent; valid until '.$record->due_on->format('d M Y').'.';
            case 'viewed':
                $record->update(['status' => 'viewed']);

                return $record->title.' was viewed.';
            case 'accept':
                if ($record->due_on && $record->due_on->lt(today())) {
                    throw ValidationException::withMessages(['status' => 'This proposal expired on '.$record->due_on->format('d M Y').'; send a new one.']);
                }
                $record->update(['status' => 'accepted']);

                return $record->title.' accepted for '.$this->money($record->amount).'.';
            default:
                $record->update(['status' => 'declined']);

                return $record->title.' declined.';
        }
    }

    public function homeCards(): array
    {
        $out = $this->records('proposals')->whereIn('status', self::OUT)->orderBy('due_on')->get();
        $decided = $this->records('proposals')->whereIn('status', ['accepted', 'declined', 'expired'])->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Proposals', 'icon' => 'file-text', 'stats' => [
                ['label' => 'Out with clients', 'value' => $out->count()],
                ['label' => 'Value out', 'value' => $this->money($out->sum('amount'))],
                ['label' => 'Win rate', 'value' => $decided->isNotEmpty() ? round($decided->where('status', 'accepted')->count() / $decided->count() * 100).'%' : '—'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Expiring in 7 days', 'icon' => 'hourglass', 'empty' => 'No proposals expire this week.',
                'rows' => $out->filter(fn (Record $proposal) => $proposal->due_on && $proposal->due_on->lte(today()->addDays(7)))->map(fn (Record $proposal) => ['label' => $proposal->title, 'sub' => ucfirst($proposal->status), 'value' => $proposal->due_on->format('d M'), 'href' => $proposal->url(), 'tone' => 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $proposals = $this->dated('proposals', $from, $to)->where('status', '!=', 'draft')->get();

        return [['title' => 'Proposals by month', 'columns' => ['Month', 'Sent', 'Accepted', 'Win rate', 'Value won'], 'rows' => collect($this->months($from, $to))
            ->map(function (string $label, string $month) use ($proposals) {
                $inMonth = $proposals->filter(fn (Record $proposal) => $proposal->occurs_on?->format('Y-m') === $month);
                $won = $inMonth->where('status', 'accepted');

                return [$label, $inMonth->count(), $won->count(), $inMonth->isNotEmpty() ? round($won->count() / $inMonth->count() * 100).'%' : '—', $this->money($won->sum('amount'))];
            })->values()->all()]];
    }
}
