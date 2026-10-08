<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One table backs every blueprint-driven app (farm, clinic, gym, …).
 * Common columns are real columns so they can be filtered and indexed;
 * everything the blueprint defines beyond that lives in the `data` JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('blueprint', 64);
            $table->string('entity', 64);
            $table->string('number', 32);
            $table->string('title');
            $table->string('status', 40)->default('active');
            $table->json('data')->nullable();
            $table->text('search_text')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('occurs_on')->nullable();
            $table->date('due_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'blueprint', 'entity']);
            $table->index(['workspace_id', 'number']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'occurs_on']);
            $table->index(['workspace_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('records');
    }
};
