<?php

namespace App\Blueprints;

use Illuminate\Support\Str;

/**
 * A kind of record inside a blueprint app (a farm has fields, crops and
 * harvests; a gym has members, classes and check-ins).
 *
 * Compact definition: [label, titleLabel, "status,status", [field specs], extras]
 * where extras may set icon, plural, prefix, contact, amount, date, due,
 * assignee, list, description and bill.
 *
 * `bill` makes records invoiceable through the Invoicing module: true, or a map of
 * invoice status => record status (e.g. ['paid' => 'paid', 'partial' => 'part_paid'])
 * plus `via` (a record field whose record's contact pays, e.g. a visit's patient).
 */
class Entity
{
    /** @var array<string, Field> */
    public array $fields = [];

    /** @var array<string, string> status key => label */
    public array $statuses = [];

    /**
     * @param  array<string, Field>  $fields
     * @param  array<string, string>  $statuses
     * @param  list<string>  $listColumns
     */
    public function __construct(
        public string $key,
        public string $blueprintKey,
        public string $label,
        public string $plural,
        public string $icon,
        public string $titleLabel,
        public string $numberPrefix,
        array $statuses,
        array $fields,
        public ?string $contactLabel = null,
        public ?string $amountLabel = null,
        public ?string $dateLabel = null,
        public ?string $dueLabel = null,
        public bool $hasAssignee = false,
        public array $listColumns = [],
        public ?string $description = null,
        public ?array $billing = null,
    ) {
        $this->statuses = $statuses;
        $this->fields = $fields;
    }

    /** @param  array<int|string, mixed>  $spec */
    public static function fromArray(string $key, string $blueprintKey, string $blueprintIcon, array $spec): static
    {
        [$label, $titleLabel, $statusSpec, $fieldSpecs] = $spec;
        $extras = $spec[4] ?? [];

        $statuses = [];
        foreach (array_filter(explode(',', $statusSpec)) as $status) {
            $statuses[$status] = self::statusLabel($status);
        }

        $fields = [];
        foreach ($fieldSpecs as $fieldSpec) {
            $field = Field::parse($fieldSpec);
            $fields[$field->key] = $field;
        }

        $plural = $extras['plural'] ?? Str::plural($label);

        return new static(
            key: $key,
            blueprintKey: $blueprintKey,
            label: $label,
            plural: $plural,
            icon: $extras['icon'] ?? $blueprintIcon,
            titleLabel: $titleLabel,
            numberPrefix: $extras['prefix'] ?? self::derivePrefix($label),
            statuses: $statuses,
            fields: $fields,
            contactLabel: $extras['contact'] ?? null,
            amountLabel: $extras['amount'] ?? null,
            dateLabel: $extras['date'] ?? null,
            dueLabel: $extras['due'] ?? null,
            hasAssignee: (bool) ($extras['assignee'] ?? false),
            listColumns: $extras['list'] ?? [],
            description: $extras['description'] ?? null,
            billing: isset($extras['bill']) ? (is_array($extras['bill']) ? $extras['bill'] : []) : null,
        );
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'in_progress' => 'In progress',
            'no_show' => 'No-show',
            'on_hold' => 'On hold',
            default => Str::ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /** "Stock movement" → "SM-", "Item" → "ITM-". */
    public static function derivePrefix(string $label): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $label, -1, PREG_SPLIT_NO_EMPTY) ?: ['REC'];
        if (count($words) >= 2) {
            $prefix = implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 3)));
        } else {
            $word = $words[0];
            $prefix = mb_substr($word, 0, 1);
            $rest = preg_replace('/[aeiou]/i', '', mb_substr($word, 1)) ?? '';
            $prefix .= mb_substr($rest.mb_substr($word, 1), 0, 2);
        }

        return mb_strtoupper($prefix).'-';
    }

    /** Pill colour for a status, so free-form blueprint statuses reuse the shared pill palette. */
    public function statusTone(?string $status): string
    {
        return match (true) {
            in_array($status, ['done', 'completed', 'paid', 'delivered', 'resolved', 'won', 'hired', 'licensed', 'graduated', 'repaid', 'received', 'collected', 'given', 'attended', 'held', 'compliant', 'operational', 'available', 'enrolled', 'active', 'member', 'alive', 'in_use', 'occupied', 'harvested', 'in_store', 'resulted', 'checked_in', 'signed_in', 'let', 'awarded', 'sold'], true) => 'success',
            in_array($status, ['cancelled', 'rejected', 'lost', 'no_show', 'failed', 'lapsed', 'overdue', 'in_arrears', 'written_off', 'declined', 'deceased', 'died', 'faulty', 'short', 'missed', 'stopped', 'spoiled', 'suspended', 'expired', 'emergency', 'abnormal'], true) => 'danger',
            in_array($status, ['waiting', 'pending', 'on_hold', 'queried', 'awaiting_parts', 'notice_given', 'due_soon', 'due', 'part_paid', 'under_offer', 'under_repair', 'frozen', 'escalated', 'test_booked', 'requested', 'unpaid', 'unbilled', 'waitlisted'], true) => 'warning',
            in_array($status, ['approved', 'accepted', 'offer', 'billed', 'invoiced', 'reconciled'], true) => 'purple',
            in_array($status, ['draft', 'inactive', 'archived', 'closed', 'retired', 'left', 'exited', 'ended', 'withdrawn', 'fallow', 'scrapped', 'disposed', 'reversed', 'transferred', 'signed_out', 'slaughtered', 'leased_out', 'in_storage', 'visitor', 'planned', 'skipped', 'waived', 'recorded'], true) => 'muted',
            $status === $this->defaultStatus() => 'primary',
            default => 'info',
        };
    }

    public function defaultStatus(): string
    {
        return array_key_first($this->statuses) ?? 'active';
    }

    public function field(string $key): ?Field
    {
        return $this->fields[$key] ?? null;
    }

    public function sequenceKey(): string
    {
        return 'rec.'.$this->blueprintKey.'.'.$this->key;
    }

    /** Fields shown as table columns in the list view (explicit list, else the first three simple fields). */
    public function listFields(): array
    {
        if ($this->listColumns) {
            return array_values(array_filter(array_map(fn ($k) => $this->field($k), $this->listColumns)));
        }

        return array_values(array_slice(array_filter($this->fields, fn (Field $f) => $f->type !== 'textarea'), 0, 3));
    }

    /** Fields of type "record" that point at another entity. @return array<string, Field> */
    public function recordFields(): array
    {
        return array_filter($this->fields, fn (Field $f) => $f->type === 'record');
    }

    public function hasContact(): bool
    {
        return $this->contactLabel !== null;
    }

    public function hasAmount(): bool
    {
        return $this->amountLabel !== null;
    }

    public function hasDate(): bool
    {
        return $this->dateLabel !== null;
    }

    /** Whether records can be turned into invoices. */
    public function isBillable(): bool
    {
        return $this->billing !== null;
    }

    public function hasDue(): bool
    {
        return $this->dueLabel !== null;
    }
}
