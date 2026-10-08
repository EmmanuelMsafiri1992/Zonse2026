<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Database\Factories\PortalAccessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Contacts\Models\Contact;

/**
 * Lets one contact (a customer, patient, tenant, parent…) sign in to the workspace's
 * portal with a one-time link emailed to them. No password is ever stored.
 */
class PortalAccess extends Model
{
    /** @use HasFactory<PortalAccessFactory> */
    use BelongsToWorkspace, HasFactory;

    public const AUDIENCES = [
        'customer' => 'Customer', 'vendor' => 'Supplier', 'employee' => 'Employee', 'patient' => 'Patient',
        'student' => 'Student / parent', 'tenant' => 'Tenant', 'member' => 'Member', 'donor' => 'Donor',
    ];

    public const STATUSES = ['active' => 'Active', 'disabled' => 'Disabled'];

    public const LINK_MINUTES = 30;

    protected $fillable = ['workspace_id', 'contact_id', 'audience', 'email', 'status', 'invited_at', 'last_login_at', 'created_by'];

    protected $hidden = ['login_token_hash'];

    protected function casts(): array
    {
        return ['login_token_expires_at' => 'datetime', 'invited_at' => 'datetime', 'last_login_at' => 'datetime'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function audienceLabel(): string
    {
        return self::AUDIENCES[$this->audience] ?? ucfirst($this->audience);
    }

    /** Issues a fresh single-use sign-in token; only its hash is stored. */
    public function issueLoginToken(): string
    {
        $token = Str::random(48);
        $this->forceFill([
            'login_token_hash' => hash('sha256', $token),
            'login_token_expires_at' => now()->addMinutes(self::LINK_MINUTES),
        ])->save();

        return $token;
    }

    /** Finds the access a sign-in link belongs to and uses the link up. */
    public static function redeem(Workspace $workspace, string $token): ?self
    {
        $access = static::forWorkspace($workspace)
            ->where('login_token_hash', hash('sha256', $token))
            ->where('login_token_expires_at', '>', now())
            ->where('status', 'active')
            ->first();

        $access?->forceFill(['login_token_hash' => null, 'login_token_expires_at' => null, 'last_login_at' => now()])->save();

        return $access;
    }
}
