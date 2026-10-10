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
 * Contracts & e-signature: a contract must end after it starts. Signing needs the signatory and the date
 * signed, which can't be in the future. A signed contract is active once it has started. When an active
 * contract reaches its end date, it renews for the same term if it auto-renews, and expires if not;
 * this is checked each night. Obligations only go on live contracts, and pending ones past their due
 * date are marked missed.
 */
class ContractLogic extends AppLogic
{
    /**
     * Contract statuses that still bind both sides.
     *
     * @var list<string>
     */
    public const LIVE = ['draft', 'sent', 'signed', 'active'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'obligations') {
            if (! $existing && filled($data['contract'] ?? null) && ($contract = $this->records('contracts')->find($data['contract'])) && ! in_array($contract->status, self::LIVE, true)) {
                $errors['data.contract'] = $contract->title.' is '.$contract->status.'.';
            }

            return $errors;
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lte(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The contract must end after it starts.';
        }
        if (in_array($payload['status'], ['signed', 'active'], true)) {
            if (blank($data['signatory'] ?? null)) {
                $errors['data.signatory'] = 'Give the name of the person who signed.';
            }
            if (blank($data['signed_on'] ?? null)) {
                $errors['data.signed_on'] = 'Give the date it was signed.';
            }
        }
        if (filled($data['signed_on'] ?? null) && Carbon::parse($data['signed_on'])->gt(today())) {
            $errors['data.signed_on'] = 'The signing date can\'t be in the future.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'obligations') {
            if ($record->status === 'pending' && $record->due_on && $record->due_on->lt(today())) {
                $record->status = 'missed';
            }

            return;
        }
        if ($record->status === 'signed' && (! $record->occurs_on || $record->occurs_on->lte(today()))) {
            $record->occurs_on ??= today();
            $record->status = 'active';
        }
        if ($record->status !== 'active' || ! $record->due_on || $record->due_on->gte(today())) {
            return;
        }
        if (! $record->value('auto_renew') || ! $record->occurs_on) {
            $record->status = 'expired';

            return;
        }
        $term = max(1, (int) $record->occurs_on->diffInDays($record->due_on));
        $renewals = (int) $this->number($record, '_renewals');
        while ($record->due_on->lt(today())) {
            $record->occurs_on = $record->due_on->copy()->addDay();
            $record->due_on = $record->occurs_on->copy()->addDays($term);
            $renewals++;
        }
        $this->put($record, ['_renewals' => $renewals]);
    }

    public function daily(Workspace $workspace): int
    {
        $contracts = $this->records('contracts')->whereIn('status', ['signed', 'active'])->whereDate('due_on', '<', today()->toDateString())->get()->each(fn (Record $contract) => $contract->save());
        $obligations = $this->records('obligations')->where('status', 'pending')->whereDate('due_on', '<', today()->toDateString())->get()->each(fn (Record $obligation) => $obligation->save());

        return $contracts->count() + $obligations->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'obligations') {
            return $record->status === 'pending' ? ['met' => ['label' => 'Met', 'icon' => 'check']] : [];
        }

        return match ($record->status) {
            'draft' => ['send' => ['label' => 'Sent for signature', 'icon' => 'send']],
            'sent' => ['sign' => ['label' => 'Signed', 'icon' => 'pen-tool', 'fields' => [
                ['name' => 'signatory', 'label' => 'Signed by', 'type' => 'text', 'value' => $record->value('signatory')],
                ['name' => 'signed_on', 'label' => 'Signed on', 'type' => 'date', 'value' => today()->toDateString()],
            ]]],
            'signed', 'active' => ['terminate' => ['label' => 'Terminate', 'icon' => 'ban']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'met':
                $record->update(['status' => 'met']);

                return $record->title.' met.';
            case 'send':
                $record->update(['status' => 'sent']);

                return $record->title.' sent for signature.';
            case 'sign':
                $input = $request->validate(['signatory' => ['nullable', 'string', 'max:255'], 'signed_on' => ['nullable', 'date', 'before_or_equal:today']]);
                $signatory = trim((string) ($input['signatory'] ?? '')) ?: $record->value('signatory');
                if (blank($signatory)) {
                    throw ValidationException::withMessages(['signatory' => 'Give the name of the person who signed.']);
                }
                $record->update(['status' => 'signed', 'data' => [...$record->data, 'signatory' => $signatory, 'signed_on' => $input['signed_on'] ?? today()->toDateString()]]);

                return $record->title.' signed by '.$signatory.($record->status === 'active' ? '; active'.($record->due_on ? ' until '.$record->due_on->format('d M Y') : '') : '; starts '.$record->occurs_on->format('d M Y')).'.';
            default:
                $record->update(['status' => 'terminated']);
                $open = $this->linked('obligations', 'contract', $record)->where('status', 'pending')->count();

                return $record->title.' terminated'.($open ? '; '.$open.' pending '.str('obligation')->plural($open).' still listed' : '').'.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'contracts') {
            return [];
        }
        $obligations = $this->linked('obligations', 'contract', $record)->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Obligations', 'icon' => 'list-checks', 'empty' => 'No obligations recorded.',
            'rows' => $obligations->map(fn (Record $obligation) => ['label' => $obligation->title, 'sub' => $obligation->value('owner') === 'us' ? 'Ours' : 'Theirs', 'value' => ucfirst($obligation->status).($obligation->due_on ? ' · '.$obligation->due_on->format('d M Y') : ''), 'href' => $obligation->url(), 'tone' => match ($obligation->status) {
                'missed' => 'danger', 'met' => 'success', default => null,
            }])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $ending = $this->records('contracts')->where('status', 'active')->whereDate('due_on', '<=', today()->addDays(60)->toDateString())->orderBy('due_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Ending in the next 60 days', 'icon' => 'calendar-clock', 'empty' => 'No contracts end in the next 60 days.',
                'rows' => $ending->map(fn (Record $contract) => ['label' => $contract->title, 'sub' => $contract->value('auto_renew') ? 'Auto-renews' : 'Expires unless renewed', 'value' => $contract->due_on->format('d M Y'), 'href' => $contract->url(), 'tone' => $contract->value('auto_renew') ? null : 'warning'])->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Contracts', 'icon' => 'pen-tool', 'stats' => [
                ['label' => 'Active', 'value' => $this->records('contracts')->where('status', 'active')->count()],
                ['label' => 'Awaiting signature', 'value' => $this->records('contracts')->where('status', 'sent')->count()],
                ['label' => 'Missed obligations', 'value' => $this->records('obligations')->where('status', 'missed')->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        return [['title' => 'Contracts by type', 'columns' => ['Type', 'Contracts', 'Active', 'Value'], 'rows' => $this->dated('contracts', $from, $to)->get()
            ->groupBy(fn (Record $contract) => ucfirst((string) $contract->value('type')))->sortKeys()
            ->map(fn ($group, string $type) => [$type === 'Nda' ? 'NDA' : $type, $group->count(), $group->where('status', 'active')->count(), $this->money($group->whereNotIn('status', ['draft', 'terminated'])->sum('amount'))])
            ->values()->all()]];
    }
}
