<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a signature request's audit trail. Written once and never changed. */
class SignatureEvent extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id', 'signature_request_id', 'signature_signer_id', 'user_id', 'event', 'description', 'ip_address', 'user_agent',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(SignatureSigner::class, 'signature_signer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
