<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Print shop: a job needs a quantity of at least one. A proof can only be sent with the artwork link, and
 * nothing goes to print until the customer approves the proof and at least half the price is paid as a
 * deposit. A rejected proof goes back to artwork. Jobs then move through finishing to ready and collected.
 */
class PrintShopLogic extends AppLogic
{
    /**
     * Statuses that need an approved proof.
     *
     * @var list<string>
     */
    public const AFTER_APPROVAL = ['approved', 'printing', 'finishing', 'ready', 'collected'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ((int) ($data['quantity'] ?? 0) < 1) {
            $errors['data.quantity'] = 'The quantity must be at least 1.';
        }
        if (in_array($payload['status'], ['proof_sent', ...self::AFTER_APPROVAL], true) && blank($data['artwork_url'] ?? null)) {
            $errors['data.artwork_url'] = 'Add the artwork link first.';
        }
        if (in_array($payload['status'], self::AFTER_APPROVAL, true) && empty($data['proof_approved'])) {
            $errors['data.proof_approved'] = 'The customer must approve the proof first.';
        }
        if (in_array($payload['status'], ['printing', 'finishing', 'ready', 'collected'], true) && ($problem = $this->depositShort((float) ($payload['amount'] ?? 0), (float) ($data['deposit'] ?? 0)))) {
            $errors['data.deposit'] = $problem;
        }

        return $errors;
    }

    /**
     * Why the deposit isn't enough to start printing, if it isn't.
     */
    protected function depositShort(float $price, float $deposit): ?string
    {
        return $price > 0 && $deposit < $price / 2 ? 'Take a deposit of at least '.$this->money($price / 2).' before printing.' : null;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'quote' => ['accept' => ['label' => 'Quote accepted', 'icon' => 'check']],
            'artwork' => ['send_proof' => ['label' => 'Send proof', 'icon' => 'send', 'fields' => [['name' => 'artwork_url', 'label' => 'Artwork link', 'type' => 'url', 'value' => $record->value('artwork_url')]]]],
            'proof_sent' => ['approve' => ['label' => 'Proof approved', 'icon' => 'thumbs-up'], 'reject' => ['label' => 'Changes needed', 'icon' => 'thumbs-down']],
            'approved' => ['print' => ['label' => 'Print', 'icon' => 'printer', 'fields' => [['name' => 'deposit', 'label' => 'Deposit paid', 'type' => 'number', 'value' => $record->value('deposit')]]]],
            'printing' => ['finish' => ['label' => 'Finishing', 'icon' => 'scissors']],
            'finishing' => ['ready' => ['label' => 'Ready', 'icon' => 'package-check']],
            'ready' => ['collect' => ['label' => 'Collected', 'icon' => 'hand']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'accept':
                $record->update(['status' => 'artwork']);

                return $record->title.': quote accepted, on to artwork.';
            case 'send_proof':
                $link = $request->validate(['artwork_url' => ['required', 'url']], ['artwork_url.required' => 'Add the artwork link first.'])['artwork_url'];
                $record->update(['status' => 'proof_sent', 'data' => [...$record->data, 'artwork_url' => $link, 'proof_approved' => false]]);

                return 'Proof for '.$record->title.' sent.';
            case 'approve':
                $record->update(['status' => 'approved', 'data' => [...$record->data, 'proof_approved' => true]]);

                return 'Proof for '.$record->title.' approved.';
            case 'reject':
                $record->update(['status' => 'artwork', 'data' => [...$record->data, 'proof_approved' => false]]);

                return $record->title.' is back in artwork.';
            case 'print':
                $deposit = (float) ($request->validate(['deposit' => ['nullable', 'numeric', 'min:0']])['deposit'] ?? $this->number($record, 'deposit'));
                if ($problem = $this->depositShort((float) $record->amount, $deposit)) {
                    throw ValidationException::withMessages(['deposit' => $problem]);
                }
                $record->update(['status' => 'printing', 'data' => [...$record->data, 'deposit' => $deposit]]);

                return $record->title.' is printing.';
            case 'finish':
                $record->update(['status' => 'finishing']);

                return $record->title.' is in finishing.';
            case 'ready':
                $record->update(['status' => 'ready']);

                return $record->title.' is ready'.($record->due_on && $record->due_on->lt(today()) ? ', '.($late = (int) $record->due_on->diffInDays(today())).' '.str('day')->plural($late).' late' : '').'.';
            default:
                $balance = max(0, (float) $record->amount - $this->number($record, 'deposit'));
                $record->update(['status' => 'collected']);

                return $record->title.' collected'.($balance > 0 ? '; '.$this->money($balance).' balance taken' : '').'.';
        }
    }

    public function homeCards(): array
    {
        $open = $this->records('jobs')->whereNotIn('status', ['quote', 'collected'])->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Production', 'icon' => 'printer', 'stats' => [
                ['label' => 'In artwork', 'value' => $open->where('status', 'artwork')->count()],
                ['label' => 'Waiting on proof approval', 'value' => $open->where('status', 'proof_sent')->count()],
                ['label' => 'On the press', 'value' => $open->whereIn('status', ['approved', 'printing', 'finishing'])->count()],
                ['label' => 'Ready to collect', 'value' => $open->where('status', 'ready')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Due in the next 3 days', 'icon' => 'alarm-clock', 'empty' => 'Nothing due soon.',
                'rows' => $open->where('status', '!=', 'ready')->filter(fn (Record $job) => $job->due_on && $job->due_on->lte(today()->addDays(3)))->sortBy('due_on')
                    ->map(fn (Record $job) => ['label' => $job->title, 'sub' => str_replace('_', ' ', $job->status), 'value' => $job->due_on->format('d M'), 'href' => $job->url(), 'tone' => $job->due_on->lt(today()) ? 'danger' : 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        return [['title' => 'Jobs by product', 'columns' => ['Product', 'Jobs', 'Quantity', 'Collected', 'Value'], 'rows' => $this->dated('jobs', $from, $to)->where('status', '!=', 'quote')->get()
            ->groupBy(fn (Record $job) => ucfirst(str_replace('_', ' ', (string) ($job->value('product') ?: 'other'))))->sortKeys()
            ->map(fn ($group, string $product) => [$product, $group->count(), (int) $group->sum(fn (Record $job) => $this->number($job, 'quantity')), $group->where('status', 'collected')->count(), $this->money($group->sum('amount'))])
            ->values()->all()]];
    }
}
