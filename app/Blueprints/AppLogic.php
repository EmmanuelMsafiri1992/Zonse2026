<?php

namespace App\Blueprints;

use App\Models\Record;
use App\Models\Workspace;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;

/**
 * Behaviour on top of a blueprint app's generic screens. The base class is what every
 * app gets for free (one-line invoices, status sync from payments, generic reports);
 * apps that need more (clinic, school, rentals…) extend it and override the hooks.
 *
 * Card hooks return view partials: list<array{view: string, data: array<string, mixed>}>.
 * Report hooks return tables: list<array{title: string, columns: list<string>, rows: list<list<string|int|float>>, note?: string}>.
 */
class AppLogic
{
    protected Blueprint $app;

    public function forApp(Blueprint $app): static
    {
        $this->app = $app;

        return $this;
    }

    public function app(): Blueprint
    {
        return $this->app;
    }

    // ----- Record lifecycle ------------------------------------------------------

    /** Fill derived values before a record is saved (e.g. price from the chosen service). */
    public function saving(Record $record): void {}

    /** Side effects after a record is saved (e.g. mark a unit occupied when its lease starts). */
    public function saved(Record $record): void {}

    /**
     * Business rules the field definitions cannot express. Return field => message for anything wrong.
     *
     * @param  array<string, mixed>  $payload  attributes about to be saved (data already cast)
     * @return array<string, string>
     */
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        return [];
    }

    // ----- Billing ------------------------------------------------------------------

    /**
     * Invoice lines for a record. The default bills the record's amount under its title.
     *
     * @return list<array{description: string, quantity: float|int, unit_price: float|int|string, item_id?: int|null}>
     */
    public function invoiceLines(Record $record): array
    {
        $def = $record->definition();

        return [[
            'description' => $def->label.' '.$record->number.' · '.$record->title,
            'quantity' => 1,
            'unit_price' => (float) $record->amount,
        ]];
    }

    /** The record whose contact pays (a visit is paid by its patient). Defaults to the record itself or its "via" field. */
    public function payer(Record $record): Record
    {
        $via = $record->definition()->billing['via'] ?? null;

        return ($via ? $record->related($via) : null) ?? $record;
    }

    /** A fixed contact to bill instead of looking one up (the point of sale's walk-in customer). */
    public function billingContact(Record $record): ?Contact
    {
        return null;
    }

    /**
     * Name and phone for the contact made when a paying record has none yet.
     *
     * @return array{name: string, phone: ?string}
     */
    public function newContactDetails(Record $payer): array
    {
        return ['name' => (string) $payer->title, 'phone' => $payer->value('phone')];
    }

    /** Keep the record's status in step with its invoice once payments come in. */
    public function invoiceChanged(Record $record, Invoice $invoice): void
    {
        $map = $record->definition()->billing ?? [];
        $status = $map[$invoice->status] ?? null;

        if ($status && $status !== $record->status && isset($record->definition()->statuses[$status])) {
            $record->update(['status' => $status]);
        }
    }

    // ----- Screens ------------------------------------------------------------------

    /** Extra cards on a record's page. @return list<array{view: string, data: array<string, mixed>}> */
    public function recordCards(Record $record): array
    {
        return [];
    }

    /** Extra cards at the top of the app's home page. @return list<array{view: string, data: array<string, mixed>}> */
    public function homeCards(): array
    {
        return [];
    }

    /**
     * One-click actions offered on a record's page.
     *
     * @return array<string, array{label: string, icon: string, confirm?: string, fields?: list<array{name: string, label: string, type: string, value?: mixed, options?: array<string, string>}>}>
     */
    public function actions(Record $record): array
    {
        return [];
    }

    /** Run an action offered by actions(); returns the flash message. */
    public function runAction(string $action, Record $record, Request $request): string
    {
        abort(404);
    }

    /** Printable documents for a record (statement, receipt…). @return array<string, string> key => label */
    public function documents(Record $record): array
    {
        return [];
    }

    /** @return array{view: string, data: array<string, mixed>}|null */
    public function document(string $name, Record $record): ?array
    {
        return null;
    }

    // ----- Reports & schedules ---------------------------------------------------

    /** @return list<array{title: string, columns: list<string>, rows: list<list<string|int|float>>, note?: string}> */
    public function reports(Carbon $from, Carbon $to): array
    {
        $sections = [];

        foreach ($this->app->entities as $entity) {
            $base = Record::query()->ofEntity($this->app->key, $entity->key);

            $byStatus = (clone $base)->selectRaw('status, count(*) as total, sum(amount) as amount')
                ->whereBetween('created_at', [$from, $to])->groupBy('status')->get()->keyBy('status');
            if ($byStatus->isEmpty()) {
                continue;
            }

            $columns = ['Status', 'Count'];
            if ($entity->hasAmount()) {
                $columns[] = $entity->amountLabel;
            }
            $rows = [];
            foreach ($entity->statuses as $status => $label) {
                if (! isset($byStatus[$status])) {
                    continue;
                }
                $row = [$label, (int) $byStatus[$status]->total];
                if ($entity->hasAmount()) {
                    $row[] = Money::format($byStatus[$status]->amount ?? 0);
                }
                $rows[] = $row;
            }

            $sections[] = ['title' => $entity->plural.' added', 'columns' => $columns, 'rows' => $rows];
        }

        return $sections;
    }

    /** Work done once a day for each workspace that has the app (recurring billing, status roll-overs). Returns how many things changed. */
    public function daily(Workspace $workspace): int
    {
        return 0;
    }

    // ----- Helpers for subclasses ------------------------------------------------

    protected function billing(): RecordBilling
    {
        return app(RecordBilling::class);
    }

    protected function money(float|int|string|null $amount): string
    {
        return Money::format($amount ?? 0);
    }

    /** Money still owed on the invoices raised from these records. @param  list<int>  $recordIds */
    protected function owingFor(array $recordIds): float
    {
        if (! $recordIds || ! $this->billing()->available()) {
            return 0.0;
        }

        return (float) Invoice::query()->whereIn('record_id', $recordIds)->whereIn('status', Invoice::OPEN_STATUSES)->sum('balance');
    }

    /** @param  list<int>  $recordIds  @return array<int, float> record id => amount paid on its invoices */
    protected function collectedFor(array $recordIds): array
    {
        if (! $recordIds || ! $this->billing()->available()) {
            return [];
        }

        return Invoice::query()->whereIn('record_id', $recordIds)->whereNot('status', 'cancelled')
            ->selectRaw('record_id, sum(amount_paid) as paid')->groupBy('record_id')->pluck('paid', 'record_id')->map(fn ($paid) => (float) $paid)->all();
    }

    /** Records of an entity whose record-field points at the given record. @return \Illuminate\Database\Eloquent\Builder<Record> */
    protected function linked(string $entity, string $field, Record|int $record)
    {
        return $this->records($entity)->linkedTo($field, $record instanceof Record ? $record->id : $record);
    }

    /** Records dated in a range, by their date column or, when undated, when they were added. @return \Illuminate\Database\Eloquent\Builder<Record> */
    protected function dated(string $entity, Carbon $from, Carbon $to)
    {
        return $this->records($entity)->where(fn ($query) => $query->whereBetween('occurs_on', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orWhere(fn ($query) => $query->whereNull('occurs_on')->whereBetween('created_at', [$from, $to])));
    }

    /** @return Builder<Record> */
    protected function records(string $entity)
    {
        return Record::query()->ofEntity($this->app->key, $entity);
    }

    /** "2026-10" → "Oct 2026" rows for every month between two dates. @return array<string, string> */
    protected function months(Carbon $from, Carbon $to): array
    {
        $months = [];
        for ($month = $from->copy()->startOfMonth(); $month <= $to; $month->addMonth()) {
            $months[$month->format('Y-m')] = $month->format('M Y');
        }

        return $months;
    }

    /** Group records' amounts by month of a date column, in PHP so it works the same on MySQL and SQLite. @return array<string, float> */
    protected function sumByMonth(iterable $records, string $dateColumn = 'occurs_on'): array
    {
        $totals = [];
        foreach ($records as $record) {
            $date = $record->{$dateColumn} ?? $record->created_at;
            $key = Carbon::parse($date)->format('Y-m');
            $totals[$key] = ($totals[$key] ?? 0) + (float) $record->amount;
        }

        return $totals;
    }
}
