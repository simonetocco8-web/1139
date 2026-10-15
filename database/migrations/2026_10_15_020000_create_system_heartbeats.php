<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_heartbeats', function (Blueprint $t): void {
            $t->string('name', 40)->primary();
            $t->timestamp('last_seen_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_heartbeats');
    }
};
