<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Support\Approvals;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Asking for sign-off, and the inbox where approvers say yes or no. */
class ApprovalController extends Controller
{
    use AuthorizesRequests;

    public const TABS = ['waiting' => 'Waiting for me', 'mine' => 'My requests', 'all' => 'All requests'];

    public function __construct(protected WorkspaceContext $context) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $workspace = $this->context->getOrFail();
        $isAdmin = $user->isAdminOf($workspace);
        $tab = $request->string('tab')->toString();
        $tab = array_key_exists($tab, self::TABS) && ($tab !== 'all' || $isAdmin) ? $tab : 'waiting';

        $with = ['approvable', 'requester', 'decider', 'rule'];
        $waiting = ApprovalRequest::query()->pending()->with($with)->oldest('id')->get()
            ->filter(fn (ApprovalRequest $approval) => $approval->canBeDecidedBy($user))->values();

        $requests = match ($tab) {
            'mine' => ApprovalRequest::query()->where('requested_by', $user->id)->with($with)->latest('id')->paginate(25)->withQueryString(),
            'all' => ApprovalRequest::query()->with($with)->latest('id')->paginate(25)->withQueryString(),
            default => null,
        };

        return view('approvals.index', [
            'tab' => $tab,
            'tabs' => collect(self::TABS)->except($isAdmin ? [] : ['all'])->all(),
            'waiting' => $waiting,
            'requests' => $requests,
            'hasRules' => ApprovalRule::query()->where('is_active', true)->exists(),
            'isAdmin' => $isAdmin,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', Rule::in(array_keys(ApprovalRule::SUBJECTS))],
            'id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $subject = ApprovalRule::SUBJECTS[$data['subject']];
        abort_unless($this->context->hasModule($subject['module']), 404);

        $model = $subject['model']::query()->findOrFail($data['id']);
        $this->authorize('update', $model);

        if ($model->getAttribute('status') !== 'draft') {
            return back()->with('flash', ['type' => 'warning', 'message' => 'Only a draft '.$subject['noun'].' needs approval before it goes out.']);
        }
        if (Approvals::pending($model, $data['subject'])) {
            return back()->with('flash', ['type' => 'info', 'message' => 'This '.$subject['noun'].' is already waiting for approval.']);
        }
        if (! Approvals::blocking($model, $data['subject'], $request->user())) {
            return back()->with('flash', ['type' => 'info', 'message' => 'This '.$subject['noun'].' does not need approval. You can send it.']);
        }

        Approvals::request($model, $data['subject'], $request->user(), $data['note'] ?? null);

        return back()->with('flash', ['type' => 'success', 'message' => 'Sent for approval. You will be told as soon as it is decided.']);
    }

    public function approve(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        return $this->decide($request, $approvalRequest, true);
    }

    public function reject(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        return $this->decide($request, $approvalRequest, false);
    }

    public function withdraw(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $user = $request->user();
        abort_unless($approvalRequest->isPending() && ($approvalRequest->requested_by === $user->id || $user->isAdminOf($this->context->getOrFail())), 403);

        Approvals::withdraw($approvalRequest);

        return back()->with('flash', ['type' => 'success', 'message' => 'Request withdrawn.']);
    }

    protected function decide(Request $request, ApprovalRequest $approval, bool $approve): RedirectResponse
    {
        abort_unless($approval->canBeDecidedBy($request->user()), 403);
        $note = $request->validate(['decision_note' => ['nullable', 'string', 'max:1000']])['decision_note'] ?? null;

        if ($approve && ! $approval->approvable) {
            return back()->with('flash', ['type' => 'danger', 'message' => 'The '.(ApprovalRule::SUBJECTS[$approval->subject]['noun'] ?? 'document').' has been deleted, so it can only be turned down.']);
        }

        Approvals::decide($approval, $request->user(), $approve, $note);

        return back()->with('flash', ['type' => 'success', 'message' => $approval->title.($approve ? ' approved.' : ' turned down.')]);
    }
}
