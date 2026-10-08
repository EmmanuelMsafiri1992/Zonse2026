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
        Schema::create('document_captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('type', 20);
            $table->string('status', 20)->default('processing');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime', 100);
            $table->unsignedInteger('file_size')->default(0);
            $table->char('file_hash', 64);
            $table->string('provider', 30)->nullable();
            $table->longText('raw_text')->nullable();
            $table->json('fields')->nullable();
            $table->string('error', 500)->nullable();
            $table->nullableMorphs('result');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'file_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_captures');
    }
};
