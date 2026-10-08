<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30);
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requester_name', 160)->nullable();
            $table->string('requester_email', 190)->nullable();
            $table->string('subject', 200);
            $table->text('body')->nullable();
            $table->string('channel', 20)->default('phone');
            $table->string('category', 60)->nullable();
            $table->string('status', 20)->default('open');
            $table->string('priority', 10)->default('normal');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_replied_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['workspace_id', 'number']);
            $table->index(['workspace_id', 'status', 'priority']);
            $table->index(['workspace_id', 'assignee_id', 'status']);
            $table->index(['workspace_id', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
