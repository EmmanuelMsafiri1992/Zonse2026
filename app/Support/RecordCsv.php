<?php

namespace App\Support;

use App\Blueprints\Blueprint;
use App\Blueprints\Entity;
use App\Models\CustomField;
use App\Models\Record;
use Illuminate\Support\Collection;

/**
 * The spreadsheet layout of one app list: a column for each shared field the list uses,
 * each of the app's own fields and each extra field the workspace added, in plain words.
 * Used by the list's "Export" button and by the full workspace export.
 */
class RecordCsv
{
    /** @var Collection<int, CustomField> */
    protected Collection $extra;

    public function __construct(public Blueprint $app, public Entity $def, ?int $workspaceId = null)
    {
        $this->extra = CustomFields::for(CustomField::appEntity($app->key, $def->key), $workspaceId);
    }

    /** @return list<string> */
    public function header(): array
    {
        $def = $this->def;
        $header = ['Number', $def->titleLabel, 'Status'];
        if ($def->hasContact()) {
            $header[] = $def->contactLabel;
        }
        if ($def->hasAssignee) {
            $header[] = 'Assignee';
        }
        if ($def->hasAmount()) {
            $header[] = $def->amountLabel;
        }
        if ($def->hasDate()) {
            $header[] = $def->dateLabel;
        }
        if ($def->hasDue()) {
            $header[] = $def->dueLabel;
        }
        foreach ($def->fields as $field) {
            $header[] = $field->label;
        }
        foreach ($this->extra as $field) {
            $header[] = $field->label;
        }
        $header[] = 'Created';

        return $header;
    }

    /** @return list<string|null> */
    public function row(Record $record): array
    {
        $def = $this->def;
        $row = [$record->number, $record->title, $record->statusLabel()];
        if ($def->hasContact()) {
            $row[] = $record->contact?->displayName() ?? '';
        }
        if ($def->hasAssignee) {
            $row[] = $record->assignee?->name ?? '';
        }
        if ($def->hasAmount()) {
            $row[] = $record->amount !== null ? number_format((float) $record->amount, 2, '.', '') : '';
        }
        if ($def->hasDate()) {
            $row[] = $record->occurs_on?->toDateString() ?? '';
        }
        if ($def->hasDue()) {
            $row[] = $record->due_on?->toDateString() ?? '';
        }
        foreach ($def->fields as $field) {
            $row[] = $record->displayValue($field);
        }
        foreach ($this->extra as $field) {
            $row[] = $field->display($record->customField($field->key)) ?? '';
        }
        $row[] = $record->created_at?->toDateTimeString();

        return $row;
    }

    /**
     * Writes the header and every record to a CSV stream.
     *
     * @param  resource  $stream
     * @param  iterable<Record>  $records
     */
    public function write($stream, iterable $records): void
    {
        fputcsv($stream, $this->header());
        foreach ($records as $record) {
            fputcsv($stream, $this->row($record));
        }
    }
}
