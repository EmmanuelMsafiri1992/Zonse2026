<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zonseob AI: Claude access a person pays for with tokens. Each person has one wallet (tokens
 * belong to the person, not a workspace, so they keep them whichever workspace they open), and
 * their chats with Claude are private to them. Every answer records what it cost in tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('balance_tokens')->default(0);
            $table->unsignedBigInteger('welcome_tokens')->default(0);
            $table->unsignedBigInteger('purchased_tokens')->default(0);
            $table->unsignedBigInteger('used_tokens')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('title', 120);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('ai_chats')->cascadeOnDelete();
            $table->string('role', 20); // user|assistant
            $table->longText('content');
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cost_tokens')->default(0); // what was taken from the wallet
            $table->timestamps();

            $table->index(['chat_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
        Schema::dropIfExists('ai_chats');
        Schema::dropIfExists('ai_wallets');
    }
};
