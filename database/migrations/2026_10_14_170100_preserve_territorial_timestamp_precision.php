<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lodge_territorial_assignments', function (Blueprint $table): void {
            $table->timestamp('starts_at', 6)->change();
            $table->timestamp('ended_at', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('lodge_territorial_assignments', function (Blueprint $table): void {
            $table->timestamp('starts_at')->change();
            $table->timestamp('ended_at')->nullable()->change();
        });
    }
};
