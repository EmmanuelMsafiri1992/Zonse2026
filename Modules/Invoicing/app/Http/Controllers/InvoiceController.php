<?php

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Sms\SmsService;
use App\Support\Approvals;
use App\Support\Lists;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Documents\DocumentPdf;
use Modules\Invoicing\Http\Requests\DocumentRequest;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Item;
use Modules\Invoicing\Models\Payment;
use Modules\Invoicing\Models\TaxRate;
use Modules\Invoicing\Sms\InvoiceTexts;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Invoice::class);
        Invoice::refreshOverdue();

        $filters = $this->filters($request);
        $invoices = $this->filtered($filters)->with('contact')->latest('issue_date')->latest('id')->paginate(20)->withQueryString();

        $stats = [
            'outstanding' => Invoice::query()->open()->sum('balance'),
            'overdue' => Invoice::query()->where('status', 'overdue')->sum('balance'),
            'drafts' => Invoice::query()->where('status', 'draft')->count(),
            'collected' => Payment::query()->whereBetween('paid_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('amount'),
        ];
        $contact = $filters['contact'] ? Contact::query()->find($filters['contact']) : null;

        return view('invoicing::invoices.index', compact('invoices', 'filters', 'stats', 'contact'));
    }

    public function create(Request $request, WorkspaceContext $context): View
    {
        $this->authorize('create', Invoice::class);

        $workspace = $context->getOrFail();
        $invoice = new Invoice([
            'contact_id' => $request->integer('contact') ?: null,
            'issue_date' => today(),
            'due_date' => today()->addDays((int) $workspace->setting('invoicing.due_days', 14)),
            'currency_code' => $workspace->currency_code,
            'terms' => $workspace->setting('invoicing.terms'),
            'notes' => $workspace->setting('invoicing.notes'),
        ]);

        return view('invoicing::invoices.form', $this->formData($invoice));
    }

    public function store(DocumentRequest $request): RedirectResponse
    {
        $this->authorize('create', Invoice::class);

        $invoice = DB::transaction(function () use ($request) {
            $invoice = Invoice::create($request->documentPayload());

            return $invoice->syncLines($request->lines());
        });

        return redirect()->route('invoices.show', $invoice)->with('flash', ['type' => 'success', 'message' => $invoice->number.' was created.']);
    }

    public function show(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        $invoice->load(['contact', 'branch', 'creator', 'lines.item', 'payments.receiver', 'quote', 'comments.user']);

        return view('invoicing::invoices.show', ['invoice' => $invoice, 'methods' => Payment::METHODS]);
    }

    public function edit(Invoice $invoice): View|RedirectResponse
    {
        $this->authorize('update', $invoice);

        if (! $invoice->isEditable()) {
            return redirect()->route('invoices.show', $invoice)->with('flash', ['type' => 'warning', 'message' => 'This invoice has payments or is closed and can no longer be edited.']);
        }
        $invoice->load('lines');

        return view('invoicing::invoices.form', $this->formData($invoice));
    }

    public function update(DocumentRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('update', $invoice);

        if (! $invoice->isEditable()) {
            return redirect()->route('invoices.show', $invoice)->with('flash', ['type' => 'warning', 'message' => 'This invoice can no longer be edited.']);
        }

        DB::transaction(function () use ($request, $invoice) {
            $invoice->update($request->documentPayload());
            $invoice->syncLines($request->lines());
        });

        return redirect()->route('invoices.show', $invoice)->with('flash', ['type' => 'success', 'message' => 'Invoice updated.']);
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->authorize('delete', $invoice);

        if ($invoice->payments()->exists()) {
            return back()->with('flash', ['type' => 'danger', 'message' => 'Remove the payments on this invoice before deleting it.']);
        }
        $number = $invoice->number;
        $invoice->delete();

        return redirect()->route('invoices.index')->with('flash', ['type' => 'success', 'message' => $number.' was deleted.']);
    }

    public function send(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('update', $invoice);
        if ($held = $this->heldForApproval($request, $invoice)) {
            return $held;
        }

        $this->markSent($request, $invoice);

        return back()->with('flash', ['type' => 'success', 'message' => $invoice->number.' is now marked as sent. Share the public link with your customer.']);
    }

    /** Text the invoice, with its view-and-pay link, to the customer's mobile. */
    public function sms(Request $request, Invoice $invoice, SmsService $sms, InvoiceTexts $texts, WorkspaceContext $context): RedirectResponse
    {
        $this->authorize('update', $invoice);

        if (! $sms->enabled($context->getOrFail())) {
            return back()->with('flash', ['type' => 'danger', 'message' => 'Set up SMS in Text messages → SMS settings first.']);
        }
        if ($invoice->status === 'cancelled') {
            return back()->with('flash', ['type' => 'danger', 'message' => 'A cancelled invoice cannot be sent.']);
        }
        if ($held = $this->heldForApproval($request, $invoice)) {
            return $held;
        }

        $message = $texts->sendFor($invoice, 'invoice', $texts->invoice($invoice));
        if (! $message) {
            return back()->with('flash', ['type' => 'warning', 'message' => 'This customer has no usable mobile number. Add one to the contact and try again.']);
        }
        $this->markSent($request, $invoice);

        return back()->with('flash', ['type' => 'success', 'message' => $invoice->number.' was texted to '.$message->to.'.']);
    }

    /** A draft that needs sign-off first goes nowhere until it has it. */
    protected function heldForApproval(Request $request, Invoice $invoice): ?RedirectResponse
    {
        $rule = Approvals::blocking($invoice, 'invoice.send', $request->user());

        return $rule ? back()->with('flash', ['type' => 'warning', 'message' => $invoice->number.' needs approval before it goes out ('.$rule->name.'). Ask for approval below.']) : null;
    }

    protected function markSent(Request $request, Invoice $invoice): void
    {
        $wasDraft = $invoice->status === 'draft';
        $invoice->markSent();
        if ($wasDraft) {
            Approvals::settle($invoice, 'invoice.send', $request->user(), 'Sent it directly.');
        }
    }

    public function cancel(Invoice $invoice): RedirectResponse
    {
        $this->authorize('update', $invoice);

        if ($invoice->amount_paid > 0) {
            return back()->with('flash', ['type' => 'danger', 'message' => 'An invoice with payments cannot be cancelled. Remove the payments first.']);
        }
        $invoice->forceFill(['status' => 'cancelled'])->save();

        return back()->with('flash', ['type' => 'success', 'message' => $invoice->number.' was cancelled.']);
    }

    public function print(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        $invoice->load(['contact', 'branch', 'lines', 'payments']);

        return view('invoicing::invoices.print', ['invoice' => $invoice, 'document' => $invoice, 'kind' => 'invoice']);
    }

    public function pdf(Invoice $invoice): Response
    {
        $this->authorize('view', $invoice);
        $invoice->load(['workspace', 'contact', 'branch', 'lines', 'payments']);

        return DocumentPdf::response($invoice, 'invoice');
    }

    public function comment(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('update', $invoice);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $invoice->addComment($data['body'], $request->user(), true);

        return back()->with('flash', ['type' => 'success', 'message' => 'Note added.']);
    }

    /** @return array{q: string, status: ?string, contact: ?int} */
    protected function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'status' => $request->query('status') ?: null,
            'contact' => $request->integer('contact') ?: null,
        ];
    }

    /**
     * @param  array{q: string, status: ?string, contact: ?int}  $filters
     * @return Builder<Invoice>
     */
    protected function filtered(array $filters): Builder
    {
        $query = Invoice::query()->search($filters['q'])->forContact($filters['contact']);

        return match ($filters['status']) {
            'open' => $query->open(),
            null => $query,
            default => $query->status($filters['status']),
        };
    }

    /** @return array<string, mixed> */
    protected function formData(Invoice $invoice): array
    {
        return [
            'invoice' => $invoice,
            'document' => $invoice,
            'kind' => 'invoice',
            'contacts' => Contact::query()->active()->whereIn('type', ['customer', 'lead', 'other'])->orderBy('name')->get(['id', 'name', 'company_name', 'kind', 'currency_code']),
            'branches' => Branch::query()->orderBy('name')->pluck('name', 'id'),
            'currencies' => Lists::CURRENCIES,
            'items' => Item::query()->active()->with('taxRate')->orderBy('name')->get()->map->toPickerRow()->values(),
            'taxRates' => TaxRate::query()->active()->orderBy('rate')->get(['id', 'name', 'rate', 'is_default']),
            'defaultTaxRate' => TaxRate::defaultRate(),
        ];
    }
}
