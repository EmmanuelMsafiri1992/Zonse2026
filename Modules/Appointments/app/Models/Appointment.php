<?php

namespace Modules\Appointments\Models;

use App\Models\Branch;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\BelongsToWorkspace;
use App\Tenancy\HasComments;
use App\Tenancy\HasCustomFields;
use App\Tenancy\RecordsActivity;
use App\Tenancy\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Appointments\Database\Factories\AppointmentFactory;
use Modules\Contacts\Models\Contact;

/**
 * Times are stored as the workspace's local wall-clock time (no conversion),
 * which is what staff see on the calendar and what customers are told.
 */
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use BelongsToWorkspace, HasComments, HasCustomFields, HasFactory, RecordsActivity, SoftDeletes;

    public const STATUSES = [
        'scheduled' => 'Scheduled', 'confirmed' => 'Confirmed', 'completed' => 'Completed',
        'no_show' => 'No-show', 'cancelled' => 'Cancelled',
    ];

    public const ACTIVE_STATUSES = ['scheduled', 'confirmed'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'scheduled'];

    protected $fillable = [
        'workspace_id', 'branch_id', 'contact_id', 'service_id', 'staff_id', 'title', 'status', 'starts_at', 'ends_at',
        'price', 'notes', 'created_by',
    ];

    /** @var list<string> */
    protected array $activityAttributes = ['status', 'starts_at', 'ends_at', 'staff_id', 'contact_id', 'service_id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'confirmed_at' => 'datetime', 'completed_at' => 'datetime',
            'cancelled_at' => 'datetime', 'price' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Appointment $appointment) {
            $appointment->uuid ??= (string) Str::uuid();
            $appointment->created_by ??= auth()->id();
        });
    }

    protected static function newFactory(): AppointmentFactory
    {
        return AppointmentFactory::new();
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /** "Now" in the workspace's own timezone, to match the stored wall-clock times. */
    public static function localNow(): CarbonImmutable
    {
        $timezone = app(WorkspaceContext::class)->get()?->timezone ?: config('app.timezone');

        return CarbonImmutable::now($timezone);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
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
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $status ? $query->where('status', $status) : $query;
    }

    public function scopeForStaff(Builder $query, int|string|null $staffId): Builder
    {
        return $staffId ? $query->where('staff_id', $staffId) : $query;
    }

    public function scopeForContact(Builder $query, int|string|null $contactId): Builder
    {
        return $contactId ? $query->where('contact_id', $contactId) : $query;
    }

    public function scopeBetween(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query->where('starts_at', '<', $to->format('Y-m-d H:i:s'))->where('ends_at', '>', $from->format('Y-m-d H:i:s'));
    }

    public function scopeOnDay(Builder $query, CarbonImmutable $day): Builder
    {
        return $query->between($day->startOfDay(), $day->endOfDay());
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('title', 'like', $like)
                ->orWhere('notes', 'like', $like)
                ->orWhereHas('contact', fn (Builder $c) => $c->where('name', 'like', $like)->orWhere('company_name', 'like', $like)->orWhere('phone', 'like', $like))
                ->orWhereHas('service', fn (Builder $s) => $s->where('name', 'like', $like));
        });
    }

    /**
     * Other active bookings for the same staff member that overlap this one.
     *
     * @return Builder<Appointment>
     */
    public static function conflictsFor(?int $staffId, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?int $ignoreId = null): Builder
    {
        return static::query()->active()
            ->where('staff_id', $staffId)
            ->between($startsAt, $endsAt)
            ->when($ignoreId, fn (Builder $q) => $q->where('id', '!=', $ignoreId));
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isPast(): bool
    {
        return $this->ends_at->format('Y-m-d H:i:s') < self::localNow()->format('Y-m-d H:i:s');
    }

    /** @return list<string> */
    public function allowedTransitions(): array
    {
        return match ($this->status) {
            'scheduled' => ['confirmed', 'completed', 'no_show', 'cancelled'],
            'confirmed' => ['completed', 'no_show', 'cancelled'],
            'completed', 'no_show' => ['scheduled'],
            'cancelled' => ['scheduled'],
            default => [],
        };
    }

    public function transitionTo(string $status, ?string $reason = null): static
    {
        if (! in_array($status, $this->allowedTransitions(), true)) {
            abort(422, 'An appointment that is '.$this->statusLabel().' cannot be marked '.self::STATUSES[$status].'.');
        }

        $now = now();
        $this->forceFill([
            'status' => $status,
            'confirmed_at' => $status === 'confirmed' ? $now : ($status === 'scheduled' ? null : $this->confirmed_at),
            'completed_at' => $status === 'completed' ? $now : null,
            'cancelled_at' => $status === 'cancelled' ? $now : null,
            'cancel_reason' => $status === 'cancelled' ? $reason : null,
        ])->save();

        return $this;
    }

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    public function displayTitle(): string
    {
        return $this->title ?: ($this->service?->name ?: 'Appointment');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function money(float|int|string|null $amount): string
    {
        return Money::format((float) $amount, $this->workspace?->currency_code ?? app(WorkspaceContext::class)->get()?->currency_code ?? 'USD');
    }

    public function activityLabel(): string
    {
        return 'Appointment '.$this->displayTitle().' · '.$this->starts_at->format('d M H:i');
    }

    public function activityUrl(): string
    {
        return route('appointments.show', $this);
    }
}
