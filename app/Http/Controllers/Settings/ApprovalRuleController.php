<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApprovalRuleRequest;
use App\Models\ApprovalRule;
use App\Support\Audit;
use App\Support\Lists;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Which steps need sign-off, from what amount, and by whom. */
class ApprovalRuleController extends Controller
{
    public function __construct(protected WorkspaceContext $context) {}

    public function index(): View
    {
        $workspace = $this->context->getOrFail();
        $rules = ApprovalRule::query()->with('approverUser')->orderBy('subject')->orderBy('min_amount')->get();

        return view('settings.approval-rules.index', [
            'rules' => $rules,
            'subjects' => $this->subjects($rules->pluck('subject')->all()),
            'enabled' => collect(ApprovalRule::subjects())->map(fn (array $subject) => $workspace->hasModule($subject['module'])),
        ]);
    }

    public function create(Request $request): View
    {
        $subject = $request->string('subject')->toString();

        return $this->form(new ApprovalRule([
            'subject' => ApprovalRule::subject($subject) ? $subject : 'invoice.send',
            'currency_code' => $this->context->getOrFail()->currency_code,
        ]));
    }

    public function store(ApprovalRuleRequest $request): RedirectResponse
    {
        $rule = ApprovalRule::create($request->rule());
        Audit::log('settings', 'approval-rule-created', 'Added the approval rule "'.$rule->name.'"', $rule);

        return redirect()->route('settings.approval-rules.index')->with('flash', ['type' => 'success', 'message' => '"'.$rule->name.'" is set up.']);
    }

    public function edit(ApprovalRule $approvalRule): View
    {
        return $this->form($approvalRule);
    }

    public function update(ApprovalRuleRequest $request, ApprovalRule $approvalRule): RedirectResponse
    {
        $approvalRule->update($request->rule());
        Audit::log('settings', 'approval-rule-updated', 'Updated the approval rule "'.$approvalRule->name.'"', $approvalRule);

        return redirect()->route('settings.approval-rules.index')->with('flash', ['type' => 'success', 'message' => 'Rule saved.']);
    }

    public function destroy(ApprovalRule $approvalRule): RedirectResponse
    {
        $approvalRule->delete();
        Audit::log('settings', 'approval-rule-deleted', 'Removed the approval rule "'.$approvalRule->name.'"');

        return redirect()->route('settings.approval-rules.index')->with('flash', ['type' => 'success', 'message' => '"'.$approvalRule->name.'" removed. Requests already made keep their decisions.']);
    }

    protected function form(ApprovalRule $rule): View
    {
        $workspace = $this->context->getOrFail();

        return view('settings.approval-rules.form', [
            'rule' => $rule,
            'subjects' => collect($this->subjects(array_filter([$rule->subject])))->map(fn (array $subject) => $subject['label'])->all(),
            'approvers' => ApprovalRule::APPROVERS,
            'people' => $workspace->members()->orderBy('name')->get()->mapWithKeys(fn ($user) => [$user->id => $user->name.' ('.ucfirst($user->pivot->role).')'])->all(),
            'currencies' => Lists::CURRENCIES,
        ]);
    }

    /**
     * The steps worth showing: invoices and quotes, approving records in the apps that are on, and any
     * step a rule already covers.
     *
     * @param  list<string>  $keep
     * @return array<string, array<string, mixed>>
     */
    protected function subjects(array $keep = []): array
    {
        $workspace = $this->context->getOrFail();

        return collect(ApprovalRule::subjects())
            ->filter(fn (array $subject, string $key) => ! isset($subject['until']) || $workspace->hasModule($subject['module']) || in_array($key, $keep, true))
            ->all();
    }
}
