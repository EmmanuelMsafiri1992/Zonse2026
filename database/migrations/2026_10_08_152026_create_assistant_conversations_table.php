<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('title', 120);
            $table->string('status', 20)->default('idle');
            $table->string('error', 500)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'user_id', 'last_message_at'], 'assistant_conversations_owner_recent_index');
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('role', 20);
            $table->longText('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id', 100)->nullable();
            $table->string('tool_name', 60)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'role', 'created_at'], 'assistant_messages_usage_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
