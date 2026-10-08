<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutomationRequest;
use App\Models\Automation;
use App\Sms\SmsService;
use App\Support\Audit;
use App\Support\Automations;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Tasks\Models\Task;

/** "When this happens, do that" rules for the workspace. */
class AutomationController extends Controller
{
    /**
     * Ready-made rules people can start from.
     *
     * @var array<string, array{name: string, blurb: string, trigger: string, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>}>
     */
    public const RECIPES = [
        'lead-follow-up' => [
            'name' => 'Follow up new leads', 'blurb' => 'When a lead is added, make a task to call them tomorrow.',
            'trigger' => 'contact.created',
            'conditions' => [['field' => 'type', 'operator' => 'equals', 'value' => 'lead']],
            'actions' => [['type' => 'create_task', 'title' => 'Call {{name}}', 'description' => 'New lead. Phone: {{phone}}', 'assignee' => '', 'due_in_days' => 1, 'priority' => 'high']],
        ],
        'urgent-ticket' => [
            'name' => 'Alert admins about urgent tickets', 'blurb' => 'When an urgent ticket comes in, tell the owners and admins straight away.',
            'trigger' => 'ticket.created',
            'conditions' => [['field' => 'priority', 'operator' => 'equals', 'value' => 'urgent']],
            'actions' => [['type' => 'notify', 'to' => 'admins', 'message' => 'Urgent ticket {{number}}: {{subject}}']],
        ],
        'thank-payment' => [
            'name' => 'Thank customers for payments', 'blurb' => 'When a payment is recorded, email the customer a thank-you.',
            'trigger' => 'payment.received',
            'conditions' => [],
            'actions' => [['type' => 'send_email', 'subject' => 'Thank you for your payment', 'body' => "Hello {{contact_name}},\n\nWe have received your payment of {{currency_code}} {{amount}}. Thank you!"]],
        ],
        'resolved-ticket' => [
            'name' => 'Close the loop on resolved tickets', 'blurb' => 'When a ticket is resolved, email the customer to let them know.',
            'trigger' => 'ticket.updated',
            'conditions' => [['field' => 'status', 'operator' => 'changed', 'value' => null], ['field' => 'status', 'operator' => 'equals', 'value' => 'resolved']],
            'actions' => [['type' => 'send_email', 'subject' => 'Ticket {{number}} is resolved', 'body' => "Hello {{contact_name}},\n\nYour request \"{{subject}}\" has been resolved. Just reply if you need anything else."]],
        ],
    ];

    public function __construct(protected WorkspaceContext $context) {}

    public function index(): View
    {
        return view('settings.automations.index', [
            'automations' => Automation::query()->withCount(['runs as failed_count' => fn ($query) => $query->where('status', 'failed')->where('created_at', '>=', now()->subWeek())])->orderBy('name')->get(),
            'recipes' => self::RECIPES,
        ]);
    }

    public function create(Request $request): View
    {
        $recipe = self::RECIPES[$request->string('recipe')->toString()] ?? null;

        return $this->form(new Automation($recipe ? collect($recipe)->except('blurb')->all() + ['is_active' => true] : [
            'trigger' => 'contact.created', 'conditions' => [], 'is_active' => true,
            'actions' => [['type' => 'notify', 'to' => 'admins', 'message' => '']],
        ]));
    }

    public function store(AutomationRequest $request): RedirectResponse
    {
        if (Automation::query()->count() >= Automations::MAX_AUTOMATIONS) {
            return back()->withInput()->withErrors(['name' => 'This workspace already has '.Automations::MAX_AUTOMATIONS.' automations.']);
        }

        $automation = Automation::create($request->automation());
        Audit::log('settings', 'automation-created', 'Created the automation "'.$automation->name.'"', $automation);

        return redirect()->route('settings.automations.index')->with('flash', ['type' => 'success', 'message' => 'Automation saved'.($automation->is_active ? ' and switched on.' : '.')]);
    }

    public function edit(Automation $automation): View
    {
        return $this->form($automation);
    }

    public function update(AutomationRequest $request, Automation $automation): RedirectResponse
    {
        $automation->update($request->automation());
        Audit::log('settings', 'automation-updated', 'Updated the automation "'.$automation->name.'"', $automation);

        return redirect()->route('settings.automations.edit', $automation)->with('flash', ['type' => 'success', 'message' => 'Automation saved.']);
    }

    public function toggle(Automation $automation): RedirectResponse
    {
        $automation->update(['is_active' => ! $automation->is_active]);
        Audit::log('settings', 'automation-'.($automation->is_active ? 'enabled' : 'disabled'), ($automation->is_active ? 'Switched on' : 'Switched off').' the automation "'.$automation->name.'"', $automation);

        return back()->with('flash', ['type' => 'success', 'message' => '"'.$automation->name.'" is now '.($automation->is_active ? 'on.' : 'off.')]);
    }

    public function destroy(Automation $automation): RedirectResponse
    {
        $automation->delete();
        Audit::log('settings', 'automation-deleted', 'Deleted the automation "'.$automation->name.'"');

        return redirect()->route('settings.automations.index')->with('flash', ['type' => 'success', 'message' => 'Automation deleted.']);
    }

    protected function form(Automation $automation): View
    {
        $workspace = $this->context->getOrFail();

        return view('settings.automations.form', [
            'automation' => $automation,
            'runs' => $automation->exists ? $automation->runs()->latest('id')->limit(30)->get() : collect(),
            'builder' => [
                'triggers' => Automations::TRIGGERS,
                'fields' => Automations::FIELDS,
                'operators' => Automations::OPERATORS,
                'valueless' => Automations::VALUELESS,
                'actions' => Automations::ACTIONS,
                'recipients' => Automations::RECIPIENTS,
                'updatable' => Automations::UPDATABLE,
                'priorities' => Task::PRIORITIES,
                'people' => $workspace->members()->orderBy('name')->pluck('name', 'users.id'),
                'placeholders' => collect(Automations::TRIGGERS)->mapWithKeys(fn ($label, $event) => [$event => Automations::placeholders($event)]),
                'smsOn' => app(SmsService::class)->enabled($workspace),
                'tasksOn' => $workspace->hasModule('tasks'),
            ],
        ]);
    }
}
