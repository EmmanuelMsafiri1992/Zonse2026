<?php

namespace Tests\Feature;

use App\Assistant\LandingChat;
use App\Assistant\Providers\AnthropicProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class LandingChatTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
        RateLimiter::clear('landing-chat:127.0.0.1');
        config(['zonseo.assistant.api_key' => null]);
    }

    public function test_the_chat_is_on_the_public_pages_only(): void
    {
        $this->get('/')->assertOk()->assertSee('Ask a question')->assertSee(LandingChat::SUGGESTIONS[0]);
        $this->get('/pricing')->assertOk()->assertSee('Ask a question');

        [$owner] = $this->ownerWithWorkspace();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertDontSee('Ask a question');
    }

    public function test_without_a_key_prices_are_answered_from_the_plans(): void
    {
        $response = $this->postJson(route('chat.store'), ['message' => 'What does Zonseob cost?'])->assertOk();

        $response->assertJsonPath('source', 'help');
        $this->assertStringContainsString('$29', $response->json('text'));
        $this->assertStringContainsString('Business', $response->json('text'));
        $this->assertStringContainsString('<a href="'.route('pricing').'"', $response->json('html'));
        Http::assertNothingSent();
    }

    public function test_without_a_key_apps_and_help_articles_answer_other_questions(): void
    {
        $apps = $this->postJson(route('chat.store'), ['message' => 'Which apps are there for a clinic?'])->assertOk();
        $this->assertStringContainsString('Apps that match', $apps->json('text'));
        $this->assertMatchesRegularExpression('/Clinic|Patients/', $apps->json('text'));

        $paying = $this->postJson(route('chat.store'), ['message' => 'Can I pay with EcoCash?'])->assertOk();
        $this->assertStringContainsString('Plans & billing', $paying->json('text'));

        $help = $this->postJson(route('chat.store'), ['message' => 'How do I invite my team members?'])->assertOk();
        $this->assertStringContainsString('created a free account', $help->json('text'));

        $unknown = $this->postJson(route('chat.store'), ['message' => 'zzzz qqqq'])->assertOk();
        $this->assertStringContainsString('could not find that', $unknown->json('text'));
    }

    public function test_with_a_key_claude_answers_from_the_live_facts_and_remembers_the_conversation(): void
    {
        config(['zonseo.assistant.api_key' => 'sk-ant-test']);
        Http::fake([AnthropicProvider::ENDPOINT => Http::sequence()
            ->push(['model' => 'claude-haiku-5-5', 'content' => [['type' => 'text', 'text' => 'Business is $29 a month.']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]])
            ->push(['model' => 'claude-haiku-5-5', 'content' => [['type' => 'text', 'text' => 'Yes, yearly is $290.']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]),
        ]);

        $first = $this->postJson(route('chat.store'), ['message' => 'How much is the Business plan?'])->assertOk();
        $first->assertJsonPath('source', 'claude')->assertJsonPath('text', 'Business is $29 a month.');

        Http::assertSent(fn (Request $request) => $request->hasHeader('x-api-key', 'sk-ant-test')
            && $request['model'] === 'claude-haiku-5-5'
            && str_contains($request['system'], '$29 per month')
            && str_contains($request['system'], route('register'))
            && str_contains($request['system'], 'Getting started')
            && count($request['messages']) === 1
            && $request['messages'][0]['content'][0]['text'] === 'How much is the Business plan?');

        $this->postJson(route('chat.store'), ['message' => 'And per year?'])->assertOk()->assertJsonPath('text', 'Yes, yearly is $290.');

        Http::assertSent(fn (Request $request) => count($request['messages']) === 3
            && $request['messages'][1]['role'] === 'assistant'
            && $request['messages'][1]['content'][0]['text'] === 'Business is $29 a month.'
            && $request['messages'][2]['content'][0]['text'] === 'And per year?');
        $this->assertCount(4, session(LandingChat::SESSION_KEY));
    }

    public function test_the_help_centre_answers_when_claude_cannot_be_reached(): void
    {
        config(['zonseo.assistant.api_key' => 'sk-ant-test']);
        Http::fake([AnthropicProvider::ENDPOINT => Http::response(['error' => ['message' => 'overloaded']], 500)]);

        $response = $this->postJson(route('chat.store'), ['message' => 'What does Zonseob cost?'])->assertOk();

        $response->assertJsonPath('source', 'help');
        $this->assertStringContainsString('$29', $response->json('text'));
    }

    public function test_bad_input_is_rejected_and_bots_are_ignored(): void
    {
        $this->postJson(route('chat.store'), ['message' => ''])->assertUnprocessable();
        $this->postJson(route('chat.store'), ['message' => 'a'])->assertUnprocessable();
        $this->postJson(route('chat.store'), ['message' => str_repeat('x', 1001)])->assertUnprocessable();
        $this->postJson(route('chat.store'), ['message' => 'What does it cost?', 'website' => 'http://spam'])->assertUnprocessable();
    }

    public function test_questions_are_throttled_per_visitor(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->postJson(route('chat.store'), ['message' => 'What does it cost?'])->assertOk();
        }

        $this->postJson(route('chat.store'), ['message' => 'What does it cost?'])->assertStatus(429);
    }

    public function test_starting_again_forgets_the_conversation(): void
    {
        $this->postJson(route('chat.store'), ['message' => 'What does it cost?'])->assertOk();
        $this->assertNotEmpty(session(LandingChat::SESSION_KEY));

        $this->deleteJson(route('chat.destroy'))->assertOk()->assertJson(['ok' => true]);

        $this->assertNull(session(LandingChat::SESSION_KEY));
    }
}
