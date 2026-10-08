<?php

namespace Tests\Feature;

use App\Assistant\AssistantService;
use App\Assistant\AssistantTools;
use App\Blueprints\BlueprintRegistry;
use App\Models\AssistantConversation;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** The AI assistant: answering questions from the workspace's data, drafting, summarising, and keeping chats private. */
class AssistantTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, string>  $credentials
     * @return array{0: User, 1: Workspace}
     */
    protected function workspace(?string $provider = 'test', array $credentials = []): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['expenses', 'contacts', 'invoicing'], $owner);
        if ($provider) {
            app(AssistantService::class)->configure($workspace, $provider, [$provider => $credentials]);
        }

        return [$owner, $workspace->fresh()];
    }

    protected function expense(Workspace $workspace, string $title, float $amount, string $category, array $attributes = []): Record
    {
        return Record::factory()->ofEntity('expenses', 'expenses', ['category' => $category])
            ->create(['workspace_id' => $workspace->id, 'title' => $title, 'status' => 'approved', 'amount' => $amount, 'currency' => 'USD', 'occurs_on' => today(), ...$attributes])->fresh();
    }

    protected function ask(User $user, string $question, array $extra = []): AssistantConversation
    {
        $this->actingAs($user)->post(route('assistant.store'), ['question' => $question] + $extra)->assertRedirect();

        return AssistantConversation::allWorkspaces()->latest('id')->firstOrFail();
    }

    protected function reply(AssistantConversation $conversation): string
    {
        return (string) $conversation->messages()->where('role', 'assistant')->whereNotNull('content')->reorder()->latest('id')->value('content');
    }

    public function test_test_mode_adds_up_spending_by_category_from_real_records(): void
    {
        [$owner, $workspace] = $this->workspace();
        $this->expense($workspace, 'Diesel', 50, 'fuel');
        $this->expense($workspace, 'Petrol', 30, 'fuel');
        $this->expense($workspace, 'Lunch with client', 20, 'meals');
        $this->expense($workspace, 'Old trip', 999, 'travel', ['occurs_on' => today()->subYears(2)]);

        $conversation = $this->ask($owner, 'How much did we spend this month by category?');

        $this->assertSame('idle', $conversation->fresh()->status);
        $tool = $conversation->messages()->where('role', 'tool')->firstOrFail();
        $this->assertSame('record_totals', $tool->tool_name);
        $answer = $this->reply($conversation);
        $this->assertStringContainsString('**Expenses › Expenses**', $answer);
        $this->assertStringContainsString('3 records, totalling **$100.00**', $answer);
        $this->assertStringContainsString('- Fuel: 2 · $80.00', $answer);
        $this->assertStringContainsString('- Meals: 1 · $20.00', $answer);
        $this->assertStringNotContainsString('999', $answer);

        $this->actingAs($owner)->get(route('assistant.show', $conversation))->assertOk()
            ->assertSee('Added up records')->assertSee('<strong>$100.00</strong>', false)->assertSee('Test mode');
    }

    public function test_it_says_who_owes_money_and_drafts_a_reminder(): void
    {
        [$owner, $workspace] = $this->workspace();
        $customer = Contact::factory()->for($workspace)->customer()->create(['name' => 'Tendai Moyo', 'kind' => 'person', 'company_name' => null]);
        $invoice = Invoice::factory()->for($workspace)->overdue()->withLines([['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 120, 'tax_rate' => 0]])
            ->create(['contact_id' => $customer->id])->fresh();

        $answer = $this->reply($this->ask($owner, 'Who owes us money?'));
        $this->assertStringContainsString('Customers owe **$120.00** in total, of which **$120.00** is overdue.', $answer);
        $this->assertStringContainsString($invoice->number, $answer);
        $this->assertStringContainsString('Tendai Moyo', $answer);
        $this->assertStringContainsString('10 days overdue', $answer);

        $draft = $this->reply($this->ask($owner, 'Draft a reminder for overdue invoices'));
        $this->assertStringContainsString('**Subject:** Payment reminder: invoice '.$invoice->number, $draft);
        $this->assertStringContainsString('Dear Tendai Moyo,', $draft);
    }

    public function test_summarise_button_reads_the_record_and_keeps_it_as_context(): void
    {
        [$owner, $workspace] = $this->workspace();
        $record = $this->expense($workspace, 'Generator repair', 75, 'repairs');

        $this->actingAs($owner)->get($record->url())->assertOk()->assertSee('Summarise with AI');

        $conversation = $this->ask($owner, 'Summarise '.$record->number.': what it is, where it stands and anything that needs attention.', ['record_id' => $record->id]);

        $this->assertSame($record->id, $conversation->contextRecord()?->id);
        $answer = $this->reply($conversation);
        $this->assertStringContainsString($record->number.' · Generator repair', $answer);
        $this->assertStringContainsString('currently **Approved**', $answer);
        $this->assertStringContainsString('$75.00', $answer);
        $this->assertStringContainsString('- Category: Repairs', $answer);
        $this->assertStringContainsString('started this chat from record '.$record->number, app(AssistantService::class)->systemPrompt($workspace, $conversation));

        $this->actingAs($owner)->get(route('assistant.show', $conversation))->assertOk()->assertSee('About')->assertSee('Opened a record');

        $draft = $this->reply($this->ask($owner, 'Write an email about '.$record->number));
        $this->assertStringContainsString('**Subject:** Generator repair ('.$record->number.')', $draft);
    }

    public function test_follow_up_questions_continue_the_same_chat(): void
    {
        [$owner, $workspace] = $this->workspace();
        $this->expense($workspace, 'Diesel', 50, 'fuel');
        $conversation = $this->ask($owner, 'What is in this workspace?');
        $this->assertStringContainsString('has these apps switched on', $this->reply($conversation));

        $this->actingAs($owner)->post(route('assistant.ask', $conversation), ['question' => 'Show recent expenses'])->assertRedirect(route('assistant.show', $conversation));

        $this->assertSame(2, $conversation->messages()->where('role', 'user')->count());
        $this->assertStringContainsString('Diesel', $this->reply($conversation));
        $this->assertSame(1, AssistantConversation::allWorkspaces()->count());

        $history = app(AssistantService::class)->history($conversation);
        $this->assertSame('What is in this workspace?', $history[0]['content']);
        $this->assertSame('Show recent expenses', collect($history)->where('role', 'user')->last()['content']);
        $this->assertSame('assistant', end($history)['role']);
    }

    public function test_lookups_report_bad_input_and_never_reach_other_workspaces_or_apps(): void
    {
        [$owner, $workspace] = $this->workspace();
        [, $other] = $this->workspace();
        $this->expense($other, 'Someone else', 500, 'fuel');
        $tools = new AssistantTools($workspace, app(BlueprintRegistry::class));

        $this->assertStringContainsString('No app called "Fleet"', $tools->call('record_totals', ['app' => 'Fleet'])['error']);
        $this->assertStringContainsString('has no field "colour"', $tools->call('record_totals', ['app' => 'expenses', 'group_by' => 'colour'])['error']);
        $this->assertStringContainsString('Unknown period', $tools->call('find_records', ['app' => 'expenses', 'period' => 'someday'])['error']);
        $this->assertStringContainsString('must be a date', $tools->call('find_records', ['app' => 'expenses', 'from' => 'March'])['error']);
        $this->assertSame('Unknown lookup "delete_records".', $tools->call('delete_records', [])['error']);

        $this->actingAs($owner)->get(route('assistant.index'))->assertOk();
        $answer = $this->reply($this->ask($owner, 'How much did we spend on expenses?'));
        $this->assertStringContainsString('0 records', $answer);
        $this->assertStringNotContainsString('500', $answer);
    }

    public function test_anthropic_makes_lookups_then_answers(): void
    {
        [$owner, $workspace] = $this->workspace('anthropic', ['api_key' => 'sk-ant-secret-1234']);
        $this->expense($workspace, 'Diesel', 50, 'fuel');
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['model' => 'claude-sonnet-5-5', 'content' => [
                ['type' => 'text', 'text' => 'Let me check.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'record_totals', 'input' => ['app' => 'expenses', 'group_by' => 'category']],
            ], 'usage' => ['input_tokens' => 900, 'output_tokens' => 40]])
            ->push(['model' => 'claude-sonnet-5-5', 'content' => [['type' => 'text', 'text' => 'You spent **$50.00**, all on fuel.']], 'usage' => ['input_tokens' => 1100, 'output_tokens' => 20]]),
        ]);

        $conversation = $this->ask($owner, 'What did we spend?');

        $this->assertSame('idle', $conversation->fresh()->status);
        $this->assertSame('You spent **$50.00**, all on fuel.', $this->reply($conversation));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->hasHeader('x-api-key', 'sk-ant-secret-1234')
            && $request['model'] === 'claude-sonnet-5-5'
            && collect($request['tools'])->pluck('name')->contains('invoice_summary')
            && str_contains($request['system'], $workspace->name));
        Http::assertSent(function (Request $request) {
            $last = collect($request['messages'])->last();

            return $last['role'] === 'user' && ($last['content'][0]['type'] ?? null) === 'tool_result'
                && $last['content'][0]['tool_use_id'] === 'toolu_1' && str_contains($last['content'][0]['content'], '"Fuel"');
        });
        $this->assertSame(['questions' => 1, 'input_tokens' => 2000, 'output_tokens' => 60], app(AssistantService::class)->usage($workspace));
    }

    public function test_openai_makes_lookups_then_answers(): void
    {
        [$owner] = $this->workspace('openai', ['api_key' => 'sk-openai-5678', 'model' => 'gpt-test']);
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['model' => 'gpt-test', 'choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'workspace_overview', 'arguments' => '{}']],
            ]]]], 'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 10]])
            ->push(['model' => 'gpt-test', 'choices' => [['message' => ['role' => 'assistant', 'content' => 'You have the Expenses app.']]], 'usage' => ['prompt_tokens' => 700, 'completion_tokens' => 12]]),
        ]);

        $conversation = $this->ask($owner, 'What apps do we have?');

        $this->assertSame('You have the Expenses app.', $this->reply($conversation));
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer sk-openai-5678') && $request['model'] === 'gpt-test'
            && $request['messages'][0]['role'] === 'system' && $request['tools'][0]['type'] === 'function');
        Http::assertSent(fn (Request $request) => collect($request['messages'])->contains(fn ($message) => $message['role'] === 'tool' && $message['tool_call_id'] === 'call_1'));
    }

    public function test_a_rejected_key_fails_the_chat_and_it_can_be_retried(): void
    {
        [$owner] = $this->workspace('anthropic', ['api_key' => 'sk-ant-wrong']);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401)
            ->push(['content' => [['type' => 'text', 'text' => 'Hello again.']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]),
        ]);

        $conversation = $this->ask($owner, 'Hello');
        $this->assertSame('failed', $conversation->fresh()->status);
        $this->assertSame('Anthropic: invalid x-api-key', $conversation->fresh()->error);
        $this->actingAs($owner)->get(route('assistant.show', $conversation))->assertSee('The assistant could not answer')->assertSee('invalid x-api-key');
        $this->actingAs($owner)->getJson(route('assistant.status', $conversation))->assertJson(['status' => 'failed']);

        $this->actingAs($owner)->post(route('assistant.retry', $conversation))->assertRedirect(route('assistant.show', $conversation));

        $this->assertSame('idle', $conversation->fresh()->status);
        $this->assertSame('Hello again.', $this->reply($conversation));
        $this->actingAs($owner)->post(route('assistant.retry', $conversation))->assertForbidden();
    }

    public function test_questions_stop_at_the_monthly_limit_and_when_switched_off(): void
    {
        [$owner, $workspace] = $this->workspace();
        app(AssistantService::class)->configure($workspace, 'test', [], 1);

        $this->ask($owner, 'What is in this workspace?');
        $this->actingAs($owner)->post(route('assistant.store'), ['question' => 'And again?'])->assertSessionHas('flash.type', 'warning');
        $this->assertSame(1, AssistantConversation::allWorkspaces()->count());

        app(AssistantService::class)->configure($workspace->fresh(), null, [], 100);
        $this->actingAs($owner)->post(route('assistant.store'), ['question' => 'Anyone there?'])->assertSessionHas('flash.message', fn ($message) => str_contains($message, 'not set up'));
        $this->actingAs($owner)->get(route('assistant.index'))->assertOk()->assertSee('The assistant is not set up yet')->assertSee('Set up the assistant');
    }

    public function test_chats_are_private_to_the_person_who_started_them(): void
    {
        [$owner, $workspace] = $this->workspace();
        $viewer = $this->memberOf($workspace, 'viewer');
        $conversation = $this->ask($owner, 'Which lists do we keep?');
        [$stranger] = $this->workspace();

        $this->actingAs($viewer)->get(route('assistant.index'))->assertOk()->assertDontSee($conversation->title)->assertDontSee('Assistant settings');
        $this->actingAs($viewer)->get(route('assistant.show', $conversation))->assertForbidden();
        $this->actingAs($viewer)->post(route('assistant.ask', $conversation), ['question' => 'Hi'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('assistant.destroy', $conversation))->assertForbidden();
        $this->actingAs($stranger)->get(route('assistant.show', $conversation))->assertNotFound();

        $mine = $this->ask($viewer, 'Show recent expenses');
        $this->assertSame($viewer->id, $mine->user_id);
        $this->actingAs($viewer)->get(route('settings.assistant.edit'))->assertForbidden();

        $this->actingAs($owner)->delete(route('assistant.destroy', $conversation))->assertRedirect(route('assistant.index'));
        $this->assertModelMissing($conversation);
        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_settings_store_the_key_encrypted_and_show_it_masked(): void
    {
        [$owner, $workspace] = $this->workspace(null);

        $this->actingAs($owner)->put(route('settings.assistant.update'), ['provider' => 'anthropic', 'monthly_limit' => 50])
            ->assertSessionHas('flash.type', 'warning');

        $this->actingAs($owner)->put(route('settings.assistant.update'), [
            'provider' => 'anthropic', 'monthly_limit' => 50, 'anthropic' => ['api_key' => 'sk-ant-abcdef9876', 'model' => 'claude-haiku-5-5'],
        ])->assertSessionHas('flash.type', 'success');

        $workspace->refresh();
        $stored = $workspace->setting('assistant.anthropic.api_key');
        $this->assertNotSame('sk-ant-abcdef9876', $stored);
        $this->assertSame('sk-ant-abcdef9876', Crypt::decryptString($stored));
        $this->assertSame(50, app(AssistantService::class)->monthlyLimit($workspace));
        $this->assertSame('anthropic', app(AssistantService::class)->provider($workspace)?->key());

        $this->actingAs($owner)->put(route('settings.assistant.update'), ['provider' => 'anthropic', 'monthly_limit' => 50, 'anthropic' => ['api_key' => '', 'model' => '']]);
        $this->assertSame('sk-ant-abcdef9876', Crypt::decryptString($workspace->fresh()->setting('assistant.anthropic.api_key')));

        $this->actingAs($owner)->get(route('settings.assistant.edit'))->assertOk()
            ->assertSee('••••9876')->assertDontSee('sk-ant-abcdef9876')->assertSee('Active');
        $this->actingAs($owner)->put(route('settings.assistant.update'), ['provider' => 'nope', 'monthly_limit' => 0])->assertSessionHasErrors(['provider', 'monthly_limit']);
    }
}
