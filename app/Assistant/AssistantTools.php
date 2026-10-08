<?php

namespace App\Assistant;

use App\Blueprints\Blueprint;
use App\Blueprints\BlueprintRegistry;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;

/**
 * The read-only lookups the assistant may make. Every query runs inside the workspace context
 * (so the workspace scope applies) and only touches apps the workspace has switched on. Nothing
 * here can change data; the assistant drafts and explains, people act.
 */
class AssistantTools
{
    /** Rows returned by one lookup at most, so answers stay short and cheap. */
    public const MAX_ROWS = 25;

    /** Records read for one totals lookup at most. */
    public const MAX_SCAN = 5000;

    public const PERIODS = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'last_year'];

    public function __construct(protected Workspace $workspace, protected BlueprintRegistry $blueprints) {}

    /**
     * The tool definitions offered to the model, as JSON-schema parameter objects.
     *
     * @return list<array{name: string, label: string, description: string, parameters: array<string, mixed>}>
     */
    public function definitions(): array
    {
        $period = [
            'period' => ['type' => 'string', 'enum' => self::PERIODS, 'description' => 'A named date range. Use instead of from/to when it fits.'],
            'from' => ['type' => 'string', 'description' => 'Start date, YYYY-MM-DD.'],
            'to' => ['type' => 'string', 'description' => 'End date, YYYY-MM-DD.'],
        ];
        $app = [
            'app' => ['type' => 'string', 'description' => 'The app or list to look in, by name or key, e.g. "expenses", "Fleet", "job cards". workspace_overview lists them.'],
            'entity' => ['type' => 'string', 'description' => 'Optional: which list inside the app, when it has several.'],
        ];

        $tools = [
            [
                'name' => 'workspace_overview',
                'label' => 'Looked at the workspace',
                'description' => 'Lists the apps switched on in this workspace, the lists inside each with how many records they hold, and the number of contacts. Call this first when unsure where data lives.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'find_records',
                'label' => 'Searched records',
                'description' => 'Finds records in one app list, newest first, with their number, title, status, amount, dates, contact and a link.',
                'parameters' => ['type' => 'object', 'properties' => $app + [
                    'search' => ['type' => 'string', 'description' => 'Words to look for in the title, number or text fields.'],
                    'status' => ['type' => 'string', 'description' => 'Only records with this status key.'],
                    'limit' => ['type' => 'integer', 'description' => 'How many to return, at most '.self::MAX_ROWS.'.'],
                ] + $period, 'required' => ['app']],
            ],
            [
                'name' => 'record_totals',
                'label' => 'Added up records',
                'description' => 'Counts records in one app list and adds up their amounts, optionally grouped by status, month, contact or one of the list\'s fields (e.g. category). Use for "how much", "how many" and "which ... most" questions.',
                'parameters' => ['type' => 'object', 'properties' => $app + [
                    'group_by' => ['type' => 'string', 'description' => '"status", "month", "contact", or a field key of the list such as "category".'],
                    'status' => ['type' => 'string', 'description' => 'Only records with this status key.'],
                ] + $period, 'required' => ['app']],
            ],
            [
                'name' => 'get_record',
                'label' => 'Opened a record',
                'description' => 'Reads one record in full by its number (e.g. "EXP-0004"): every field, the contact, recent comments and any invoices.',
                'parameters' => ['type' => 'object', 'properties' => ['number' => ['type' => 'string', 'description' => 'The record number.']], 'required' => ['number']],
            ],
            [
                'name' => 'find_contacts',
                'label' => 'Searched contacts',
                'description' => 'Finds customers, suppliers and leads by name, email or phone, with what they owe when invoicing is on.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Name, company, email or phone.'],
                    'type' => ['type' => 'string', 'enum' => array_keys(Contact::TYPES)],
                    'limit' => ['type' => 'integer', 'description' => 'How many to return, at most '.self::MAX_ROWS.'.'],
                ]],
            ],
        ];

        if ($this->workspace->hasModule('invoicing')) {
            $tools[] = [
                'name' => 'invoice_summary',
                'label' => 'Checked invoices',
                'description' => 'Totals invoiced, paid and still owed, the overdue amount, and the open invoices (who owes what and since when). Use for sales and "who owes us" questions.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'customer' => ['type' => 'string', 'description' => 'Only invoices for customers matching this name.'],
                ] + $period],
            ];
        }

        return $tools;
    }

    public function label(string $name): string
    {
        return collect($this->definitions())->firstWhere('name', $name)['label'] ?? 'Looked something up';
    }

    /**
     * Run one lookup. Bad input comes back as an error the model can read and correct.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $name, array $arguments): array
    {
        try {
            return match ($name) {
                'workspace_overview' => $this->workspaceOverview(),
                'find_records' => $this->findRecords($arguments),
                'record_totals' => $this->recordTotals($arguments),
                'get_record' => $this->getRecord($arguments),
                'find_contacts' => $this->findContacts($arguments),
                'invoice_summary' => $this->workspace->hasModule('invoicing') ? $this->invoiceSummary($arguments) : ['error' => 'Invoicing is not switched on.'],
                default => ['error' => 'Unknown lookup "'.$name.'".'],
            };
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    protected function workspaceOverview(): array
    {
        $counts = Record::query()->selectRaw('blueprint, entity, count(*) as total')->groupBy('blueprint', 'entity')->get()
            ->mapWithKeys(fn ($row) => [$row->blueprint.'.'.$row->entity => (int) $row->total]);

        return [
            'workspace' => $this->workspace->name,
            'currency' => $this->workspace->currency_code,
            'today' => now()->toDateString(),
            'apps' => collect($this->apps())->map(fn (Blueprint $app) => [
                'app' => $app->name,
                'key' => $app->key,
                'lists' => collect($app->entities)->map(fn (Entity $entity) => [
                    'entity' => $entity->key,
                    'name' => $entity->plural,
                    'records' => $counts[$app->key.'.'.$entity->key] ?? 0,
                    'statuses' => $entity->statuses,
                    'fields' => collect($entity->fields)->map(fn ($field) => $field->label)->all(),
                ])->values()->all(),
            ])->values()->all(),
            'contacts' => Contact::query()->count(),
            'invoicing' => $this->workspace->hasModule('invoicing'),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function findRecords(array $arguments): array
    {
        [$app, $entity] = $this->resolve($arguments);
        $limit = $this->limit($arguments);
        $query = $this->recordQuery($app, $entity, $arguments)->with(['contact', 'assignee'])
            ->search($this->string($arguments, 'search'))->orderByDesc($this->dateColumn($entity))->orderByDesc('id');
        $total = (clone $query)->count();

        return [
            'list' => $app->name.' › '.$entity->plural,
            'matching' => $total,
            'showing' => min($total, $limit),
            'records' => $query->limit($limit)->get()->map(fn (Record $record) => $this->recordRow($record, $entity))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function recordTotals(array $arguments): array
    {
        [$app, $entity] = $this->resolve($arguments);
        $groupBy = $this->string($arguments, 'group_by');
        $field = null;
        if ($groupBy !== null && ! in_array($groupBy, ['status', 'month', 'contact'], true)) {
            $field = $entity->field($groupBy) ?? collect($entity->fields)->first(fn ($candidate) => Str::lower($candidate->label) === Str::lower($groupBy));
            if (! $field) {
                throw new \InvalidArgumentException('"'.$entity->plural.'" has no field "'.$groupBy.'". Its fields are: '.implode(', ', array_keys($entity->fields)).'.');
            }
        }

        $dateColumn = $this->dateColumn($entity);
        $records = $this->recordQuery($app, $entity, $arguments)->with($groupBy === 'contact' ? ['contact'] : [])
            ->orderByDesc('id')->limit(self::MAX_SCAN)->get();

        $result = [
            'list' => $app->name.' › '.$entity->plural,
            'range' => $this->rangeLabel($arguments),
            'count' => $records->count(),
            'totals' => $this->sums($records),
        ];
        if ($records->count() === self::MAX_SCAN) {
            $result['note'] = 'Only the latest '.self::MAX_SCAN.' records were counted.';
        }

        if ($groupBy !== null) {
            $result['grouped_by'] = $field?->label ?? $groupBy;
            $result['groups'] = $records->groupBy(fn (Record $record) => match (true) {
                $groupBy === 'status' => $record->statusLabel(),
                $groupBy === 'month' => ($record->{$dateColumn} ?? $record->created_at)->format('Y-m'),
                $groupBy === 'contact' => $record->contact?->displayName() ?? 'No contact',
                default => $record->displayValue($field) ?: 'Not set',
            })->map(fn ($group, $key) => ['group' => (string) $key, 'count' => $group->count(), 'totals' => $this->sums($group)])
                ->sortByDesc(fn ($row) => array_sum($row['totals']) ?: $row['count'])->take(self::MAX_ROWS)->values()->all();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function getRecord(array $arguments): array
    {
        $number = $this->string($arguments, 'number') ?? throw new \InvalidArgumentException('Give the record number.');
        $record = Record::query()->whereIn('blueprint', array_keys($this->apps()))->where('number', $number)
            ->with(['contact', 'assignee', 'creator'])->latest('id')->first();
        if (! $record) {
            throw new \InvalidArgumentException('No record numbered "'.$number.'" was found.');
        }
        $entity = $record->definition();

        $details = $this->recordRow($record, $entity) + [
            'app' => $this->apps()[$record->blueprint]->name,
            'list' => $entity->label,
            'fields' => collect($entity->fields)->mapWithKeys(fn ($field) => [$field->label => $record->displayValue($field)])->filter(fn ($value) => $value !== '')->all(),
            'created' => $record->created_at->toDateString().' by '.($record->creator?->name ?? 'someone'),
            'updated' => $record->updated_at->toDateString(),
            'comments' => $record->comments()->with('user')->latest('id')->limit(5)->get()
                ->map(fn ($comment) => ['by' => $comment->user?->name ?? $comment->author_name ?? 'someone', 'on' => $comment->created_at->toDateString(), 'text' => Str::limit($comment->body, 400)])->all(),
        ];
        if ($record->contact) {
            $details['contact_details'] = array_filter([
                'name' => $record->contact->displayName(), 'email' => $record->contact->email,
                'phone' => $record->contact->mobile ?: $record->contact->phone, 'type' => $record->contact->typeLabel(),
            ]);
        }
        if ($this->workspace->hasModule('invoicing') && $entity->isBillable()) {
            $details['invoices'] = $record->invoices()->latest('id')->limit(5)->get()
                ->map(fn (Invoice $invoice) => ['number' => $invoice->number, 'status' => $invoice->statusLabel(), 'total' => $invoice->total, 'balance' => $invoice->balance, 'due' => $invoice->due_date?->toDateString()])->all();
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function findContacts(array $arguments): array
    {
        $type = $this->string($arguments, 'type');
        $query = Contact::query()->search($this->string($arguments, 'search'))
            ->when($type && array_key_exists($type, Contact::TYPES), fn ($query) => $query->ofType($type))->orderBy('name');
        $total = (clone $query)->count();
        $contacts = $query->limit($this->limit($arguments))->get();

        $owed = $this->workspace->hasModule('invoicing')
            ? Invoice::query()->open()->whereIn('contact_id', $contacts->pluck('id'))->selectRaw('contact_id, sum(balance) as owed')->groupBy('contact_id')->pluck('owed', 'contact_id')
            : collect();

        return [
            'matching' => $total,
            'contacts' => $contacts->map(fn (Contact $contact) => array_filter([
                'name' => $contact->displayName(),
                'type' => $contact->typeLabel(),
                'email' => $contact->email,
                'phone' => $contact->mobile ?: $contact->phone,
                'city' => $contact->city,
                'owes' => isset($owed[$contact->id]) ? round((float) $owed[$contact->id], 2) : null,
                'url' => $contact->activityUrl(),
            ], fn ($value) => $value !== null && $value !== ''))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function invoiceSummary(array $arguments): array
    {
        [$from, $to] = $this->range($arguments);
        $customer = $this->string($arguments, 'customer');
        $customerIds = $customer ? Contact::query()->search($customer)->pluck('id') : null;
        $scoped = fn () => Invoice::query()->where('status', '!=', 'cancelled')
            ->when($customerIds !== null, fn ($query) => $query->whereIn('contact_id', $customerIds));

        $issued = $scoped()->where('status', '!=', 'draft')
            ->when($from, fn ($query) => $query->whereDate('issue_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('issue_date', '<=', $to))
            ->get(['currency_code', 'total', 'amount_paid', 'balance']);
        $open = $scoped()->open()->with('contact')->orderBy('due_date')->get();

        $byCurrency = fn ($invoices, string $column) => $invoices->groupBy(fn ($invoice) => $invoice->currency_code ?: $this->workspace->currency_code)
            ->map(fn ($group) => round($group->sum($column), 2))->all();

        return [
            'range' => $this->rangeLabel($arguments),
            'invoices_issued' => $issued->count(),
            'invoiced' => $byCurrency($issued, 'total'),
            'paid_on_these' => $byCurrency($issued, 'amount_paid'),
            'still_owed_all_time' => $byCurrency($open, 'balance'),
            'overdue' => $byCurrency($open->filter(fn (Invoice $invoice) => $invoice->isOverdue()), 'balance'),
            'open_invoices' => $open->take(self::MAX_ROWS)->map(fn (Invoice $invoice) => [
                'number' => $invoice->number,
                'customer' => $invoice->contact?->displayName(),
                'customer_email' => $invoice->contact?->email,
                'issued' => $invoice->issue_date?->toDateString(),
                'due' => $invoice->due_date?->toDateString(),
                'days_overdue' => $invoice->isOverdue() ? (int) $invoice->due_date->diffInDays(today()) : 0,
                'balance' => $invoice->balance,
                'currency' => $invoice->currency_code,
                'url' => $invoice->activityUrl(),
            ])->values()->all(),
        ];
    }

    // ----- helpers -------------------------------------------------------------

    /** @return array<string, Blueprint> apps the workspace has switched on */
    public function apps(): array
    {
        return array_filter($this->blueprints->all(), fn (Blueprint $app) => $this->workspace->hasModule($app->key));
    }

    /**
     * Find the app list a lookup means. Accepts keys and names ("expenses", "Fleet › Trips",
     * "job cards"), and failing an exact match, the longest app or list name mentioned in the text.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: Blueprint, 1: Entity}
     */
    public function resolve(array $arguments): array
    {
        $hint = Str::lower(trim((string) ($arguments['app'] ?? '')));
        $entityHint = Str::lower(trim((string) ($arguments['entity'] ?? '')));
        if ($hint === '') {
            throw new \InvalidArgumentException('Say which app to look in. workspace_overview lists them.');
        }

        $best = null;
        foreach ($this->apps() as $app) {
            foreach ($app->entities as $entity) {
                $names = array_unique(array_map(fn (string $name) => Str::lower($name), [$entity->key, $entity->label, $entity->plural, str_replace('_', ' ', $entity->key)]));
                $appNames = array_unique(array_map(fn (string $name) => Str::lower($name), [$app->key, $app->name, str_replace('_', ' ', $app->key)]));
                $entityMatches = $entityHint !== '' && in_array($entityHint, $names, true);
                $isPrimary = $entity === $app->primaryEntity();

                $score = match (true) {
                    in_array($hint, $appNames, true) && ($entityMatches || ($entityHint === '' && $isPrimary)) => 1000,
                    in_array($hint, $names, true) => 900,
                    default => 0,
                };
                if ($score === 0) {
                    foreach ([...$names, ...$appNames] as $name) {
                        if (mb_strlen($name) >= 3 && preg_match('/(?<![\p{L}\d])'.preg_quote($name, '/').'(?![\p{L}\d])/u', $hint)) {
                            $score = max($score, mb_strlen($name) * 10 + (in_array($name, $names, true) ? 5 : ($isPrimary ? 1 : 0)));
                        }
                    }
                }
                if ($score > 0 && ($best === null || $score > $best[0])) {
                    $best = [$score, $app, $entity];
                }
            }
        }

        if (! $best) {
            throw new \InvalidArgumentException('No app called "'.$arguments['app'].'" is switched on. Switched on: '.(implode(', ', array_map(fn (Blueprint $app) => $app->name, $this->apps())) ?: 'none').'.');
        }

        return [$best[1], $best[2]];
    }

    /** @param  array<string, mixed>  $arguments */
    protected function recordQuery(Blueprint $app, Entity $entity, array $arguments): Builder
    {
        [$from, $to] = $this->range($arguments);
        $column = $this->dateColumn($entity);

        return Record::query()->ofEntity($app->key, $entity->key)
            ->status($this->string($arguments, 'status'))
            ->when($from, fn ($query) => $query->whereDate($column, '>=', $from))
            ->when($to, fn ($query) => $query->whereDate($column, '<=', $to));
    }

    protected function dateColumn(Entity $entity): string
    {
        return $entity->hasDate() ? 'occurs_on' : 'created_at';
    }

    /** @return array<string, mixed> */
    protected function recordRow(Record $record, Entity $entity): array
    {
        return array_filter([
            'number' => $record->number,
            'title' => $record->title,
            'status' => $record->statusLabel(),
            'amount' => $entity->hasAmount() && $record->amount !== null ? (float) $record->amount : null,
            'currency' => $entity->hasAmount() ? ($record->currency ?: $this->workspace->currency_code) : null,
            ($entity->dateLabel ?? 'date') => $record->occurs_on?->toDateString(),
            ($entity->dueLabel ?? 'due') => $record->due_on?->toDateString(),
            ($entity->contactLabel ?? 'contact') => $record->contact?->displayName(),
            'assigned_to' => $record->assignee?->name,
            'url' => $record->url(),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** @return array<string, float> amount per currency */
    protected function sums($records): array
    {
        return $records->filter(fn (Record $record) => $record->amount !== null)
            ->groupBy(fn (Record $record) => $record->currency ?: $this->workspace->currency_code ?: 'USD')
            ->map(fn ($group) => round($group->sum(fn (Record $record) => (float) $record->amount), 2))->all();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: ?string, 1: ?string} Y-m-d dates
     */
    public function range(array $arguments): array
    {
        $today = Carbon::today();
        $period = $this->string($arguments, 'period');
        if ($period !== null) {
            return match ($period) {
                'today' => [$today->toDateString(), $today->toDateString()],
                'yesterday' => [$today->copy()->subDay()->toDateString(), $today->copy()->subDay()->toDateString()],
                'this_week' => [$today->copy()->startOfWeek()->toDateString(), $today->copy()->endOfWeek()->toDateString()],
                'last_week' => [$today->copy()->subWeek()->startOfWeek()->toDateString(), $today->copy()->subWeek()->endOfWeek()->toDateString()],
                'this_month' => [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString()],
                'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
                'this_year' => [$today->copy()->startOfYear()->toDateString(), $today->copy()->endOfYear()->toDateString()],
                'last_year' => [$today->copy()->subYear()->startOfYear()->toDateString(), $today->copy()->subYear()->endOfYear()->toDateString()],
                default => throw new \InvalidArgumentException('Unknown period "'.$period.'". Use one of: '.implode(', ', self::PERIODS).'.'),
            };
        }

        $dates = [];
        foreach (['from', 'to'] as $key) {
            $value = $this->string($arguments, $key);
            if ($value !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                throw new \InvalidArgumentException('"'.$key.'" must be a date like 2026-03-31.');
            }
            $dates[] = $value;
        }

        return $dates;
    }

    /** @param  array<string, mixed>  $arguments */
    protected function rangeLabel(array $arguments): string
    {
        [$from, $to] = $this->range($arguments);

        return match (true) {
            $from && $to => $from.' to '.$to,
            (bool) $from => 'from '.$from,
            (bool) $to => 'up to '.$to,
            default => 'all time',
        };
    }

    /** @param  array<string, mixed>  $arguments */
    protected function limit(array $arguments): int
    {
        return max(1, min(self::MAX_ROWS, (int) ($arguments['limit'] ?? 10)));
    }

    /** @param  array<string, mixed>  $arguments */
    protected function string(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
