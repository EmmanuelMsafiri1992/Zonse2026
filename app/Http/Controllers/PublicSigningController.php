<?php

namespace App\Http\Controllers;

use App\Models\SignatureSigner;
use App\Support\Signatures;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page a signer reaches from their emailed link. No account is needed: the long random
 * token is the key, and the workspace context is set from the signer it belongs to.
 */
class PublicSigningController extends Controller
{
    public function __construct(protected WorkspaceContext $context) {}

    public function show(Request $request, string $token): View
    {
        $signer = $this->signer($token);
        Signatures::viewed($signer, $request);
        $signatureRequest = $signer->request->load('signers');

        return view('signatures.sign', [
            'signer' => $signer->fresh(),
            'signatureRequest' => $signatureRequest,
            'canSign' => Signatures::canSign($signer),
            'waitingOn' => $signatureRequest->isSequential() ? Signatures::currentSigners($signatureRequest)->first() : null,
        ]);
    }

    public function document(string $token): Response
    {
        $signer = $this->signer($token);

        return SignatureRequestController::pdf($signer->request->document_path, $signer->request->document_name);
    }

    public function certificate(string $token): Response
    {
        $request = $this->signer($token)->request;
        abort_unless($request->certificate_path, 404);

        return SignatureRequestController::pdf($request->certificate_path, Str::slug($request->title).'-certificate.pdf');
    }

    public function sign(Request $request, string $token): RedirectResponse
    {
        $signer = $this->signer($token);
        $data = $request->validate([
            'signature_type' => ['required', Rule::in(['draw', 'type'])],
            'signature' => ['required', 'string', 'max:'.(int) ceil(Signatures::MAX_DRAWING_BYTES * 1.4)],
            'signed_name' => ['required', 'string', 'max:120'],
            'agree' => ['accepted'],
        ], [
            'signature.required' => 'Add your signature before signing.',
            'agree.accepted' => 'Tick the box to agree to sign electronically.',
        ]);
        if ($data['signature_type'] === 'type' && mb_strlen(trim($data['signature'])) > 120) {
            return back()->withErrors(['signature' => 'Keep your typed signature under 120 characters.']);
        }

        $signatureRequest = Signatures::sign($signer, $data['signature_type'], $data['signature'], $data['signed_name'], $request);

        return redirect()->route('signing.show', $token)->with('flash', ['type' => 'success', 'message' => $signatureRequest->status === 'completed'
            ? 'Thank you. Everyone has signed, and a copy is on its way to your email.'
            : 'Thank you, your signature has been recorded.']);
    }

    public function decline(Request $request, string $token): RedirectResponse
    {
        $signer = $this->signer($token);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        Signatures::decline($signer, $data['reason'] ?? null, $request);

        return redirect()->route('signing.show', $token)->with('flash', ['type' => 'info', 'message' => 'You declined to sign. The sender has been told.']);
    }

    protected function signer(string $token): SignatureSigner
    {
        abort_unless(strlen($token) === 48 && ctype_alnum($token), 404);
        $signer = SignatureSigner::allWorkspaces()->where('token', $token)->with('workspace')->firstOrFail();
        $this->context->set($signer->workspace);

        return $signer->load('request.workspace');
    }
}
