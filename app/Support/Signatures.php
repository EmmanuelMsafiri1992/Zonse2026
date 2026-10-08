<?php

namespace App\Support;

use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\SignatureCompleted;
use App\Notifications\SignatureRequested;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Models\Quote;

/**
 * Sending a PDF out for signature and everything that happens to it after: who may sign next,
 * the signing itself, declining, cancelling, expiring, and the completion certificate that
 * records the document's fingerprint, each signature and the full audit trail.
 */
class Signatures
{
    /** Files stay on the private disk and are only ever streamed through signed-in or token routes. */
    public const DISK = 'local';

    public const MAX_SIGNERS = 10;

    /** Largest drawn signature accepted, in bytes of PNG. */
    public const MAX_DRAWING_BYTES = 300_000;

    /**
     * @param  array{title: string, message?: ?string, signing_order?: string, expires_in_days?: ?int, signers: list<array{name: string, email: string}>}  $data
     */
    public static function create(Workspace $workspace, User $user, array $data, string $pdf, string $documentName, ?Model $signable = null): SignatureRequest
    {
        $uuid = (string) Str::uuid();
        $path = 'signatures/'.$workspace->id.'/'.$uuid.'.pdf';
        Storage::disk(self::DISK)->put($path, $pdf);

        $request = DB::transaction(function () use ($workspace, $user, $data, $pdf, $documentName, $signable, $uuid, $path) {
            $days = $data['expires_in_days'] ?? null;
            $request = SignatureRequest::create([
                'workspace_id' => $workspace->id,
                'uuid' => $uuid,
                'title' => $data['title'],
                'message' => $data['message'] ?? null,
                'signing_order' => $data['signing_order'] ?? 'parallel',
                'signable_type' => $signable?->getMorphClass(),
                'signable_id' => $signable?->getKey(),
                'document_name' => Str::limit($documentName, 180, ''),
                'document_path' => $path,
                'document_hash' => hash('sha256', $pdf),
                'document_size' => strlen($pdf),
                'created_by' => $user->id,
                'expires_at' => $days ? now()->addDays((int) $days)->endOfDay() : null,
            ]);

            foreach (array_values($data['signers']) as $index => $signer) {
                $request->signers()->create([
                    'workspace_id' => $workspace->id,
                    'name' => trim($signer['name']),
                    'email' => strtolower(trim($signer['email'])),
                    'position' => $index + 1,
                    'token' => Str::random(48),
                ]);
            }

            self::event($request, 'created', 'Sent for signing by '.$user->name.' to '.count($data['signers']).' '.Str::plural('person', count($data['signers'])), user: $user);

            return $request;
        });

        Audit::log('default', 'signature-requested', 'Sent "'.$request->title.'" for signing', $request, workspace: $workspace);
        self::notify($request);

        return $request;
    }

    /** Signers who can sign right now: everyone still to sign, or only the next in line when the order matters. */
    public static function currentSigners(SignatureRequest $request): Collection
    {
        if (! $request->isPending()) {
            return collect();
        }
        $waiting = $request->signers()->get()->reject(fn (SignatureSigner $signer) => $signer->hasFinished())->values();

        return $request->isSequential() ? $waiting->take(1) : $waiting;
    }

    /** Email the signing link to everyone whose turn it is. */
    public static function notify(SignatureRequest $request, bool $reminder = false, ?User $user = null): int
    {
        $signers = self::currentSigners($request);
        foreach ($signers as $signer) {
            $signer->setRelation('request', $request);
            Notification::route('mail', [$signer->email => $signer->name])->notify(new SignatureRequested($signer, $reminder));
            $signer->forceFill(['notified_at' => now()])->save();
            self::event($request, $reminder ? 'reminded' : 'sent', ($reminder ? 'Reminder emailed to ' : 'Signing link emailed to ').$signer->name.' <'.$signer->email.'>', $signer, $user);
        }

        return $signers->count();
    }

    public static function canSign(SignatureSigner $signer): bool
    {
        $request = $signer->request;

        return $request->isPending() && ! $request->isOverdue()
            && self::currentSigners($request)->contains('id', $signer->id);
    }

    /** The first time a signer opens their link. */
    public static function viewed(SignatureSigner $signer, Request $http): void
    {
        if ($signer->status !== 'pending' || ! $signer->request->isPending()) {
            return;
        }
        $signer->forceFill(['status' => 'viewed', 'viewed_at' => now()])->save();
        self::event($signer->request, 'viewed', $signer->name.' opened the document', $signer, http: $http);
    }

    /** The stored file still matches the fingerprint taken when it was sent. */
    public static function isIntact(SignatureRequest $request): bool
    {
        $disk = Storage::disk(self::DISK);

        return $disk->exists($request->document_path)
            && hash_equals($request->document_hash, hash('sha256', $disk->get($request->document_path)));
    }

    /**
     * Record a signature. $value is the typed name for a typed signature, or a PNG data URI for a drawn one.
     *
     * @throws ValidationException when the signature is unreadable, it is not their turn or the file was changed
     */
    public static function sign(SignatureSigner $signer, string $type, string $value, string $signedName, Request $http): SignatureRequest
    {
        $data = match ($type) {
            'draw' => self::drawing($value),
            'type' => trim($value),
            default => throw ValidationException::withMessages(['signature' => 'Choose to draw or type your signature.']),
        };
        if ($data === '') {
            throw ValidationException::withMessages(['signature' => 'Add your signature before signing.']);
        }

        $completed = DB::transaction(function () use ($signer, $type, $data, $signedName, $http) {
            $request = SignatureRequest::allWorkspaces()->lockForUpdate()->findOrFail($signer->signature_request_id);
            $signer = SignatureSigner::allWorkspaces()->lockForUpdate()->findOrFail($signer->id)->setRelation('request', $request);
            if (! self::canSign($signer)) {
                throw ValidationException::withMessages(['signature' => 'This document can no longer be signed from this link.']);
            }
            if (! self::isIntact($request)) {
                throw ValidationException::withMessages(['signature' => 'The document has changed since it was sent, so it cannot be signed. Ask the sender for a new link.']);
            }

            $signer->forceFill([
                'status' => 'signed',
                'signature_type' => $type,
                'signature_data' => $data,
                'signed_name' => trim($signedName),
                'ip_address' => $http->ip(),
                'user_agent' => Str::limit((string) $http->userAgent(), 250, ''),
                'viewed_at' => $signer->viewed_at ?? now(),
                'signed_at' => now(),
            ])->save();
            self::event($request, 'signed', $signer->name.' signed ('.($type === 'draw' ? 'drawn' : 'typed').' signature, as "'.trim($signedName).'")', $signer, http: $http);

            $everyoneSigned = $request->signers()->where('status', '!=', 'signed')->doesntExist();
            if ($everyoneSigned) {
                $request->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
                self::event($request, 'completed', 'Signed by everyone. Document fingerprint confirmed unchanged');
            }

            return $everyoneSigned;
        });

        $request = $signer->request->fresh();
        Notifier::send($request->creator, 'signatures', $signer->name.' signed '.$request->title, $completed ? 'Everyone has now signed.' : null, $request->activityUrl(), 'file-signature', $request->workspace);

        if ($completed) {
            self::complete($request);
        } elseif ($request->isSequential()) {
            self::notify($request);
        }

        return $request->fresh();
    }

    public static function decline(SignatureSigner $signer, ?string $reason, Request $http): SignatureRequest
    {
        $request = $signer->request;
        if (! self::canSign($signer)) {
            throw ValidationException::withMessages(['reason' => 'This document can no longer be declined from this link.']);
        }

        DB::transaction(function () use ($signer, $request, $reason, $http) {
            $signer->forceFill([
                'status' => 'declined', 'declined_at' => now(), 'decline_reason' => $reason ?: null,
                'ip_address' => $http->ip(), 'user_agent' => Str::limit((string) $http->userAgent(), 250, ''),
            ])->save();
            $request->forceFill(['status' => 'declined'])->save();
            self::event($request, 'declined', $signer->name.' declined to sign'.($reason ? ': '.Str::limit($reason, 180) : ''), $signer, http: $http);
        });

        Notifier::send($request->creator, 'signatures', $signer->name.' declined to sign '.$request->title, $reason, $request->activityUrl(), 'file-signature', $request->workspace);
        Webhooks::dispatch('signature.declined', $request);

        return $request;
    }

    public static function cancel(SignatureRequest $request, User $user): void
    {
        $request->forceFill(['status' => 'cancelled'])->save();
        self::event($request, 'cancelled', 'Cancelled by '.$user->name, user: $user);
        Audit::log('default', 'signature-cancelled', 'Cancelled the signature request "'.$request->title.'"', $request);
    }

    /** Close every request whose deadline has passed. Run daily by the scheduler. */
    public static function expireOverdue(): int
    {
        $overdue = SignatureRequest::allWorkspaces()->pending()->whereNotNull('expires_at')->where('expires_at', '<', now())->get();
        foreach ($overdue as $request) {
            $request->forceFill(['status' => 'expired'])->save();
            self::event($request, 'expired', 'Expired before everyone signed');
        }

        return $overdue->count();
    }

    /** Everyone signed: issue the certificate, tell everyone, and accept the quote it came from. */
    protected static function complete(SignatureRequest $request): void
    {
        $path = 'signatures/'.$request->workspace_id.'/'.$request->uuid.'-certificate.pdf';
        Storage::disk(self::DISK)->put($path, self::certificate($request));
        $request->forceFill(['certificate_path' => $path])->save();

        foreach ($request->signers as $signer) {
            $signer->setRelation('request', $request);
            Notification::route('mail', [$signer->email => $signer->name])->notify(new SignatureCompleted($signer));
        }

        $quote = $request->signable;
        if ($quote instanceof Quote && in_array($quote->status, ['draft', 'sent', 'expired'], true)) {
            $quote->accept();
            self::event($request, 'quote-accepted', 'Quote '.$quote->number.' marked as accepted');
        }

        Audit::log('default', 'signature-completed', '"'.$request->title.'" was signed by everyone', $request, workspace: $request->workspace);
        Webhooks::dispatch('signature.completed', $request);
    }

    /** The completion certificate as PDF bytes. */
    public static function certificate(SignatureRequest $request): string
    {
        $request->loadMissing(['signers', 'events', 'workspace', 'creator']);

        return Pdf::loadView('signatures.certificate', ['signatureRequest' => $request])
            ->setPaper('a4')
            ->setOption(['default_font' => 'dejavu sans', 'is_remote_enabled' => false, 'is_font_subsetting_enabled' => true])
            ->output();
    }

    public static function event(SignatureRequest $request, string $event, string $description, ?SignatureSigner $signer = null, ?User $user = null, ?Request $http = null): SignatureEvent
    {
        return SignatureEvent::create([
            'workspace_id' => $request->workspace_id,
            'signature_request_id' => $request->id,
            'signature_signer_id' => $signer?->id,
            'user_id' => $user?->id,
            'event' => $event,
            'description' => Str::limit($description, 250),
            'ip_address' => $http?->ip(),
            'user_agent' => $http ? Str::limit((string) $http->userAgent(), 250, '') : null,
        ]);
    }

    /** A drawn signature arrives as a PNG data URI; keep the checked image as plain base64. */
    protected static function drawing(string $value): string
    {
        $invalid = ValidationException::withMessages(['signature' => 'Your drawn signature could not be read. Please draw it again.']);
        if (! str_starts_with($value, 'data:image/png;base64,')) {
            throw $invalid;
        }
        $binary = base64_decode(substr($value, 22), true);
        if ($binary === false || strlen($binary) > self::MAX_DRAWING_BYTES) {
            throw $invalid;
        }
        $size = @getimagesizefromstring($binary);
        if (! $size || $size[2] !== IMAGETYPE_PNG || $size[0] > 2000 || $size[1] > 1000) {
            throw $invalid;
        }

        return base64_encode($binary);
    }
}
