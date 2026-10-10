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
 * Compliance & audits: an obligation's status follows its expiry date — compliant, due soon within
 * 30 days, lapsed once it has passed — and is brought up to date each morning. Renewing records the
 * new expiry and the fee paid. Findings are given a fix-by date from their severity when none is set,
 * and are closed only once the corrective action is written down.
 */
class ComplianceLogic extends AppLogic
{
    /**
     * Days before expiry that an obligation counts as due soon.
     */
    public const WARNING_DAYS = 30;

    /**
     * Days to fix a finding by severity, when no date is given.
     *
     * @var array<string, int>
     */
    public const FIX_DAYS = ['critical' => 7, 'high' => 30, 'medium' => 60, 'low' => 90];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $errors = [];
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = $entity->key === 'obligations' ? 'It cannot expire before it was issued.' : 'The fix-by date cannot be before the finding was raised.';
        }
        if ($entity->key === 'findings' && $payload['status'] === 'closed' && blank($payload['data']['corrective_action'] ?? null)) {
            $errors['data.corrective_action'] = 'Write down the corrective action before closing the finding.';
        }
        if ($entity->key === 'obligations' && (float) ($payload['amount'] ?? 0) < 0) {
            $errors['amount'] = 'The renewal fee cannot be negative.';
        }

        return $errors;
    }

    /**
     * An obligation's status for its expiry date.
     */
    public function standing(?Carbon $expires): string
    {
        return match (true) {
            ! $expires => 'compliant',
            $expires->lt(today()) => 'lapsed',
            $expires->lte(today()->addDays(self::WARNING_DAYS)) => 'due_soon',
            default => 'compliant',
        };
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'obligations') {
            $record->status = $this->standing($record->due_on);

            return;
        }
        if (! $record->due_on && ($days = self::FIX_DAYS[$record->value('severity')] ?? null)) {
            $record->due_on = $record->occurs_on->copy()->addDays($days);
        }
        if ($record->isDirty('status') && $record->status === 'closed') {
            $this->put($record, ['_closed_on' => today()->toDateString(), '_days_to_close' => (int) $record->occurs_on->diffInDays(today())]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'obligations') {
            return ['renew' => ['label' => 'Renewed', 'icon' => 'refresh-cw', 'fields' => [
                ['name' => 'expires_on', 'label' => 'New expiry date', 'type' => 'date'],
                ['name' => 'fee', 'label' => 'Fee paid', 'type' => 'number', 'value' => $record->amount],
                ['name' => 'reference', 'label' => 'New licence number', 'type' => 'text', 'value' => $record->value('reference')],
            ]]];
        }

        return match ($record->status) {
            'open' => ['start' => ['label' => 'Start fixing', 'icon' => 'play'], 'close' => $this->closeAction($record)],
            'in_progress' => ['close' => $this->closeAction($record)],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function closeAction(Record $record): array
    {
        return ['label' => 'Close', 'icon' => 'check', 'fields' => [['name' => 'corrective_action', 'label' => 'Corrective action taken', 'type' => 'text', 'value' => $record->value('corrective_action')]]];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'renew':
                $values = $request->validate(['expires_on' => ['required', 'date', 'after:today'], 'fee' => ['nullable', 'numeric', 'min:0'], 'reference' => ['nullable', 'string', 'max:255']]);
                $fee = (float) ($values['fee'] ?? 0);
                $record->update(['occurs_on' => today(), 'due_on' => Carbon::parse($values['expires_on']), 'amount' => $fee ?: $record->amount, 'data' => [...$record->data,
                    'reference' => filled($values['reference'] ?? null) ? $values['reference'] : $record->value('reference'),
                    '_renewals' => (int) $record->value('_renewals') + 1,
                    '_fees_paid' => round($this->number($record, '_fees_paid') + $fee, 2),
                ]]);

                return $record->title.' renewed until '.$record->due_on->format('d M Y').'.';
            case 'start':
                $record->update(['status' => 'in_progress']);

                return $record->title.' is being fixed.';
            default:
                $taken = trim((string) ($request->validate(['corrective_action' => ['nullable', 'string', 'max:2000']])['corrective_action'] ?? ''));
                if ($taken === '') {
                    throw ValidationException::withMessages(['corrective_action' => 'Write down the corrective action before closing the finding.']);
                }
                $record->update(['status' => 'closed', 'data' => [...$record->data, 'corrective_action' => $taken]]);

                return $record->title.' closed after '.$record->value('_days_to_close').' '.str('day')->plural((int) $record->value('_days_to_close')).'.';
        }
    }

    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        foreach ($this->records('obligations')->get() as $obligation) {
            if ($obligation->status !== $this->standing($obligation->due_on)) {
                $obligation->save();
                $changed++;
            }
        }

        return $changed;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'obligations') {
            return [];
        }
        $findings = $this->linked('findings', 'obligation', $record)->get();
        $days = $record->due_on ? (int) today()->diffInDays($record->due_on, false) : null;

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Standing', 'icon' => 'file-check-2', 'stats' => [
            ['label' => 'Expires', 'value' => $days === null ? 'No expiry' : ($days < 0 ? 'Lapsed '.abs($days).' '.str('day')->plural(abs($days)).' ago' : 'In '.$days.' '.str('day')->plural($days)), 'tone' => match ($record->status) {
                'lapsed' => 'danger', 'due_soon' => 'warning', default => 'success',
            }],
            ['label' => 'Open findings', 'value' => $findings->where('status', '!=', 'closed')->count()],
            ['label' => 'Renewals', 'value' => (int) $record->value('_renewals')],
            ['label' => 'Fees paid', 'value' => $this->money($this->number($record, '_fees_paid'))],
        ]]]];
    }

    public function homeCards(): array
    {
        $attention = $this->records('obligations')->whereIn('status', ['lapsed', 'due_soon'])->orderBy('due_on')->get();
        $overdue = $this->records('findings')->where('status', '!=', 'closed')->whereNotNull('due_on')->whereDate('due_on', '<', today()->toDateString())->orderBy('due_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Renewals', 'icon' => 'file-check-2', 'empty' => 'Everything is in date.',
                'rows' => $attention->map(fn (Record $obligation) => ['label' => $obligation->title, 'sub' => $obligation->value('authority'), 'value' => $obligation->due_on?->format('d M Y'), 'href' => $obligation->url(), 'tone' => $obligation->status === 'lapsed' ? 'danger' : 'warning'])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Findings past their fix-by date', 'icon' => 'search-check', 'empty' => 'No overdue findings.',
                'rows' => $overdue->map(fn (Record $finding) => ['label' => $finding->title, 'sub' => ucfirst((string) $finding->value('severity')), 'value' => $finding->due_on->format('d M'), 'href' => $finding->url(), 'tone' => 'danger'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $obligations = $this->records('obligations')->orderBy('due_on')->get();
        $findings = $this->dated('findings', $from, $to)->get();

        return [
            ['title' => 'Obligations', 'columns' => ['Obligation', 'Authority', 'Licence number', 'Expires', 'Status', 'Renewals', 'Fees paid'], 'rows' => $obligations->map(fn (Record $obligation) => [
                $obligation->title, (string) $obligation->value('authority'), (string) $obligation->value('reference'), $obligation->due_on?->format('d M Y') ?? '—',
                ucfirst(str_replace('_', ' ', $obligation->status)), (int) $obligation->value('_renewals'), $this->money($this->number($obligation, '_fees_paid')),
            ])->values()->all()],
            ['title' => 'Findings by severity', 'columns' => ['Severity', 'Raised', 'Closed', 'Still open', 'Overdue', 'Average days to close'], 'rows' => collect(array_keys(self::FIX_DAYS))
                ->map(function (string $severity) use ($findings) {
                    $group = $findings->filter(fn (Record $finding) => $finding->value('severity') === $severity);
                    $closed = $group->where('status', 'closed');

                    return [ucfirst($severity), $group->count(), $closed->count(), $group->where('status', '!=', 'closed')->count(),
                        $group->filter(fn (Record $finding) => $finding->status !== 'closed' && $finding->due_on && $finding->due_on->lt(today()))->count(),
                        $closed->isNotEmpty() ? number_format($closed->avg(fn (Record $finding) => (int) $finding->value('_days_to_close')), 1) : '—'];
                })->filter(fn (array $row) => $row[1] > 0)->values()->all()],
        ];
    }
}
