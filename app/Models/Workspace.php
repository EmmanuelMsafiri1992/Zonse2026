<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'type', 'profession_id', 'owner_id', 'email', 'phone', 'website',
        'address', 'city', 'country_code', 'currency_code', 'locale', 'timezone', 'logo_path',
        'tax_number', 'settings', 'onboarding_step', 'onboarded_at', 'trial_ends_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'onboarded_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            $workspace->uuid ??= (string) Str::uuid();
            $workspace->slug ??= static::uniqueSlug($workspace->name);
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug(Str::limit($name, 60, '')) ?: 'workspace';
        $slug = $base;
        $i = 1;
        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    // ---- Relationships -------------------------------------------------

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function profession(): BelongsTo
    {
        return $this->belongsTo(Profession::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->using(WorkspaceMembership::class)
            ->withPivot(['role', 'branch_id', 'job_title', 'joined_at'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function defaultBranch(): HasOne
    {
        return $this->hasOne(Branch::class)->where('is_default', true);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'workspace_module')
            ->withPivot(['enabled_by', 'settings', 'enabled_at'])
            ->withTimestamps();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function settingsRows(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    // ---- Modules --------------------------------------------------------

    /** @return array<int, string> */
    public function enabledModuleKeys(): array
    {
        return $this->modules()->pluck('modules.key')->all();
    }

    public function hasModule(string $key): bool
    {
        $module = Module::findByKey($key);
        if (! $module) {
            return false;
        }
        if ($module->is_core) {
            return true;
        }

        return $this->modules()->where('modules.id', $module->id)->exists();
    }

    /** Enable a list of module keys (dependencies are enabled too). */
    public function enableModules(array $keys, ?User $by = null): void
    {
        $keys = Module::expandDependencies($keys);
        $ids = Module::whereIn('key', $keys)->pluck('id');
        $attach = [];
        foreach ($ids as $id) {
            $attach[$id] = ['enabled_by' => $by?->id, 'enabled_at' => now()];
        }
        $this->modules()->syncWithoutDetaching($attach);
    }

    public function disableModule(string $key): void
    {
        $module = Module::findByKey($key);
        if ($module && ! $module->is_core) {
            $this->modules()->detach($module->id);
        }
    }

    // ---- Subscription helpers ------------------------------------------

    public function plan(): ?Plan
    {
        return $this->subscription?->plan;
    }

    public function onTrial(): bool
    {
        return $this->subscription?->status === 'trialing'
            && $this->subscription->trial_ends_at?->isFuture();
    }

    public function subscriptionActive(): bool
    {
        $sub = $this->subscription;
        if (! $sub) {
            return false;
        }

        return in_array($sub->status, ['active', 'trialing'], true)
            && ($sub->status !== 'trialing' || $sub->trial_ends_at?->isFuture());
    }

    public function isOnboarded(): bool
    {
        return $this->onboarding_step === 0 || $this->onboarded_at !== null;
    }

    // ---- Settings -------------------------------------------------------

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function putSetting(string $key, mixed $value): void
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->settings = $settings;
        $this->save();
    }

    public function getInitialsAttribute(): string
    {
        return collect(explode(' ', trim($this->name)))->filter()->take(2)
            ->map(fn ($p) => Str::upper(Str::substr($p, 0, 1)))->implode('');
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? asset('storage/'.$this->logo_path) : null;
    }
}
