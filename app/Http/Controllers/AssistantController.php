<?php

namespace App\Http\Controllers;

use App\Assistant\AssistantService;
use App\Assistant\AssistantTools;
use App\Blueprints\BlueprintRegistry;
use App\Jobs\AnswerAssistantQuestion;
use App\Models\AssistantConversation;
use App\Models\Record;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The AI assistant: ask questions about the workspace's data, get drafts and summaries.
 * Conversations are private to the person who started them; the assistant can only read.
 */
class AssistantController extends Controller
{
    /** Longest question accepted, in characters. */
    public const MAX_QUESTION = 4000;

    /** Suggested first questions. */
    public const STARTERS = [
        'What is in this workspace?',
        'How much did we spend this month by category?',
        'Who owes us money?',
        'Draft a reminder for overdue invoices',
        'Show recent expenses',
        'List our suppliers',
    ];

    public function __construct(protected WorkspaceContext $context, protected AssistantService $assistant, protected BlueprintRegistry $blueprints) {}

    public function index(Request $request): View
    {
        $this->authorizeUse($request);

        return view('assistant.index', $this->sidebar($request) + ['starters' => self::STARTERS]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeUse($request);
        $data = $request->validate([
            'question' => ['required', 'string', 'max:'.self::MAX_QUESTION],
            'record_id' => ['nullable', 'integer'],
        ]);
        if ($refusal = $this->refusal()) {
            return back()->withInput()->with('flash', $refusal);
        }

        $record = null;
        if (isset($data['record_id'])) {
            $record = Record::query()->findOrFail($data['record_id']);
            abort_unless($request->user()->can('view', $record) && $this->context->hasModule($record->blueprint), 403);
        }

        $conversation = $this->assistant->start($this->context->getOrFail(), $request->user(), $data['question'], $record);

        return redirect()->route('assistant.show', $conversation);
    }

    public function show(Request $request, AssistantConversation $conversation): View
    {
        $this->authorizeConversation($request, $conversation);
        $tools = new AssistantTools($this->context->getOrFail(), $this->blueprints);

        return view('assistant.show', $this->sidebar($request) + [
            'conversation' => $conversation,
            'messages' => $conversation->messages()->get(),
            'toolLabel' => fn (?string $name) => $tools->label((string) $name),
        ]);
    }

    /** A follow-up question in the same conversation. */
    public function ask(Request $request, AssistantConversation $conversation): RedirectResponse
    {
        $this->authorizeConversation($request, $conversation);
        $data = $request->validate(['question' => ['required', 'string', 'max:'.self::MAX_QUESTION]]);
        if ($conversation->isThinking()) {
            return back()->withInput()->with('flash', ['type' => 'warning', 'message' => 'Wait for the answer to the last question first.']);
        }
        if ($refusal = $this->refusal()) {
            return back()->withInput()->with('flash', $refusal);
        }

        $this->assistant->ask($conversation, $data['question']);

        return redirect()->route('assistant.show', $conversation);
    }

    /** Polled while the assistant is thinking. */
    public function status(Request $request, AssistantConversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        return response()->json(['status' => $conversation->status, 'error' => $conversation->error]);
    }

    public function retry(Request $request, AssistantConversation $conversation): RedirectResponse
    {
        $this->authorizeConversation($request, $conversation);
        abort_unless($conversation->status === 'failed', 403);
        if (! $this->assistant->enabled($this->context->getOrFail())) {
            return back()->with('flash', ['type' => 'warning', 'message' => 'The assistant is not set up. Choose a provider in assistant settings first.']);
        }

        $conversation->forceFill(['status' => 'thinking', 'error' => null])->save();
        AnswerAssistantQuestion::dispatch($conversation->id);

        return redirect()->route('assistant.show', $conversation);
    }

    public function destroy(Request $request, AssistantConversation $conversation): RedirectResponse
    {
        $this->authorizeConversation($request, $conversation);
        $conversation->delete();

        return redirect()->route('assistant.index')->with('flash', ['type' => 'success', 'message' => 'Conversation deleted.']);
    }

    /** @return array{type: string, message: string}|null why a new question cannot be asked */
    protected function refusal(): ?array
    {
        $workspace = $this->context->getOrFail();
        if (! $this->assistant->enabled($workspace)) {
            return ['type' => 'warning', 'message' => 'The assistant is not set up yet. A workspace admin can choose a provider in assistant settings.'];
        }
        if ($this->assistant->limitReached($workspace)) {
            return ['type' => 'warning', 'message' => 'This workspace has used its '.$this->assistant->monthlyLimit($workspace).' assistant questions for this month. An admin can raise the limit in assistant settings.'];
        }

        return null;
    }

    /** @return array{conversations: Collection<int, AssistantConversation>, provider: mixed, canConfigure: bool, usage: array<string, int>, limit: int} */
    protected function sidebar(Request $request): array
    {
        $workspace = $this->context->getOrFail();

        return [
            'conversations' => AssistantConversation::query()->where('user_id', $request->user()->id)->latest('last_message_at')->latest('id')->limit(50)->get(),
            'provider' => $this->assistant->provider($workspace),
            'canConfigure' => $request->user()->can('manage-workspace'),
            'usage' => $this->assistant->usage($workspace),
            'limit' => $this->assistant->monthlyLimit($workspace),
        ];
    }

    protected function authorizeUse(Request $request): void
    {
        abort_unless($request->user()->can('use-assistant'), 403);
    }

    protected function authorizeConversation(Request $request, AssistantConversation $conversation): void
    {
        $this->authorizeUse($request);
        abort_unless($conversation->user_id === $request->user()->id, 403);
    }
}
