<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Library: a book is lent only while it has a free copy, a borrower holds at most a few books at a
 * time, loans fall due two weeks out by default and become overdue by themselves, and returning a
 * book late charges a fine per day. Books show as on loan when every copy is out.
 */
class LibraryLogic extends AppLogic
{
    public const LOAN_DAYS = 14;

    public const MAX_LOANS = 3;

    /** Fine per day overdue, in the workspace currency. */
    public const FINE_PER_DAY = 1;

    public const OPEN = ['on_loan', 'overdue'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'books') {
            if ((int) ($data['copies'] ?? 1) < 1) {
                $errors['data.copies'] = 'A book has at least one copy.';
            }
            if ($existing && in_array($payload['status'], ['withdrawn', 'lost'], true) && $this->linked('loans', 'book', $existing)->whereIn('status', self::OPEN)->exists()) {
                $errors['status'] = 'Copies of this book are still out on loan.';
            }

            return $errors;
        }

        $book = ! empty($data['book']) ? $this->records('books')->find($data['book']) : null;
        $opening = in_array($payload['status'], self::OPEN, true) && (! $existing || ! in_array($existing->status, self::OPEN, true) || (int) $existing->value('book') !== $book?->id);
        if ($book && $opening) {
            if (in_array($book->status, ['withdrawn', 'lost'], true)) {
                $errors['data.book'] = $book->title.' is '.$book->status.' and cannot be lent.';
            } elseif ($this->freeCopies($book) < 1) {
                $errors['data.book'] = 'Every copy of '.$book->title.' is out on loan.';
            }
        }
        if ($opening && filled($payload['contact_id'] ?? null) && $this->records('loans')->whereIn('status', self::OPEN)->where('contact_id', $payload['contact_id'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->count() >= self::MAX_LOANS) {
            $errors['contact_id'] = 'This borrower already has '.self::MAX_LOANS.' books out.';
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The due date cannot be before the loan date.';
        }
        if ($payload['status'] === 'returned' && blank($data['returned_on'] ?? null)) {
            $errors['data.returned_on'] = 'Record the date the book came back.';
        }

        return $errors;
    }

    protected function freeCopies(Record $book): int
    {
        return max(0, (int) ($book->value('copies') ?: 1) - $this->linked('loans', 'book', $book)->whereIn('status', self::OPEN)->count());
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'books') {
            $out = $record->exists ? $this->linked('loans', 'book', $record)->whereIn('status', self::OPEN)->count() : 0;
            $this->put($record, ['_out' => $out]);
            if (! in_array($record->status, ['withdrawn', 'lost'], true)) {
                $record->status = $out >= (int) ($record->value('copies') ?: 1) ? 'on_loan' : 'available';
            }

            return;
        }

        $record->occurs_on ??= today();
        $record->due_on ??= $record->occurs_on->copy()->addDays(self::LOAN_DAYS);
        if ($record->status === 'returned' && $record->value('returned_on') && $record->due_on) {
            $late = (int) $record->due_on->copy()->startOfDay()->diffInDays(Carbon::parse($record->value('returned_on'))->startOfDay(), false);
            $this->put($record, ['fine' => max(0, $late) * self::FINE_PER_DAY]);
        } elseif (in_array($record->status, self::OPEN, true)) {
            $record->status = $record->due_on->lt(today()) ? 'overdue' : 'on_loan';
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'loans') {
            $this->recalculate($this->parent($record, 'book'));
            $this->recalculate($this->previousParent($record, 'book'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'loans') {
            $this->recalculate($this->parent($record, 'book'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $count = 0;
        foreach ($this->records('loans')->where('status', 'on_loan')->whereDate('due_on', '<', today())->get() as $loan) {
            $loan->update(['status' => 'overdue']);
            $count++;
        }

        return $count;
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'loans' || ! in_array($record->status, self::OPEN, true)) {
            return [];
        }

        return [
            'return_book' => ['label' => 'Returned', 'icon' => 'book-check', 'fields' => [
                ['name' => 'returned_on', 'label' => 'Returned on', 'type' => 'date', 'value' => today()->toDateString()],
            ]],
            'mark_lost' => ['label' => 'Lost', 'icon' => 'book-x', 'confirm' => 'Mark this copy lost? The borrower owes its replacement.'],
        ];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'mark_lost') {
            $record->update(['status' => 'lost']);
            $book = $this->parent($record, 'book');
            if ($book && (int) ($book->value('copies') ?: 1) <= 1) {
                $book->update(['status' => 'lost']);
            }

            return $record->title.' has lost '.($book?->title ?? 'the book').'.';
        }

        $returnedOn = $request->validate(['returned_on' => ['required', 'date']])['returned_on'];
        $record->update(['status' => 'returned', 'data' => [...(array) $record->data, 'returned_on' => Carbon::parse($returnedOn)->toDateString()]]);
        $fine = $this->number($record->fresh(), 'fine');

        return 'Book returned.'.($fine > 0 ? ' Fine owing: '.$this->money($fine).'.' : '');
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'books') {
            return [];
        }

        $loans = $this->linked('loans', 'book', $record)->with('contact')->orderByDesc('occurs_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Copies', 'icon' => 'book', 'stats' => [
                ['label' => 'Copies', 'value' => (string) (int) ($record->value('copies') ?: 1)],
                ['label' => 'On loan', 'value' => (string) (int) $record->value('_out')],
                ['label' => 'Available', 'value' => (string) $this->freeCopies($record), 'tone' => $this->freeCopies($record) > 0 ? 'success' : 'warning'],
                ['label' => 'Times borrowed', 'value' => (string) $loans->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Loans', 'icon' => 'book-open', 'empty' => 'Never borrowed.',
                'rows' => $loans->take(10)->map(fn (Record $loan) => [
                    'label' => $loan->title, 'sub' => $loan->occurs_on?->format('d M Y').' → '.$loan->due_on?->format('d M Y'), 'value' => ucfirst(str_replace('_', ' ', $loan->status)), 'href' => $loan->url(),
                    'tone' => $loan->status === 'overdue' ? 'danger' : ($loan->status === 'on_loan' ? 'warning' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $books = $this->records('books')->get();
        $loans = $this->records('loans')->get();
        $overdue = $loans->where('status', 'overdue')->sortBy('due_on');
        $fines = $loans->where('status', 'returned')->sum(fn (Record $loan) => $this->number($loan, 'fine'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Library', 'icon' => 'library', 'stats' => [
                ['label' => 'Titles', 'value' => (string) $books->whereNotIn('status', ['withdrawn', 'lost'])->count()],
                ['label' => 'Out on loan', 'value' => (string) $loans->whereIn('status', self::OPEN)->count()],
                ['label' => 'Overdue', 'value' => (string) $overdue->count(), 'tone' => $overdue->isNotEmpty() ? 'danger' : null],
                ['label' => 'Fines charged', 'value' => $this->money($fines)],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Overdue loans', 'icon' => 'clock-alert', 'empty' => 'Nothing is overdue.',
                'rows' => $overdue->take(10)->map(fn (Record $loan) => [
                    'label' => $loan->title, 'sub' => $books->firstWhere('id', (int) $loan->value('book'))?->title, 'value' => (int) $loan->due_on->diffInDays(today()).' days late', 'href' => $loan->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $books = $this->records('books')->get()->keyBy('id');
        $loans = $this->dated('loans', $from, $to)->with('contact')->get();
        $categories = $loans->groupBy(fn (Record $loan) => $books[(int) $loan->value('book')]?->value('category') ?: 'Uncategorised')->sortKeys()->map(fn ($group, $category) => [
            $category, $group->count(), $group->where('status', 'returned')->count(), $group->whereIn('status', self::OPEN)->count(), $group->where('status', 'lost')->count(),
        ])->values()->all();

        $popular = $loans->groupBy(fn (Record $loan) => (int) $loan->value('book'))->map(fn ($group, $id) => [$books[$id]?->title ?? 'Unknown', $books[$id]?->value('author') ?? '—', $group->count()])
            ->sortByDesc(2)->take(15)->values()->all();

        $fines = $loans->filter(fn (Record $loan) => $this->number($loan, 'fine') > 0)->groupBy(fn (Record $loan) => $loan->contact?->name ?? $loan->title)->sortKeys()->map(fn ($group, $borrower) => [
            $borrower, $group->count(), $this->money($group->sum(fn (Record $loan) => $this->number($loan, 'fine'))),
        ])->values()->all();

        return [
            ['title' => 'Loans by category', 'columns' => ['Category', 'Loans', 'Returned', 'Out', 'Lost'], 'rows' => $categories],
            ['title' => 'Most borrowed', 'columns' => ['Title', 'Author', 'Loans'], 'rows' => $popular],
            ['title' => 'Fines', 'columns' => ['Borrower', 'Late returns', 'Fines'], 'rows' => $fines],
        ];
    }
}
