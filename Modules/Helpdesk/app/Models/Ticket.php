<?php

namespace Modules\Helpdesk\Models;

use App\Models\Branch;
use App\Models\Comment;
use App\Models\User;
use App\Support\Sequence;
use App\Tenancy\BelongsToWorkspace;
use App\Tenancy\HasComments;
use App\Tenancy\HasCustomFields;
use App\Tenancy\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Contacts\Models\Contact;
use Modules\Helpdesk\Database\Factories\TicketFactory;

/**
 * A support ticket. Replies to the customer and internal notes both live in
 * the shared comments table: is_internal=false is a reply, true is a note,
 * and a reply with no user is one logged on the customer's behalf.
 */
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use BelongsToWorkspace, HasComments, HasCustomFields, HasFactory, RecordsActivity, SoftDeletes;

    public const STATUSES = ['open' => 'Open', 'pending' => 'Waiting on customer', 'resolved' => 'Resolved', 'closed' => 'Closed'];

    public const ACTIVE_STATUSES = ['open', 'pending'];

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

    public const CHANNELS = ['phone' => 'Phone', 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'walk_in' => 'Walk-in', 'web' => 'Website', 'other' => 'Other'];

    public const NUMBER_PREFIX = 'TKT-';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open', 'priority' => 'normal', 'channel' => 'phone'];

    protected $fillable = [
        'workspace_id', 'branch_id', 'number', 'contact_id', 'requester_name', 'requester_email', 'subject', 'body', 'channel',
        'category', 'status', 'priority', 'assignee_id', 'created_by',
    ];

    /** @var list<string> */
    protected array $activityAttributes = ['status', 'priority', 'assignee_id', 'subject'];

    protected function casts(): array
    {
        return [
            'first_replied_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime', 'last_activity_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Ticket $ticket) {
            $ticket->uuid ??= (string) Str::uuid();
            $ticket->created_by ??= auth()->id();
            $ticket->last_activity_at ??= now();
            $ticket->number ??= Sequence::next('ticket', self::NUMBER_PREFIX, $ticket->workspace_id);
        });

        static::saving(function (Ticket $ticket) {
            if ($ticket->isDirty('status')) {
                $ticket->resolved_at = in_array($ticket->status, ['resolved', 'closed'], true) ? ($ticket->resolved_at ?? now()) : null;
                $ticket->closed_at = $ticket->status === 'closed' ? now() : null;
                $ticket->last_activity_at = now();
            }
        });
    }

    protected static function newFactory(): TicketFactory
    {
        return TicketFactory::new();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
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

    public function scopePriority(Builder $query, ?string $priority): Builder
    {
        return $priority ? $query->where('priority', $priority) : $query;
    }

    public function scopeAssignedTo(Builder $query, int|string|null $userId): Builder
    {
        if ($userId === 'unassigned') {
            return $query->whereNull('assignee_id');
        }

        return $userId ? $query->where('assignee_id', $userId) : $query;
    }

    public function scopeForContact(Builder $query, int|string|null $contactId): Builder
    {
        return $contactId ? $query->where('contact_id', $contactId) : $query;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('number', 'like', "%{$term}%")
                ->orWhere('subject', 'like', "%{$term}%")
                ->orWhere('body', 'like', "%{$term}%")
                ->orWhere('requester_name', 'like', "%{$term}%")
                ->orWhere('requester_email', 'like', "%{$term}%")
                ->orWhereHas('contact', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")->orWhere('company_name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
        });
    }

    /** Urgent first, then the ticket that has waited longest. */
    public function scopeOrderByUrgency(Builder $query): Builder
    {
        return $query->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('last_activity_at')->orderBy('id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function requesterName(): string
    {
        return $this->contact?->displayName() ?? $this->requester_name ?? 'Unknown requester';
    }

    public function requesterEmail(): ?string
    {
        return $this->contact?->email ?? $this->requester_email;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? ucfirst($this->priority);
    }

    public function channelLabel(): string
    {
        return self::CHANNELS[$this->channel] ?? ucfirst($this->channel);
    }

    /** A staff reply goes to the customer and parks the ticket as waiting on them. */
    public function reply(string $body, User $user): Comment
    {
        $comment = $this->addComment($body, $user, false);

        $this->forceFill([
            'first_replied_at' => $this->first_replied_at ?? now(),
            'status' => $this->status === 'open' ? 'pending' : $this->status,
            'last_activity_at' => now(),
        ])->save();

        return $comment;
    }

    /** Something the customer said (logged by staff from a call, email or visit) reopens the ticket. */
    public function customerReply(string $body, ?string $authorName = null): Comment
    {
        $comment = $this->addComment($body, null, false, $authorName ?? $this->requesterName());

        $this->forceFill([
            'status' => $this->status === 'closed' ? 'closed' : 'open',
            'last_activity_at' => now(),
        ])->save();

        return $comment;
    }

    public function note(string $body, User $user): Comment
    {
        $comment = $this->addComment($body, $user, true);
        $this->forceFill(['last_activity_at' => now()])->save();

        return $comment;
    }

    public function activityLabel(): string
    {
        return 'Ticket '.$this->number;
    }

    public function activityUrl(): string
    {
        return route('tickets.show', $this);
    }
}
