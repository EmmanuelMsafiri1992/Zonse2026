<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The unified inbox: the channels customers write in on (an email address, an SMS number,
     * a WhatsApp number, a Facebook page, an Instagram account), one conversation per person
     * per channel, and every message in and out.
     */
    public function up(): void
    {
        Schema::create('inbox_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('name', 120);
            $table->string('address', 190)->nullable();
            $table->text('credentials')->nullable();
            $table->string('token', 64)->unique();
            $table->boolean('test_mode')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['workspace_id', 'type']);
        });

        Schema::create('inbox_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('handle', 190);
            $table->string('name', 190)->nullable();
            $table->string('subject', 190)->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('unread_count')->default(0);
            $table->string('last_message_preview', 160)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['inbox_channel_id', 'handle']);
            $table->index(['workspace_id', 'status', 'last_message_at']);
        });

        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3);
            $table->text('body');
            $table->string('status', 20);
            $table->string('external_id', 190)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['inbox_conversation_id', 'id']);
            $table->index(['inbox_conversation_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
        Schema::dropIfExists('inbox_conversations');
        Schema::dropIfExists('inbox_channels');
    }
};
