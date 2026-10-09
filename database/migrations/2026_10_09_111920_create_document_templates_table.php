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
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('kind', 20);
            $table->string('subject', 100);
            $table->string('heading', 160)->nullable();
            $table->text('body');
            $table->string('paper', 10)->default('a4');
            $table->string('orientation', 10)->default('portrait');
            $table->string('font', 10)->default('sans');
            $table->string('align', 10)->default('left');
            $table->string('border', 10)->default('none');
            $table->string('color', 7)->default('#0073ea');
            $table->boolean('show_logo')->default(true);
            $table->json('signatures');
            $table->string('footer', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('generated_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['workspace_id', 'subject']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
