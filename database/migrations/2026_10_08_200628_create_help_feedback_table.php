<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('article', 120);
            $table->boolean('helpful');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'article']);
            $table->index('article');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_feedback');
    }
};
