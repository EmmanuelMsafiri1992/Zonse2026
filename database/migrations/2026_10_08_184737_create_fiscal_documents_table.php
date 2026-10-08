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
        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('authority', 20);
            $table->unsignedInteger('counter');
            $table->string('fiscal_number', 64);
            $table->string('verification_code', 32)->unique();
            $table->string('status', 20)->default('pending');
            $table->json('payload');
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->string('authority_reference', 100)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'counter']);
            $table->index(['workspace_id', 'status']);
            $table->index(['invoice_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');
    }
};
