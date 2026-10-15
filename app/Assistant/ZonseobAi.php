<?php

namespace App\Assistant;

use App\Assistant\Providers\AnthropicProvider;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\AiWallet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Zonseob AI: Claude for anything, paid for with tokens from the person's wallet. It uses
 * Zonseob's own Anthropic key, so people who cannot pay Anthropic directly buy token packs from
 * Zonseob in their own currency instead. Each answer is charged at its input tokens plus a
 * weighted count of its output tokens and recorded on the message.
 */
class ZonseobAi
{
    public const MAX_QUESTION = 8000;

    /** How much of a chat is sent back to Claude with each question. */
    public const HISTORY_MESSAGES = 30;

    public const HISTORY_CHARS = 40000;

    /** Suggested first questions. */
    public const STARTERS = [
        'Write a polite reminder to a customer whose invoice is overdue',
        'Explain how to price a product so it still makes a profit',
        'Draft a job advert for a shop assistant',
        'Translate "Thank you for your order" into Shona, Ndebele and Chichewa',
        'Make a simple weekly plan for a small bakery',
        'What should be in a contract for a freelance designer?',
    ];

    public function __construct(protected AnthropicProvider $claude) {}

    /** Zonseob AI runs on the platform's own key; without one there is nothing to sell. */
    public function isAvailable(): bool
    {
        return trim((string) config('zonseo.assistant.api_key', '')) !== '';
    }

    public function model(): string
    {
        $model = trim((string) config('zonseo.assistant.model', ''));

        return $model !== '' ? $model : AnthropicProvider::DEFAULT_MODEL;
    }

    /** Tokens taken from the wallet for an answer. */
    public function cost(int $inputTokens, int $outputTokens): int
    {
        return $inputTokens + max(1, (int) config('zonseo.ai.output_weight', 4)) * $outputTokens;
    }

    /** Open a new chat with a first question. */
    public function start(User $user, string $question): AiChat
    {
        $chat = AiChat::create(['user_id' => $user->id, 'title' => AiChat::titleFor($question)]);

        try {
            $this->ask($chat, $question);
        } catch (AssistantException $e) {
            $chat->delete();

            throw $e;
        }

        return $chat;
    }

    /**
     * Ask Claude and charge the wallet. Nothing is kept or charged when Claude cannot answer.
     *
     * @throws AssistantException
     */
    public function ask(AiChat $chat, string $question): AiChatMessage
    {
        $question = trim($question);
        $history = $this->history($chat);
        $history[] = ['role' => 'user', 'content' => $question];

        $reply = $this->claude->reply($this->systemPrompt($chat->user), $history, [], [
            'api_key' => (string) config('zonseo.assistant.api_key'),
            'model' => $this->model(),
        ]);

        $cost = $this->cost($reply->inputTokens, $reply->outputTokens);

        return DB::transaction(function () use ($chat, $question, $reply, $cost) {
            $chat->messages()->create(['role' => 'user', 'content' => $question]);
            $answer = $chat->messages()->create([
                'role' => 'assistant',
                'content' => $reply->text ?? '',
                'model' => $reply->model,
                'input_tokens' => $reply->inputTokens,
                'output_tokens' => $reply->outputTokens,
                'cost_tokens' => $cost,
            ]);
            $chat->forceFill(['last_message_at' => now()])->save();
            AiWallet::for($chat->user)->spend($cost);

            return $answer;
        });
    }

    /**
     * The most recent turns of the chat, newest kept, trimmed so a long chat does not cost more
     * than it should.
     *
     * @return list<array{role: string, content: string}>
     */
    protected function history(AiChat $chat): array
    {
        $messages = $chat->messages()->reorder('id', 'desc')->limit(self::HISTORY_MESSAGES)->get();
        $turns = [];
        $chars = 0;
        foreach ($messages as $message) {
            $chars += mb_strlen($message->content);
            if ($chars > self::HISTORY_CHARS && $turns) {
                break;
            }
            array_unshift($turns, ['role' => $message->role, 'content' => $message->content]);
        }

        return $turns;
    }

    protected function systemPrompt(User $user): string
    {
        return implode("\n", [
            'You are Zonseob AI, a general-purpose assistant powered by Claude and offered by Zonseob (zonseob.com), a platform of business apps for every profession.',
            'Help with anything the person asks: writing and editing, explaining, planning, calculations, translation, code, advice for running a business and everyday questions.',
            'Answer in the language the person writes in. Be clear and direct, use Markdown for structure, and keep answers as short as the question allows.',
            'You cannot see or change anything in the person\'s Zonseob workspace. For questions about their own records, invoices or customers, tell them to use the Assistant inside their workspace.',
            'Each answer is paid for with tokens from the person\'s balance, so do not pad answers.',
            'The person\'s name is '.$user->name.'. Today is '.now()->toFormattedDateString().'.',
        ]);
    }
}
