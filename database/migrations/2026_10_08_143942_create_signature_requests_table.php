<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('signature_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('title', 190);
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('signing_order', 20)->default('parallel');
            $table->nullableMorphs('signable');
            $table->string('document_name', 190);
            $table->string('document_path');
            $table->char('document_hash', 64);
            $table->unsignedInteger('document_size')->default(0);
            $table->string('certificate_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('signature_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signature_request_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('token', 64)->unique();
            $table->string('status', 20)->default('pending');
            $table->string('signature_type', 10)->nullable();
            $table->mediumText('signature_data')->nullable();
            $table->string('signed_name', 120)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('signature_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signature_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signature_signer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 30);
            $table->string('description', 255);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signature_events');
        Schema::dropIfExists('signature_signers');
        Schema::dropIfExists('signature_requests');
    }
};
