<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every text message a workspace sends, with what happened to it. Doubles as the guard
     * that stops a reminder for the same invoice or appointment going out twice.
     */
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->string('to', 20);
            $table->text('body');
            $table->unsignedTinyInteger('segments')->default(1);
            $table->string('purpose', 30)->default('manual');
            $table->string('status', 20)->default('queued');
            $table->string('provider', 30);
            $table->string('provider_message_id', 120)->nullable();
            $table->string('error', 500)->nullable();
            $table->uuid('batch')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'purpose', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
