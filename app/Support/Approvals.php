<?php

namespace App\Support;

use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Sign-off before a step goes ahead. A rule names the step (such as sending an invoice), the amount
 * it starts at and who may say yes; anyone else asks first. A yes holds while the document's total
 * stays within the amount approved.
 */
class Approvals
{
    /** The subject key for doing $action to this kind of document, such as "invoice.send". */
    public static function subjectFor(Model $model, string $action = 'send'): ?string
    {
        foreach (ApprovalRule::SUBJECTS as $key => $subject) {
            if ($model instanceof $subject['model'] && Str::after($key, '.') === $action) {
                return $key;
            }
        }

        return null;
    }

    /** The strictest active rule covering the document as it stands: the one with the highest limit it reaches. */
    public static function ruleFor(Model $model, string $subject): ?ApprovalRule
    {
        return ApprovalRule::forWorkspace($model->getAttribute('workspace_id'))
            ->where('subject', $subject)->where('is_active', true)
            ->orderByDesc('min_amount')->orderBy('id')->get()
            ->first(fn (ApprovalRule $rule) => $rule->appliesTo($model));
    }

    /** The most recent ask for this step on this document, leaving out withdrawn ones. */
    public static function latest(Model $model, string $subject): ?ApprovalRequest
    {
        return self::requests($model, $subject)->where('status', '!=', 'withdrawn')->latest('id')->first();
    }

    public static function pending(Model $model, string $subject): ?ApprovalRequest
    {
        return self::requests($model, $subject)->pending()->latest('id')->first();
    }

    /** A yes that still covers the document: same currency, and the total no higher than was approved. */
    public static function approval(Model $model, string $subject): ?ApprovalRequest
    {
        $latest = self::latest($model, $subject);

        return $latest
            && $latest->status === 'approved'
            && $latest->currency_code === $model->getAttribute('currency_code')
            && (float) $model->getAttribute('total') <= (float) $latest->amount + 0.005
            ? $latest : null;
    }

    /**
     * The rule standing in the way, or null when the step may go ahead. Only a document still in the
     * stage the step starts from (a draft, for sending) can be held. Without a person, the question
     * is whether the document has been approved at all.
     */
    public static function blocking(Model $model, string $subject, ?User $user = null): ?ApprovalRule
    {
        $while = ApprovalRule::SUBJECTS[$subject]['while'] ?? null;
        if ($while && $model->getAttribute('status') !== $while) {
            return null;
        }

        $rule = self::ruleFor($model, $subject);
        if (! $rule || ($user && $rule->allowsApprovalBy($user)) || self::approval($model, $subject)) {
            return null;
        }

        return $rule;
    }

    public static function request(Model $model, string $subject, User $user, ?string $note = null): ApprovalRequest
    {
        $rule = self::ruleFor($model, $subject);
        $request = ApprovalRequest::create([
            'workspace_id' => $model->getAttribute('workspace_id'),
            'approval_rule_id' => $rule?->id,
            'subject' => $subject,
            'approvable_type' => $model->getMorphClass(),
            'approvable_id' => $model->getKey(),
            'title' => Str::limit(self::titleOf($model), 190, ''),
            'amount' => $model->getAttribute('total'),
            'currency_code' => $model->getAttribute('currency_code'),
            'requested_by' => $user->id,
            'note' => $note,
        ]);

        $workspace = $request->workspace;
        Notifier::send(
            $rule ? $rule->approvers() : Notifier::admins($workspace), 'approvals',
            $user->name.' asks you to approve '.$request->title,
            collect([$request->subjectLabel().($request->amountLabel() ? ' · '.$request->amountLabel() : ''), $note])->filter()->implode(' — '),
            route('approvals.index'), 'circle-check', $workspace, $user,
        );
        Audit::log('default', 'approval-requested', 'Asked for approval of '.$request->title, $model, ['subject' => $subject, 'amount' => $request->amount], $workspace);

        return $request;
    }

    /** Say yes or no. A yes covers the document's total at the moment of deciding. */
    public static function decide(ApprovalRequest $request, User $user, bool $approve, ?string $note = null): ApprovalRequest
    {
        $approvable = $request->approvable;
        $request->forceFill([
            'status' => $approve ? 'approved' : 'rejected',
            'decided_by' => $user->id,
            'decided_at' => now(),
            'decision_note' => $note,
            'amount' => $approve && $approvable ? $approvable->getAttribute('total') : $request->amount,
            'currency_code' => $approve && $approvable ? $approvable->getAttribute('currency_code') : $request->currency_code,
        ])->save();

        $workspace = $request->workspace;
        Notifier::send(
            $request->requester, 'approvals',
            $request->title.($approve ? ' was approved' : ' was turned down'),
            collect([$user->name, $note])->filter()->implode(': '),
            $approvable && method_exists($approvable, 'activityUrl') ? $approvable->activityUrl() : route('approvals.index'),
            $approve ? 'circle-check' : 'circle-x', $workspace, $user,
        );
        Audit::log('default', $approve ? 'approval-granted' : 'approval-refused', ($approve ? 'Approved ' : 'Turned down ').$request->title, $approvable, ['subject' => $request->subject, 'amount' => $request->amount], $workspace);

        return $request;
    }

    public static function withdraw(ApprovalRequest $request): ApprovalRequest
    {
        $request->forceFill(['status' => 'withdrawn'])->save();
        Audit::log('default', 'approval-withdrawn', 'Withdrew the approval request for '.$request->title, $request->approvable, ['subject' => $request->subject], $request->workspace);

        return $request;
    }

    /**
     * Once the step is done, open asks are closed: approved in the name of someone allowed to approve,
     * otherwise withdrawn (the rule was removed or no longer covers the document).
     */
    public static function settle(Model $model, string $subject, User $user, string $note): void
    {
        self::requests($model, $subject)->pending()->get()
            ->each(fn (ApprovalRequest $request) => $request->canBeDecidedBy($user) ? self::decide($request, $user, true, $note) : self::withdraw($request));
    }

    protected static function titleOf(Model $model): string
    {
        return method_exists($model, 'activityLabel') ? $model->activityLabel() : class_basename($model).' #'.$model->getKey();
    }

    /** @return Builder<ApprovalRequest> */
    protected static function requests(Model $model, string $subject): Builder
    {
        return ApprovalRequest::forWorkspace($model->getAttribute('workspace_id'))
            ->where('subject', $subject)
            ->where('approvable_type', $model->getMorphClass())
            ->where('approvable_id', $model->getKey());
    }
}
