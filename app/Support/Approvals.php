<?php

namespace App\Support;

use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\Record;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sign-off before a step goes ahead. A rule names the step (such as sending an invoice), the amount
 * it starts at and who may say yes; anyone else asks first. A yes holds while the document's total
 * stays within the amount approved. In the apps, the step is marking a record approved.
 */
class Approvals
{
    /** The figure a rule's limit is measured against: an invoice's total, an app record's amount. */
    public static function amountOf(Model $model): float
    {
        return (float) $model->getAttribute($model instanceof Record ? 'amount' : 'total');
    }

    public static function currencyOf(Model $model): ?string
    {
        return $model->getAttribute($model instanceof Record ? 'currency' : 'currency_code');
    }

    /** Whether the document is still where the step starts from: a draft for sending, not yet approved for a record. */
    public static function inStage(Model $model, string $subject): bool
    {
        $definition = ApprovalRule::subject($subject) ?? [];
        $status = $model->getAttribute('status');

        return isset($definition['until']) ? $status !== $definition['until'] : $status === ($definition['while'] ?? null);
    }

    /**
     * Stops someone who may not approve from marking an app record approved without a yes covering it.
     * Only people are held: imports, queued jobs and the client portal act without a signed-in member.
     *
     * @throws ValidationException
     */
    public static function guardRecord(Record $record): void
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $record->isDirty('status') || $record->status !== ApprovalRule::RECORD_STATUS) {
            return;
        }

        $subject = ApprovalRule::recordSubject((string) $record->blueprint, (string) $record->entity);
        $rule = ApprovalRule::subject($subject) ? self::ruleFor($record, $subject) : null;
        if (! $rule || $rule->allowsApprovalBy($user) || ($record->exists && self::approval($record, $subject))) {
            return;
        }

        throw ValidationException::withMessages(['status' => 'This '.ApprovalRule::subject($subject)['noun'].' needs approval first ("'.$rule->name.'", '
            .mb_strtolower($rule->approverLabel()).'). '.($record->exists ? 'Use "Ask for approval" on its page.' : 'Save it with another status, then ask for approval on its page.')]);
    }

    /** Once a record is marked approved, open asks for it are closed. */
    public static function settleRecord(Record $record): void
    {
        $user = auth()->user();
        if ($user instanceof User && $record->wasChanged('status') && $record->status === ApprovalRule::RECORD_STATUS) {
            self::settle($record, ApprovalRule::recordSubject((string) $record->blueprint, (string) $record->entity), $user, 'Marked it approved.');
        }
    }

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
            && $latest->currency_code === self::currencyOf($model)
            && self::amountOf($model) <= (float) $latest->amount + 0.005
            ? $latest : null;
    }

    /**
     * The rule standing in the way, or null when the step may go ahead. Only a document still in the
     * stage the step starts from (a draft, for sending) can be held. Without a person, the question
     * is whether the document has been approved at all.
     */
    public static function blocking(Model $model, string $subject, ?User $user = null): ?ApprovalRule
    {
        if (! self::inStage($model, $subject)) {
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
            'amount' => self::amountOf($model),
            'currency_code' => self::currencyOf($model),
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
            'amount' => $approve && $approvable ? self::amountOf($approvable) : $request->amount,
            'currency_code' => $approve && $approvable ? self::currencyOf($approvable) : $request->currency_code,
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
