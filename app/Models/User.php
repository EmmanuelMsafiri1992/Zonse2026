<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'avatar_path', 'locale', 'timezone',
        'current_workspace_id', 'last_seen_at',
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'notification_preferences' => 'array',
            'help_tours' => 'array',
            'release_notes_seen_at' => 'datetime',
        ];
    }

    // ---- Relationships -------------------------------------------------

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->using(WorkspaceMembership::class)
            ->withPivot(['role', 'branch_id', 'job_title', 'joined_at'])
            ->withTimestamps();
    }

    /** Google and Microsoft accounts the user can sign in with. */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function ownedWorkspaces()
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    // ---- Helpers --------------------------------------------------------

    public function membershipFor(Workspace|int $workspace): ?WorkspaceMembership
    {
        $id = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return WorkspaceMembership::where('user_id', $this->id)->where('workspace_id', $id)->first();
    }

    public function belongsToWorkspace(Workspace|int $workspace): bool
    {
        return $this->membershipFor($workspace) !== null;
    }

    public function roleIn(Workspace|int $workspace): ?string
    {
        return $this->membershipFor($workspace)?->role;
    }

    public function isOwnerOf(Workspace $workspace): bool
    {
        return $workspace->owner_id === $this->id;
    }

    public function isAdminOf(Workspace $workspace): bool
    {
        return $this->isOwnerOf($workspace) || in_array($this->roleIn($workspace), ['owner', 'admin'], true);
    }

    public function switchWorkspace(Workspace $workspace): void
    {
        $this->forceFill(['current_workspace_id' => $workspace->id])->save();
    }

    public function getInitialsAttribute(): string
    {
        return collect(explode(' ', trim($this->name)))
            ->filter()
            ->take(2)
            ->map(fn ($p) => Str::upper(Str::substr($p, 0, 1)))
            ->implode('');
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? asset('storage/'.$this->avatar_path) : null;
    }
}
