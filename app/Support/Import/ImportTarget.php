<?php

namespace App\Support\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

/**
 * One kind of record a spreadsheet can be imported into: the columns it understands, how a row
 * becomes attributes, how an existing record is recognised, and how rows are saved.
 */
abstract class ImportTarget
{
    abstract public function key(): string;

    /** Plural name shown in the wizard, e.g. "Contacts". */
    abstract public function label(): string;

    abstract public function icon(): string;

    abstract public function listUrl(): ?string;

    /**
     * The columns this target fills. Aliases are other headings that mean the same column, as written by
     * QuickBooks, Sage, Xero and Zonseob's own exports.
     *
     * @return array<string, array{label: string, required?: bool, aliases?: list<string>, example?: string, hint?: string}>
     */
    abstract public function columns(): array;

    /**
     * Turn one row into attributes. A null value means the row did not give it: a new record gets
     * the default and an existing record keeps what it has.
     *
     * @param  array<string, string>  $raw  column key => cell text, for the matched columns only
     * @param  array<string, string>  $options
     * @return array{values: array<string, mixed>, errors: list<string>, warnings: list<string>}
     */
    abstract public function prepare(array $raw, array $options): array;

    /** What identifies the same record twice in one file. */
    abstract public function matchKey(array $values): ?string;

    abstract public function findExisting(array $values): ?Model;

    abstract public function create(array $values, array $options): Model;

    abstract public function update(Model $model, array $values): void;

    /** @return class-string<Model> */
    abstract public function modelClass(): string;

    abstract public function describe(Model $model): string;

    /**
     * Business rules that need the existing record, e.g. a barcode already used by another item.
     *
     * @return list<string>
     */
    public function check(array $values, ?Model $existing): array
    {
        return [];
    }

    /** Records that others now point at (invoices, bookings) stay when an import is undone. */
    public function inUse(Model $model): bool
    {
        return false;
    }

    /**
     * Extra choices on the matching screen.
     *
     * @param  list<string>  $headers
     * @return array<string, array{label: string, options: array<string, string>, default: string}>
     */
    public function extraOptions(array $headers): array
    {
        return [];
    }

    /** @return list<string> */
    public function requiredColumns(): array
    {
        return array_keys(array_filter($this->columns(), fn (array $column) => $column['required'] ?? false));
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $rules
     * @return list<string>
     */
    protected function validate(array $values, array $rules): array
    {
        $labels = collect($this->columns())->map(fn (array $column) => $column['label'])->all();

        return Validator::make($values, $rules, [], $labels)->errors()->all();
    }

    /** @return array<string, mixed> only the attributes the row gave */
    protected function given(array $values): array
    {
        return array_filter($values, fn ($value) => $value !== null);
    }
}
