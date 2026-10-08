<?php

namespace Modules\Contacts\Models;

use App\Models\Branch;
use App\Models\User;
use App\Tenancy\BelongsToWorkspace;
use App\Tenancy\HasComments;
use App\Tenancy\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Contacts\Database\Factories\ContactFactory;

class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use BelongsToWorkspace, HasComments, HasFactory, RecordsActivity, SoftDeletes;

    public const TYPES = ['customer' => 'Customer', 'supplier' => 'Supplier', 'lead' => 'Lead', 'other' => 'Other'];

    public const KINDS = ['person' => 'Person', 'company' => 'Company / organisation'];

    protected $fillable = [
        'workspace_id', 'branch_id', 'type', 'kind', 'name', 'company_name', 'email', 'phone', 'mobile', 'tax_number',
        'address', 'city', 'country_code', 'currency_code', 'tags', 'notes', 'is_active', 'created_by',
    ];

    /** @var list<string> */
    protected array $activityAttributes = ['name', 'type', 'email', 'phone', 'is_active'];

    protected function casts(): array
    {
        return ['tags' => 'array', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (Contact $contact) {
            $contact->created_by ??= auth()->id();
        });
    }

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return $type ? $query->where('type', $type) : $query;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $like = '%'.$term.'%';
            $q->where('name', 'like', $like)
                ->orWhere('company_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('mobile', 'like', $like)
                ->orWhere('tax_number', 'like', $like);
        });
    }

    public function displayName(): string
    {
        return $this->kind === 'company' && $this->company_name ? $this->company_name : $this->name;
    }

    public function initials(): string
    {
        return collect(explode(' ', $this->displayName()))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function activityLabel(): string
    {
        return 'Contact '.$this->displayName();
    }

    public function activityUrl(): string
    {
        return route('contacts.show', $this);
    }
}
