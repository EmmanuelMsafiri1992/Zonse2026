<?php

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Http\Requests\PaymentRequest;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Payment;

class PaymentController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Payment::class);

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'method' => $request->query('method') ?: null,
            'from' => $request->query('from') ?: null,
            'to' => $request->query('to') ?: null,
        ];

        $query = Payment::query()->with(['invoice', 'contact', 'receiver'])
            ->when($filters['method'], fn (Builder $q, string $method) => $q->where('method', $method))
            ->when($filters['from'], fn (Builder $q, string $date) => $q->whereDate('paid_on', '>=', $date))
            ->when($filters['to'], fn (Builder $q, string $date) => $q->whereDate('paid_on', '<=', $date))
            ->when($filters['q'], function (Builder $q, string $term) {
                $like = '%'.$term.'%';
                $q->where(function (Builder $w) use ($like) {
                    $w->where('number', 'like', $like)
                        ->orWhere('reference', 'like', $like)
                        ->orWhereHas('invoice', fn (Builder $i) => $i->where('number', 'like', $like))
                        ->orWhereHas('contact', fn (Builder $c) => $c->where('name', 'like', $like)->orWhere('company_name', 'like', $like));
                });
            });

        $total = (clone $query)->sum('amount');
        $payments = $query->latest('paid_on')->latest('id')->paginate(25)->withQueryString();

        return view('invoicing::payments.index', ['payments' => $payments, 'filters' => $filters, 'total' => $total, 'methods' => Payment::METHODS]);
    }

    public function store(PaymentRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('pay', $invoice);

        if ($invoice->status === 'draft') {
            $invoice->markSent();
        }

        $payment = $invoice->payments()->create($request->validated() + [
            'workspace_id' => $invoice->workspace_id,
            'contact_id' => $invoice->contact_id,
            'currency_code' => $invoice->currency_code,
            'received_by' => $request->user()->id,
        ]);

        $invoice->refresh();
        $message = $invoice->status === 'paid'
            ? $invoice->number.' is now fully paid.'
            : 'Payment of '.$payment->money().' recorded. '.$invoice->money($invoice->balance).' still outstanding.';

        return redirect()->route('invoices.show', $invoice)->with('flash', ['type' => 'success', 'message' => $message]);
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        $this->authorize('delete', $payment);

        $invoice = $payment->invoice;
        $payment->delete();

        return redirect()->route('invoices.show', $invoice)->with('flash', ['type' => 'success', 'message' => 'Payment removed and the invoice balance updated.']);
    }
}
