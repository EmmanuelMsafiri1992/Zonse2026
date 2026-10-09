<?php

namespace App\Support;

use App\Blueprints\BlueprintRegistry;
use App\Blueprints\Entity;
use App\Models\CustomField;
use App\Models\DocumentTemplate;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Payment;
use Symfony\Component\HttpFoundation\Response;

/**
 * Letters, certificates and receipts the workspace designs: what they can be filled in from,
 * which merge tags each offers, and turning a template plus a contact, payment or app record into a PDF.
 */
class DocumentTemplates
{
    /** @var array<string, string> */
    public const KINDS = ['letter' => 'Letter', 'certificate' => 'Certificate', 'receipt' => 'Receipt', 'other' => 'Other document'];

    /** @var array<string, string> */
    public const PAPERS = ['a4' => 'A4', 'letter' => 'US Letter'];

    /** @var array<string, string> */
    public const ORIENTATIONS = ['portrait' => 'Portrait (tall)', 'landscape' => 'Landscape (wide)'];

    /** @var array<string, string> */
    public const FONTS = ['sans' => 'Clean (sans-serif)', 'serif' => 'Formal (serif)'];

    /** @var array<string, string> */
    public const ALIGNS = ['left' => 'Left', 'center' => 'Centred'];

    /** @var array<string, string> */
    public const BORDERS = ['none' => 'No border', 'simple' => 'Thin line', 'double' => 'Double frame'];

    public const MAX_SIGNATURES = 3;

    /** Matches {{ contact.name }}, with or without the spaces. */
    public const TAG_PATTERN = '/\{\{\s*([a-z0-9_.]+)\s*\}\}/i';

    /**
     * What templates can be filled in from in this workspace, grouped for a select.
     *
     * @return array<string, array<string, string>>
     */
    public static function subjects(Workspace $workspace): array
    {
        $records = array_filter([
            'contact' => $workspace->hasModule('contacts') ? 'Contacts (letters, certificates)' : null,
            'payment' => $workspace->hasModule('invoicing') ? 'Payments (receipts)' : null,
        ]);
        $groups = ['General' => ['none' => 'Nothing (general letters and notices)']];
        if ($records !== []) {
            $groups['Records'] = $records;
        }

        return $groups + collect(CustomField::entityChoices($workspace))->except('Records')->all();
    }

    public static function allowsSubject(Workspace $workspace, string $subject): bool
    {
        return collect(self::subjects($workspace))->contains(fn (array $group) => isset($group[$subject]));
    }

    public static function subjectLabel(string $subject): string
    {
        return match ($subject) {
            'none' => 'General',
            'contact' => 'Contacts',
            'payment' => 'Payments',
            default => CustomField::appEntityLabel($subject) ?? $subject,
        };
    }

    /** The template subject a contact, payment or app record belongs to, or null for anything else. */
    public static function subjectKey(Model $model): ?string
    {
        return match (true) {
            $model instanceof Contact => 'contact',
            $model instanceof Payment => 'payment',
            $model instanceof Record => CustomField::appEntity($model->blueprint, $model->entity),
            default => null,
        };
    }

    /**
     * The switched-on templates that can be printed for this contact, payment or record.
     *
     * @return Collection<int, DocumentTemplate>
     */
    public static function availableFor(Model $model): Collection
    {
        $subject = self::subjectKey($model);
        $workspaceId = $model->getAttribute('workspace_id');
        if (! $subject || ! $workspaceId) {
            return collect();
        }

        // A list page asks once per row, so the answer is kept for the rest of the request.
        $key = 'document-templates.'.$workspaceId.'.'.$subject;
        if (! request()->attributes->has($key)) {
            $workspace = Workspace::query()->find($workspaceId);
            request()->attributes->set($key, $workspace && self::allowsSubject($workspace, $subject)
                ? DocumentTemplate::query()->where('workspace_id', $workspaceId)->for($subject)->get(['id', 'name', 'kind'])
                : collect());
        }

        return request()->attributes->get($key);
    }

    /**
     * Every merge tag a template for this subject may use, grouped for the designer.
     *
     * @return array<string, array<string, string>> group => [tag => label]
     */
    public static function tags(string $subject): array
    {
        $groups = [
            'Your business' => [
                'workspace.name' => 'Business name', 'workspace.email' => 'Business email', 'workspace.phone' => 'Business phone',
                'workspace.address' => 'Business address', 'workspace.city' => 'Business city', 'workspace.tax_number' => 'Tax number',
            ],
            'Date & signer' => ['today' => "Today's date", 'user.name' => 'Your name (who prints it)'],
        ];

        if ($subject === 'contact') {
            $groups['Contact'] = self::contactTags() + self::customTags('contact');
        } elseif ($subject === 'payment') {
            $groups['Payment'] = [
                'payment.number' => 'Receipt number', 'payment.amount' => 'Amount paid', 'payment.date' => 'Date paid',
                'payment.method' => 'Paid by', 'payment.reference' => 'Payment reference', 'payment.notes' => 'Payment notes',
                'invoice.number' => 'Invoice number', 'invoice.total' => 'Invoice total', 'invoice.balance' => 'Still owed on the invoice',
            ];
            $groups['Customer'] = self::contactTags();
        } elseif ($entity = self::entity($subject)) {
            $record = ['record.number' => 'Number', 'record.title' => $entity->titleLabel, 'record.status' => 'Status'];
            foreach ($entity->fields as $field) {
                $record['record.'.$field->key] = $field->label;
            }
            if ($entity->hasDate()) {
                $record['record.date'] = (string) $entity->dateLabel;
            }
            if ($entity->hasDue()) {
                $record['record.due'] = (string) $entity->dueLabel;
            }
            if ($entity->hasAmount()) {
                $record['record.amount'] = (string) $entity->amountLabel;
            }
            $groups[$entity->label] = $record + self::customTags($subject);
            if ($entity->hasContact()) {
                $groups[(string) $entity->contactLabel] = self::contactTags();
            }
        }

        return $groups;
    }

    /** @return list<string> */
    public static function tagKeys(string $subject): array
    {
        return collect(self::tags($subject))->flatMap(fn (array $group) => array_keys($group))->values()->all();
    }

    /**
     * Tags written in the wording that this subject does not offer, so typos are caught before printing.
     *
     * @return list<string>
     */
    public static function unknownTags(string $subject, ?string ...$texts): array
    {
        preg_match_all(self::TAG_PATTERN, implode("\n", array_filter($texts)), $matches);

        return array_values(array_unique(array_diff(array_map('strtolower', $matches[1]), self::tagKeys($subject))));
    }

    /**
     * Ready-made templates to start from.
     *
     * @return array<string, array{label: string, description: string, kind: string, subject: string, values: array<string, mixed>}>
     */
    public static function starters(): array
    {
        return [
            'letter' => [
                'label' => 'Letter to a contact', 'description' => 'A letter on your letterhead, addressed to one of your contacts.',
                'kind' => 'letter', 'subject' => 'contact',
                'values' => [
                    'heading' => null,
                    'body' => "{{ today }}\n\n{{ contact.name }}  \n{{ contact.address }}  \n{{ contact.city }}\n\nDear {{ contact.name }},\n\nWrite your letter here.\n\nYours sincerely,",
                    'signatures' => ['{{ user.name }}'], 'footer' => null,
                ],
            ],
            'certificate' => [
                'label' => 'Certificate', 'description' => 'A framed, wide certificate with signature lines. Good for courses, awards and membership.',
                'kind' => 'certificate', 'subject' => 'contact',
                'values' => [
                    'heading' => 'Certificate of Completion',
                    'body' => "This is to certify that\n\n# {{ contact.name }}\n\nhas successfully completed the course at **{{ workspace.name }}**.\n\nAwarded on {{ today }}",
                    'orientation' => 'landscape', 'font' => 'serif', 'align' => 'center', 'border' => 'double',
                    'signatures' => ['Instructor', 'Director'], 'footer' => null,
                ],
            ],
            'receipt' => [
                'label' => 'Payment receipt', 'description' => 'A receipt for money received against an invoice.',
                'kind' => 'receipt', 'subject' => 'payment',
                'values' => [
                    'heading' => 'Receipt {{ payment.number }}',
                    'body' => "**Received from:** {{ contact.name }}  \n**Date:** {{ payment.date }}  \n**Amount:** {{ payment.amount }}  \n**Paid by:** {{ payment.method }} {{ payment.reference }}  \n**For invoice:** {{ invoice.number }}\n\nBalance still owed: {{ invoice.balance }}\n\nThank you for your payment.",
                    'signatures' => ['Received by'], 'footer' => '{{ workspace.name }} · {{ workspace.phone }}',
                ],
            ],
            'blank' => [
                'label' => 'Blank', 'description' => 'Start from an empty page.',
                'kind' => 'other', 'subject' => 'none',
                'values' => ['heading' => null, 'body' => 'Write here. Use the tags on the right to fill in details.', 'signatures' => [], 'footer' => null],
            ],
        ];
    }

    /**
     * The value of every tag for this subject. With no subject, each tag shows its own label in
     * brackets, which is what the designer's preview uses before anything real is picked.
     *
     * @return array<string, string>
     */
    public static function values(DocumentTemplate $template, ?Model $subject = null, ?User $user = null): array
    {
        $workspace = $template->workspace;
        $values = collect(self::tags($template->subject))->flatMap(fn (array $group) => $group)
            ->map(fn (string $label) => '['.$label.']')->all();

        $values = array_merge($values, [
            'workspace.name' => (string) $workspace->name,
            'workspace.email' => (string) $workspace->email,
            'workspace.phone' => (string) $workspace->phone,
            'workspace.address' => (string) $workspace->address,
            'workspace.city' => (string) $workspace->city,
            'workspace.tax_number' => (string) $workspace->tax_number,
            'today' => now()->format('j F Y'),
            'user.name' => (string) ($user?->name ?? ''),
        ]);

        return match (true) {
            $subject instanceof Contact => array_merge($values, self::contactValues($subject), self::customValues($subject)),
            $subject instanceof Payment => array_merge($values, self::paymentValues($subject), self::contactValues($subject->contact)),
            $subject instanceof Record => array_merge($values, self::recordValues($subject), self::customValues($subject), self::contactValues($subject->contact)),
            default => $values,
        };
    }

    /** Fills in the tags in one line of text (a heading, signature role or footer). */
    public static function fill(?string $text, array $values): string
    {
        return trim((string) preg_replace_callback(self::TAG_PATTERN, fn (array $match) => $values[strtolower($match[1])] ?? '', (string) $text));
    }

    /**
     * The wording as safe HTML: simple formatting (**bold**, *italic*, # big line, lists) is kept,
     * any HTML typed in is shown as text, and each tag is replaced by its escaped value.
     *
     * @param  array<string, string>  $values
     */
    public static function body(DocumentTemplate $template, array $values): HtmlString
    {
        $found = [];
        $marked = preg_replace_callback(self::TAG_PATTERN, function (array $match) use (&$found) {
            $found[] = strtolower($match[1]);

            return 'ZTAG'.(count($found) - 1).'ZEND';
        }, (string) $template->body);

        $html = Str::markdown((string) $marked, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $html = preg_replace_callback('/ZTAG(\d+)ZEND/', fn (array $match) => nl2br(e($values[$found[(int) $match[1]]] ?? '')), $html);

        return new HtmlString((string) $html);
    }

    /**
     * Everything the page needs, for the PDF and for the designer's on-screen preview.
     *
     * @return array<string, mixed>
     */
    public static function page(DocumentTemplate $template, ?Model $subject = null, ?User $user = null, bool $pdf = false): array
    {
        $values = self::values($template, $subject, $user);

        return [
            'template' => $template,
            'workspace' => $template->workspace,
            'heading' => self::fill($template->heading, $values),
            'body' => self::body($template, $values),
            'signatures' => collect($template->signatures ?? [])->map(fn (string $role) => self::fill($role, $values))->filter()->values()->all(),
            'footer' => self::fill($template->footer, $values),
            'logo' => $template->show_logo ? self::logo($template->workspace, $pdf) : null,
            'pdf' => $pdf,
        ];
    }

    public static function render(DocumentTemplate $template, ?Model $subject = null, ?User $user = null): string
    {
        return Pdf::loadView('documents.pdf', self::page($template, $subject, $user, true))
            ->setPaper($template->paper, $template->orientation)
            ->setOption([
                'default_font' => $template->font === 'serif' ? 'dejavu serif' : 'dejavu sans',
                'is_remote_enabled' => false,
                'is_font_subsetting_enabled' => true,
            ])->output();
    }

    public static function filename(DocumentTemplate $template, ?Model $subject = null): string
    {
        $name = match (true) {
            $subject instanceof Contact => $subject->displayName(),
            $subject instanceof Payment => $subject->number,
            $subject instanceof Record => $subject->number,
            default => null,
        };

        return (Str::slug(trim($template->name.' '.$name)) ?: 'document').'.pdf';
    }

    /** The finished PDF, shown in the browser, counted and noted in the audit log. */
    public static function response(DocumentTemplate $template, ?Model $subject = null, ?User $user = null): Response
    {
        $pdf = self::render($template, $subject, $user);
        $template->increment('generated_count');
        Audit::log('default', 'document-generated', 'Printed "'.$template->name.'"'.($subject ? ' for '.self::describe($subject) : ''), $subject ?? $template);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.self::filename($template, $subject).'"',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * The workspace logo for the page. The PDF renderer does not fetch URLs, so it gets the image
     * embedded; only PNG, JPEG and GIF files under 2 MB are embedded.
     */
    public static function logo(?Workspace $workspace, bool $embed = false): ?string
    {
        if (! $workspace?->logo_path) {
            return null;
        }
        if (! $embed) {
            return $workspace->logo_url;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($workspace->logo_path) || $disk->size($workspace->logo_path) > 2 * 1024 * 1024) {
            return null;
        }
        $mime = $disk->mimeType($workspace->logo_path);

        return in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)
            ? 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($workspace->logo_path))
            : null;
    }

    protected static function describe(Model $subject): string
    {
        return match (true) {
            $subject instanceof Contact => $subject->displayName(),
            $subject instanceof Payment => 'payment '.$subject->number,
            $subject instanceof Record => $subject->number.' '.$subject->title,
            default => class_basename($subject),
        };
    }

    /** @return array<string, string> */
    protected static function contactTags(): array
    {
        return [
            'contact.name' => 'Name', 'contact.company' => 'Company', 'contact.email' => 'Email', 'contact.phone' => 'Phone',
            'contact.address' => 'Address', 'contact.city' => 'City', 'contact.tax_number' => 'Their tax number',
        ];
    }

    /** @return array<string, string> */
    protected static function customTags(string $entity): array
    {
        return CustomFields::for($entity)->mapWithKeys(fn (CustomField $field) => ['custom.'.$field->key => $field->label])->all();
    }

    /** @return array<string, string> */
    protected static function contactValues(?Contact $contact): array
    {
        return [
            'contact.name' => (string) $contact?->displayName(),
            'contact.company' => (string) $contact?->company_name,
            'contact.email' => (string) $contact?->email,
            'contact.phone' => (string) ($contact?->phone ?: $contact?->mobile),
            'contact.address' => (string) $contact?->address,
            'contact.city' => (string) $contact?->city,
            'contact.tax_number' => (string) $contact?->tax_number,
        ];
    }

    /** @return array<string, string> */
    protected static function customValues(Model $model): array
    {
        $entity = CustomField::entityFor($model);
        if (! $entity) {
            return [];
        }
        $stored = (array) ($model->getAttribute('custom_fields') ?? []);

        return CustomFields::for($entity, $model->getAttribute('workspace_id'))
            ->mapWithKeys(fn (CustomField $field) => ['custom.'.$field->key => (string) $field->display($stored[$field->key] ?? null)])
            ->all();
    }

    /** @return array<string, string> */
    protected static function paymentValues(Payment $payment): array
    {
        $invoice = $payment->invoice;

        return [
            'payment.number' => (string) $payment->number,
            'payment.amount' => Money::format($payment->amount, $payment->currency_code),
            'payment.date' => $payment->paid_on?->format('j F Y') ?? '',
            'payment.method' => $payment->methodLabel(),
            'payment.reference' => (string) $payment->reference,
            'payment.notes' => (string) $payment->notes,
            'invoice.number' => (string) $invoice?->number,
            'invoice.total' => $invoice ? Money::format($invoice->total, $invoice->currency_code) : '',
            'invoice.balance' => $invoice ? Money::format($invoice->balance, $invoice->currency_code) : '',
        ];
    }

    /** @return array<string, string> */
    protected static function recordValues(Record $record): array
    {
        $values = [
            'record.number' => (string) $record->number,
            'record.title' => (string) $record->title,
            'record.status' => $record->statusLabel(),
            'record.date' => $record->occurs_on?->format('j F Y') ?? '',
            'record.due' => $record->due_on?->format('j F Y') ?? '',
            'record.amount' => $record->amount !== null ? Money::format($record->amount, $record->currency) : '',
        ];
        foreach ($record->definition()->fields as $field) {
            $values['record.'.$field->key] = $record->displayValue($field);
        }

        return $values;
    }

    protected static function entity(string $subject): ?Entity
    {
        if (! str_contains($subject, '.')) {
            return null;
        }
        [$blueprint, $entity] = explode('.', $subject, 2);

        return app(BlueprintRegistry::class)->get($blueprint)?->entity($entity);
    }
}
