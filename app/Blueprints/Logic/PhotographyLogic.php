<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Photography studio: a shoot is booked once a deposit is paid, and the deposit can't be more than the
 * package price. A photographer can't be booked for two shoots on the same day. Galleries are due 14 days
 * after a shoot, or six weeks for weddings, and a shoot is only delivered with a gallery link.
 */
class PhotographyLogic extends AppLogic
{
    /**
     * Shoot statuses where the photographer is committed.
     *
     * @var list<string>
     */
    public const BOOKED = ['booked', 'shot', 'editing'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        $deposit = (float) ($data['deposit_paid'] ?? 0);
        if ($deposit > (float) ($payload['amount'] ?? 0) && (float) ($payload['amount'] ?? 0) > 0) {
            $errors['data.deposit_paid'] = 'The deposit can\'t be more than the package price.';
        }
        if ($payload['status'] === 'booked' && $deposit <= 0) {
            $errors['data.deposit_paid'] = 'Take a deposit to book the shoot.';
        }
        if ($payload['status'] === 'delivered' && blank($data['gallery_link'] ?? null)) {
            $errors['data.gallery_link'] = 'Add the gallery link to deliver the shoot.';
        }
        if (in_array($payload['status'], self::BOOKED, true) && filled($payload['assignee_id'] ?? null) && filled($payload['occurs_on'] ?? null)) {
            $clash = $this->records('shoots')->whereIn('status', self::BOOKED)->where('assignee_id', $payload['assignee_id'])
                ->whereDate('occurs_on', Carbon::parse($payload['occurs_on'])->toDateString())
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first();
            if ($clash) {
                $errors['assignee_id'] = User::query()->whereKey($payload['assignee_id'])->value('name').' is already shooting '.$clash->title.' that day.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->occurs_on && (! $record->due_on || ($record->exists && $record->isDirty('occurs_on') && ! $record->isDirty('due_on')))) {
            $record->due_on = $record->occurs_on->copy()->addDays($record->value('type') === 'wedding' ? 42 : 14);
        }
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'enquiry' => ['book' => ['label' => 'Book', 'icon' => 'calendar-check', 'fields' => [['name' => 'deposit', 'label' => 'Deposit paid', 'type' => 'number', 'value' => '']]]],
            'booked' => ['shot' => ['label' => 'Shot', 'icon' => 'camera']],
            'shot' => ['edit' => ['label' => 'Start editing', 'icon' => 'image']],
            'editing' => ['deliver' => ['label' => 'Deliver', 'icon' => 'send', 'fields' => [['name' => 'gallery_link', 'label' => 'Gallery link', 'type' => 'url', 'value' => $record->value('gallery_link')]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'book':
                $max = $record->amount > 0 ? '|max:'.$record->amount : '';
                $deposit = (float) $request->validate(['deposit' => 'required|numeric|gt:0'.$max], ['deposit.required' => 'Take a deposit to book the shoot.', 'deposit.max' => 'The deposit can\'t be more than the package price.'])['deposit'];
                $record->update(['status' => 'booked', 'data' => [...$record->data, 'deposit_paid' => $deposit]]);

                return $record->title.' booked with a '.$this->money($deposit).' deposit'.($record->amount > 0 ? '; '.$this->money($record->amount - $deposit).' to pay' : '').'.';
            case 'shot':
                $record->update(['status' => 'shot']);

                return $record->title.' shot; gallery due '.$record->due_on?->format('d M Y').'.';
            case 'edit':
                $record->update(['status' => 'editing']);

                return $record->title.' in editing.';
            default:
                $link = $request->validate(['gallery_link' => ['required', 'url']], ['gallery_link.required' => 'Add the gallery link to deliver the shoot.'])['gallery_link'];
                $record->update(['status' => 'delivered', 'data' => [...$record->data, 'gallery_link' => $link]]);

                return $record->title.' delivered'.($record->due_on && $record->due_on->lt(today()) ? ', '.($late = (int) $record->due_on->diffInDays(today())).' '.str('day')->plural($late).' late' : ' on time').'.';
        }
    }

    public function recordCards(Record $record): array
    {
        $deposit = $this->number($record, 'deposit_paid');

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Money', 'icon' => 'banknote', 'stats' => [
            ['label' => 'Package', 'value' => $this->money($record->amount)],
            ['label' => 'Deposit paid', 'value' => $this->money($deposit)],
            ['label' => 'Balance', 'value' => $this->money(max(0, (float) $record->amount - $deposit))],
        ]]]];
    }

    public function homeCards(): array
    {
        $shoots = $this->records('shoots')->whereIn('status', self::BOOKED)->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Shoots in the next 14 days', 'icon' => 'camera', 'empty' => 'No shoots coming up.',
                'rows' => $shoots->where('status', 'booked')->filter(fn (Record $shoot) => $shoot->occurs_on && $shoot->occurs_on->between(today(), today()->addDays(14)))->sortBy('occurs_on')
                    ->map(fn (Record $shoot) => ['label' => $shoot->title, 'sub' => trim(ucfirst((string) $shoot->value('type')).' · '.$shoot->value('location'), ' ·'), 'value' => $shoot->occurs_on->format('d M'), 'href' => $shoot->url()])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Galleries due', 'icon' => 'image', 'empty' => 'No galleries waiting.',
                'rows' => $shoots->whereIn('status', ['shot', 'editing'])->sortBy('due_on')
                    ->map(fn (Record $shoot) => ['label' => $shoot->title, 'sub' => $shoot->status, 'value' => $shoot->due_on?->format('d M') ?? '—', 'href' => $shoot->url(), 'tone' => $shoot->due_on?->lt(today()) ? 'danger' : null])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $shoots = $this->dated('shoots', $from, $to)->whereNotIn('status', ['enquiry', 'cancelled'])->get();

        return [['title' => 'Shoots by type', 'columns' => ['Type', 'Shoots', 'Delivered', 'Bookings', 'Deposits taken', 'Balance to collect'], 'rows' => $shoots
            ->groupBy(fn (Record $shoot) => ucfirst((string) ($shoot->value('type') ?: 'other')))->sortKeys()
            ->map(fn ($group, string $type) => [
                $type, $group->count(), $group->where('status', 'delivered')->count(), $this->money($group->sum('amount')),
                $this->money($group->sum(fn (Record $shoot) => $this->number($shoot, 'deposit_paid'))),
                $this->money($group->sum(fn (Record $shoot) => max(0, (float) $shoot->amount - $this->number($shoot, 'deposit_paid')))),
            ])->values()->all()]];
    }
}
