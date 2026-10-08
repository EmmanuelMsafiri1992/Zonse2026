<?php

namespace App\Http\Controllers\Apps;

use App\Blueprints\Blueprint;
use App\Blueprints\BlueprintRegistry;
use App\Blueprints\Entity;
use App\Blueprints\RecordBilling;
use App\Http\Controllers\Controller;
use App\Models\Record;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Invoicing\Models\Payment;

/** What an app does beyond its generic screens: bill a record, take payment, run app actions, print, report. */
class RecordWorkflowController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected BlueprintRegistry $blueprints, protected RecordBilling $billing) {}

    public function bill(string $blueprint, string $entity, int $record): RedirectResponse
    {
        [, $def, $record] = $this->resolve($blueprint, $entity, $record);
        $this->authorize('update', $record);
        abort_unless($def->isBillable(), 404);

        $invoice = $this->billing->invoice($record);

        return back()->with('flash', ['type' => 'success', 'message' => 'Invoice '.$invoice->number.' raised for '.$invoice->money($invoice->total).'.']);
    }

    public function pay(Request $request, string $blueprint, string $entity, int $record): RedirectResponse
    {
        [, $def, $record] = $this->resolve($blueprint, $entity, $record);
        $this->authorize('update', $record);
        abort_unless($def->isBillable(), 404);

        $validated = $request->validate([
            'invoice_id' => ['required', Rule::exists('invoices', 'id')->where('record_id', $record->id)->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'paid_on' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $invoice = $record->invoices()->findOrFail($validated['invoice_id']);
        $payment = $this->billing->pay($invoice, (float) $validated['amount'], $validated['method'], $validated['paid_on'] ?? null, $validated['reference'] ?? null);

        return back()->with('flash', ['type' => 'success', 'message' => 'Payment of '.$invoice->money($payment->amount).' recorded against '.$invoice->number.'.']);
    }

    public function action(Request $request, string $blueprint, string $entity, int $record, string $action): RedirectResponse
    {
        [$app, , $record] = $this->resolve($blueprint, $entity, $record);
        $this->authorize('update', $record);
        abort_unless(array_key_exists($action, $app->logic()->actions($record)), 404);

        $message = $app->logic()->runAction($action, $record, $request);

        return back()->with('flash', ['type' => 'success', 'message' => $message]);
    }

    public function document(string $blueprint, string $entity, int $record, string $document): View
    {
        [$app, $def, $record] = $this->resolve($blueprint, $entity, $record);
        $this->authorize('view', $record);

        $page = $app->logic()->document($document, $record) ?? abort(404);

        return view($page['view'], array_merge($page['data'], [
            'app' => $app, 'def' => $def, 'record' => $record, 'workspace' => $record->workspace,
            'documentTitle' => $app->logic()->documents($record)[$document] ?? 'Document',
        ]));
    }

    public function reports(Request $request, string $blueprint): View
    {
        $app = $this->blueprints->get($blueprint) ?? abort(404);
        $this->authorize('viewAny', Record::class);

        $validated = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : today()->startOfYear();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : today()->endOfDay();

        return view('apps.reports', ['app' => $app, 'from' => $from, 'to' => $to, 'sections' => $app->logic()->reports($from, $to)]);
    }

    /** @return array{0: Blueprint, 1: Entity, 2: Record} */
    protected function resolve(string $blueprint, string $entity, int $id): array
    {
        $app = $this->blueprints->get($blueprint) ?? abort(404);
        $def = $app->entity($entity) ?? abort(404);

        return [$app, $def, Record::query()->ofEntity($app->key, $def->key)->findOrFail($id)];
    }
}
