<?php

namespace App\Http\Controllers;

use App\Models\SignatureRequest;
use App\Support\Signatures;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Invoicing\Documents\DocumentPdf;
use Modules\Invoicing\Models\Quote;
use Symfony\Component\HttpFoundation\Response;

/** Sending documents out for signature and following them until everyone has signed. Viewers can only look. */
class SignatureRequestController extends Controller
{
    public const TABS = ['pending' => 'Waiting', 'completed' => 'Signed', 'closed' => 'Declined & cancelled', 'all' => 'All'];

    public function __construct(protected WorkspaceContext $context) {}

    public function index(Request $request): View
    {
        $tab = $request->string('tab')->toString();
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'pending';

        $requests = SignatureRequest::query()->with(['signers', 'creator'])
            ->when($tab === 'pending', fn ($query) => $query->where('status', 'pending'))
            ->when($tab === 'completed', fn ($query) => $query->where('status', 'completed'))
            ->when($tab === 'closed', fn ($query) => $query->whereIn('status', ['declined', 'cancelled', 'expired']))
            ->latest('id')->paginate(25)->withQueryString();

        return view('signatures.index', [
            'tab' => $tab,
            'requests' => $requests,
            'counts' => SignatureRequest::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'canSend' => $this->canSend($request),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($this->canSend($request), 403);
        $quote = $request->filled('quote') ? $this->quote($request->integer('quote')) : null;

        return view('signatures.create', [
            'quote' => $quote,
            'orders' => SignatureRequest::ORDERS,
            'signers' => old('signers', $quote?->contact?->email
                ? [['name' => $quote->contact->displayName(), 'email' => $quote->contact->email]]
                : [['name' => '', 'email' => '']]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canSend($request), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'message' => ['nullable', 'string', 'max:2000'],
            'signing_order' => ['required', Rule::in(array_keys(SignatureRequest::ORDERS))],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'quote_id' => ['nullable', 'integer'],
            'document' => ['required_without:quote_id', 'nullable', 'file', 'mimes:pdf', 'max:10240'],
            'signers' => ['required', 'array', 'min:1', 'max:'.Signatures::MAX_SIGNERS],
            'signers.*.name' => ['required', 'string', 'max:120'],
            'signers.*.email' => ['required', 'email', 'max:190', 'distinct:ignore_case'],
        ], [
            'document.required_without' => 'Choose the PDF to be signed.',
            'signers.*.email.distinct' => 'Each signer needs their own email address.',
        ]);

        $quote = null;
        if (! empty($data['quote_id'])) {
            $quote = $this->quote((int) $data['quote_id']);
            $pdf = DocumentPdf::render($quote->load(['contact', 'branch', 'lines']), 'quote');
            $name = DocumentPdf::filename($quote, 'quote');
        } else {
            $pdf = $request->file('document')->get();
            $name = $request->file('document')->getClientOriginalName();
            if (! str_starts_with($pdf, '%PDF')) {
                return back()->withInput()->withErrors(['document' => 'That file is not a readable PDF.']);
            }
        }

        $signatureRequest = Signatures::create($this->context->getOrFail(), $request->user(), $data, $pdf, $name, $quote);

        return redirect()->route('signatures.show', $signatureRequest)
            ->with('flash', ['type' => 'success', 'message' => 'Sent for signing. Each signer gets their own link by email.']);
    }

    public function show(Request $request, SignatureRequest $signatureRequest): View
    {
        $signatureRequest->load(['signers', 'events.user', 'events.signer', 'creator', 'signable']);

        return view('signatures.show', [
            'signatureRequest' => $signatureRequest,
            'current' => Signatures::currentSigners($signatureRequest)->pluck('id')->all(),
            'intact' => Signatures::isIntact($signatureRequest),
            'canManage' => $this->canManage($request, $signatureRequest),
        ]);
    }

    public function remind(Request $request, SignatureRequest $signatureRequest): RedirectResponse
    {
        abort_unless($this->canManage($request, $signatureRequest) && $signatureRequest->isPending() && ! $signatureRequest->isOverdue(), 403);
        $sent = Signatures::notify($signatureRequest, reminder: true, user: $request->user());

        return back()->with('flash', ['type' => 'success', 'message' => 'Reminder sent to '.$sent.' '.Str::plural('signer', $sent).'.']);
    }

    public function cancel(Request $request, SignatureRequest $signatureRequest): RedirectResponse
    {
        abort_unless($this->canManage($request, $signatureRequest) && $signatureRequest->isPending(), 403);
        Signatures::cancel($signatureRequest, $request->user());

        return back()->with('flash', ['type' => 'success', 'message' => 'Signature request cancelled. The signing links no longer work.']);
    }

    public function document(SignatureRequest $signatureRequest): Response
    {
        return self::pdf($signatureRequest->document_path, $signatureRequest->document_name);
    }

    public function certificate(SignatureRequest $signatureRequest): Response
    {
        abort_unless($signatureRequest->certificate_path, 404);

        return self::pdf($signatureRequest->certificate_path, Str::slug($signatureRequest->title).'-certificate.pdf');
    }

    /** Stream a stored PDF inline; shared with the public signing pages. */
    public static function pdf(string $path, string $name): Response
    {
        $disk = Storage::disk(Signatures::DISK);
        abort_unless($disk->exists($path), 404);

        return response($disk->get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace(['"', '\\', "\r", "\n"], '', $name).'"',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    protected function canSend(Request $request): bool
    {
        return $request->user()->roleIn($this->context->getOrFail()) !== 'viewer';
    }

    /** The sender and workspace admins can remind or cancel. */
    protected function canManage(Request $request, SignatureRequest $signatureRequest): bool
    {
        $user = $request->user();

        return $signatureRequest->created_by === $user->id || $user->isAdminOf($this->context->getOrFail());
    }

    protected function quote(int $id): Quote
    {
        abort_unless($this->context->hasModule('quotes'), 404);

        return Quote::query()->with('contact')->findOrFail($id);
    }
}
