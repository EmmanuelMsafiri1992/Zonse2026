<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posts Zonseob publishes about itself on its own social channels (the Facebook Page series that
 * walks through the platform suite by suite and app by app). One row per attempt, so the planner
 * knows what has already gone out and the admin page can show what was posted and what failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20)->default('facebook');
            $table->string('kind', 20); // intro|suite|app
            $table->string('key', 80);
            $table->string('title', 160);
            $table->text('message');
            $table->string('image_path')->nullable();
            $table->string('external_id', 80)->nullable();
            $table->string('status', 20); // posted|failed
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['channel', 'kind', 'key']);
            $table->index(['channel', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
