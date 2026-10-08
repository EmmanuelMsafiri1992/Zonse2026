<?php

namespace App\Models;

use App\Support\Money;
use App\Tenancy\BelongsToWorkspace;
use Database\Factories\ApprovalRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Quote;

/**
 * "This needs a yes first": a step, such as sending an invoice, that someone must sign off
 * when the amount reaches a limit. People who may approve skip the step for their own work.
 */
class ApprovalRule extends Model
{
    /** @use HasFactory<ApprovalRuleFactory> */
    use BelongsToWorkspace, HasFactory;

    /** @var array<string, array{label: string, noun: string, module: string, model: class-string<Model>, while: string}> */
    public const SUBJECTS = [
        'invoice.send' => ['label' => 'Sending an invoice', 'noun' => 'invoice', 'module' => 'invoicing', 'model' => Invoice::class, 'while' => 'draft'],
        'quote.send' => ['label' => 'Sending a quote', 'noun' => 'quote', 'module' => 'quotes', 'model' => Quote::class, 'while' => 'draft'],
    ];

    /** @var array<string, string> */
    public const APPROVERS = [
        'admins' => 'Owners and admins',
        'managers' => 'Managers, admins and owners',
        'person' => 'One named person',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['min_amount' => 0, 'approver' => 'admins', 'is_active' => true];

    protected $fillable = ['workspace_id', 'name', 'subject', 'min_amount', 'currency_code', 'approver', 'approver_id', 'is_active'];

    protected function casts(): array
    {
        return ['min_amount' => 'float', 'is_active' => 'boolean'];
    }

    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function subjectLabel(): string
    {
        return self::SUBJECTS[$this->subject]['label'] ?? $this->subject;
    }

    public function approverLabel(): string
    {
        return $this->approver === 'person'
            ? ($this->approverUser?->name ?? 'The owner')
            : (self::APPROVERS[$this->approver] ?? $this->approver);
    }

    /** "Over US$500.00", "Any amount in ZWG", … */
    public function limitLabel(): string
    {
        if ($this->min_amount <= 0) {
            return $this->currency_code ? 'Any amount in '.$this->currency_code : 'Any amount';
        }

        return 'From '.Money::format($this->min_amount, $this->currency_code);
    }

    public function appliesTo(Model $model): bool
    {
        if (! $this->is_active || $model->getAttribute('workspace_id') !== $this->workspace_id) {
            return false;
        }
        if ($this->currency_code && $this->currency_code !== $model->getAttribute('currency_code')) {
            return false;
        }

        return (float) $model->getAttribute('total') >= $this->min_amount;
    }

    /** The owner may always approve, so a rule never gets stuck when its named person leaves. */
    public function allowsApprovalBy(User $user): bool
    {
        $workspace = $this->workspace;
        if (! $workspace || ! $user->belongsToWorkspace($workspace)) {
            return false;
        }
        if ($user->isOwnerOf($workspace)) {
            return true;
        }

        return match ($this->approver) {
            'person' => $this->approver_id === $user->id,
            'managers' => in_array($user->roleIn($workspace), ['owner', 'admin', 'manager'], true),
            default => in_array($user->roleIn($workspace), ['owner', 'admin'], true),
        };
    }

    /** @return Collection<int, User> */
    public function approvers(): Collection
    {
        return $this->workspace->members()->get()->filter(fn (User $user) => $this->allowsApprovalBy($user))->values();
    }
}
