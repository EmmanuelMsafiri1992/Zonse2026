<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Notifications\PublicPageConfirmation;
use App\Support\Approvals;
use App\Support\Branding;
use App\Support\Notifier;
use App\Support\PublicPage;
use App\Tenancy\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Models\Service;
use Modules\Appointments\Support\BookingSettings;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;

/**
 * The workspace's public page (link-in-bio style): visitors book a time, order items or pay
 * an amount without an account. Each becomes a contact plus an appointment or an invoice.
 */
class PublicPageController extends Controller
{
    public function __construct(protected WorkspaceContext $context, protected PublicPage $page) {}

    public function show(Workspace $workspace): View
    {
        $this->open($workspace);

        return view('public-page.show', [
            'settings' => $this->page->settings($workspace),
            'blocks' => $this->page->blocks($workspace),
        ]);
    }

    public function booking(Request $request, Workspace $workspace): View
    {
        $this->open($workspace, 'booking');

        $services = Service::active()->orderBy('name')->get();
        $service = $services->firstWhere('id', $request->integer('service')) ?? ($services->count() === 1 ? $services->first() : null);
        [$first, $last] = $this->page->bookingWindow($workspace);
        $day = $this->day($request->query('date'), $first, $last);

        return view('public-page.booking', [
            'services' => $services,
            'service' => $service,
            'day' => $day,
            'first' => $first,
            'last' => $last,
            'slots' => $service ? $this->page->slots($workspace, $service, $day) : [],
            'note' => BookingSettings::for($workspace)['booking_note'],
        ]);
    }

    public function storeBooking(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->open($workspace, 'booking');

        $data = $request->validate([
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')->where('workspace_id', $workspace->id)->where('is_active', true)->whereNull('deleted_at')],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            ...$this->visitorRules(),
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $service = Service::findOrFail($data['service_id']);
        $startsAt = CarbonImmutable::parse($data['date'].' '.$data['time']);

        $appointment = DB::transaction(function () use ($workspace, $service, $startsAt, $data) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->first();

            if (! in_array($startsAt->format('H:i'), $this->page->slots($workspace, $service, $startsAt), true)) {
                throw ValidationException::withMessages(['time' => 'Sorry, that time has just been taken. Please choose another.']);
            }

            $contact = $this->page->contactFor($workspace, $data);

            return Appointment::create([
                'workspace_id' => $workspace->id,
                'contact_id' => $contact->id,
                'service_id' => $service->id,
                'status' => BookingSettings::for($workspace)['auto_confirm'] ? 'confirmed' : 'scheduled',
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes(max(5, (int) $service->duration_minutes)),
                'price' => $service->price,
                'notes' => trim('Booked online.'."\n".($data['notes'] ?? '')),
            ]);
        });

        $when = $appointment->starts_at->format('l d M Y, H:i');
        $this->tellVisitor($data['email'], $workspace, ($appointment->status === 'confirmed' ? 'Booking confirmed: ' : 'Booking received: ').$service->name, [
            'Your booking for '.$service->name.' with '.$workspace->name.' is '.($appointment->status === 'confirmed' ? 'confirmed' : 'received and waiting for confirmation').'.',
            'When: '.$when.' ('.$service->durationLabel().')',
        ], 'View or cancel my booking', route('public.booking.show', [$workspace, $appointment->uuid]));
        $this->tellTeam($workspace, 'New online booking', $appointment->contact->displayName().' booked '.$service->name.' for '.$when.'.', route('appointments.show', $appointment), 'calendar-check');

        return redirect()->route('public.booking.show', [$workspace, $appointment->uuid])
            ->with('flash', ['type' => 'success', 'message' => 'You are booked in. We have emailed the details to '.$data['email'].'.']);
    }

    public function showBooking(Workspace $workspace, string $uuid): View
    {
        $this->open($workspace);
        $appointment = Appointment::with('service')->where('uuid', $uuid)->firstOrFail();

        return view('public-page.booked', [
            'appointment' => $appointment,
            'cancellable' => $appointment->isActive() && ! $appointment->isPast(),
        ]);
    }

    public function cancelBooking(Workspace $workspace, string $uuid): RedirectResponse
    {
        $this->open($workspace);
        $appointment = Appointment::with('service', 'contact')->where('uuid', $uuid)->firstOrFail();
        abort_unless($appointment->isActive() && ! $appointment->isPast(), 422, 'This booking can no longer be cancelled online.');

        $appointment->transitionTo('cancelled', 'Cancelled online by the customer.');
        $this->tellTeam($workspace, 'Online booking cancelled', ($appointment->contact?->displayName() ?? 'A customer').' cancelled '.$appointment->displayTitle().' on '.$appointment->starts_at->format('D d M, H:i').'.', route('appointments.show', $appointment), 'calendar-x');

        return back()->with('flash', ['type' => 'success', 'message' => 'Your booking has been cancelled.']);
    }

    public function order(Workspace $workspace): View
    {
        $this->open($workspace, 'ordering');

        return view('public-page.order', ['items' => $this->page->orderableItems($workspace)]);
    }

    public function storeOrder(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->open($workspace, 'ordering');

        $items = $this->page->orderableItems($workspace)->keyBy('id');
        $data = $request->validate([
            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'integer', 'min:0', 'max:999'],
            ...$this->visitorRules(),
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $lines = [];
        foreach ($data['quantities'] as $itemId => $quantity) {
            $item = $items->get((int) $itemId);
            if (! $item || ! $quantity) {
                continue;
            }
            if ($item->tracksStock() && $quantity > (float) $item->stock_qty) {
                throw ValidationException::withMessages(['quantities.'.$itemId => 'Only '.rtrim(rtrim(number_format((float) $item->stock_qty, 3), '0'), '.').' of '.$item->name.' left.']);
            }
            $lines[] = ['item_id' => $item->id, 'description' => $item->name, 'quantity' => $quantity, 'unit' => $item->unit, 'unit_price' => (float) $item->price, 'tax_rate' => (float) ($item->taxRate?->rate ?? 0)];
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['quantities' => 'Choose at least one item.']);
        }

        $invoice = $this->invoiceFor($workspace, $data, $lines, 'Online order');

        return $this->afterInvoice($workspace, $invoice, $data['email'], 'Your order with '.$workspace->name, 'New online order');
    }

    public function payment(Workspace $workspace): View
    {
        $this->open($workspace, 'payment');

        return view('public-page.pay');
    }

    public function storePayment(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->open($workspace, 'payment');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'purpose' => ['required', 'string', 'max:150'],
            ...$this->visitorRules(),
        ]);

        $invoice = $this->invoiceFor($workspace, $data, [
            ['description' => $data['purpose'], 'quantity' => 1, 'unit_price' => round((float) $data['amount'], 2), 'tax_rate' => 0],
        ], 'Online payment');

        return $this->afterInvoice($workspace, $invoice, $data['email'], 'Your payment to '.$workspace->name, 'New online payment request');
    }

    public function received(Workspace $workspace, string $uuid): View
    {
        $this->open($workspace);

        return view('public-page.received', ['invoice' => Invoice::where('uuid', $uuid)->firstOrFail()]);
    }

    /**
     * Load the workspace for a visitor: the page must be switched on (and the block, when given).
     */
    protected function open(Workspace $workspace, ?string $block = null): void
    {
        abort_unless($workspace->is_active, 404);
        $this->context->set($workspace);
        abort_unless($block ? $this->page->shows($workspace, $block) : $this->page->settings($workspace)['enabled'], 404);

        ViewFacade::share([
            'publicWorkspace' => $workspace,
            'brand' => app(Branding::class)->for($workspace),
            'publicBlocks' => $this->page->blocks($workspace),
        ]);
    }

    /** @return array<string, list<mixed>> */
    protected function visitorRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['prohibited'],
        ];
    }

    protected function day(?string $date, CarbonImmutable $first, CarbonImmutable $last): CarbonImmutable
    {
        try {
            $day = $date ? CarbonImmutable::createFromFormat('!Y-m-d', $date) : $first;
        } catch (\Throwable) {
            $day = $first;
        }

        return $day && $day->betweenIncluded($first, $last) ? $day : $first;
    }

    /**
     * @param  array{name: string, email: string, phone?: ?string, notes?: ?string}  $visitor
     * @param  list<array<string, mixed>>  $lines
     */
    protected function invoiceFor(Workspace $workspace, array $visitor, array $lines, string $reference): Invoice
    {
        return DB::transaction(function () use ($workspace, $visitor, $lines, $reference) {
            $contact = $this->page->contactFor($workspace, $visitor);

            $invoice = Invoice::create([
                'workspace_id' => $workspace->id,
                'contact_id' => $contact->id,
                'reference' => $reference,
                'notes' => $visitor['notes'] ?? null,
                'issue_date' => today(),
            ]);

            return $invoice->syncLines($lines);
        });
    }

    /**
     * Send the invoice so the visitor can pay it straight away, unless an approval rule holds
     * invoices back; then they get a "received" page and the team is asked to look at it.
     */
    protected function afterInvoice(Workspace $workspace, Invoice $invoice, string $email, string $subject, string $teamTitle): RedirectResponse
    {
        $held = Approvals::blocking($invoice, 'invoice.send') !== null;
        if (! $held) {
            $invoice->markSent();
        }
        $invoice->load('contact');

        $this->tellTeam($workspace, $teamTitle, $invoice->contact->displayName().' · '.$invoice->number.' · '.$invoice->money($invoice->total).($held ? ' (waiting for approval before it is sent)' : ''), route('invoices.show', $invoice), 'shopping-bag');

        if ($held) {
            $this->tellVisitor($email, $workspace, $subject, [
                'Thank you. We have received your request ('.$invoice->number.', '.$invoice->money($invoice->total).').',
                $workspace->name.' will email your invoice once it has been checked.',
            ]);

            return redirect()->route('public.received', [$workspace, $invoice->uuid]);
        }

        $this->tellVisitor($email, $workspace, $subject, [
            'Thank you. Here is your invoice '.$invoice->number.' for '.$invoice->money($invoice->total).'.',
        ], 'View and pay', $invoice->publicUrl());

        return redirect()->to($invoice->publicUrl());
    }

    /** @param  list<string>  $lines */
    protected function tellVisitor(string $email, Workspace $workspace, string $subject, array $lines, ?string $action = null, ?string $url = null): void
    {
        try {
            Notification::route('mail', mb_strtolower($email))->notify(new PublicPageConfirmation($workspace, $subject, $lines, $action, $url));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function tellTeam(Workspace $workspace, string $title, string $body, string $url, string $icon): void
    {
        Notifier::send(Notifier::admins($workspace), 'bookings', $title, $body, $url, $icon, $workspace);
    }
}
