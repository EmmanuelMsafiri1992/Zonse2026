<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Blueprint apps number their records per entity ("rec.<app>.<entity>"), which needs longer sequence keys. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sequences', function (Blueprint $table) {
            $table->string('key', 120)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sequences', function (Blueprint $table) {
            $table->string('key', 40)->change();
        });
    }
};
