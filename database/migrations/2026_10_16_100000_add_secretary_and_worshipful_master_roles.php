<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lodge_user_roles', function (Blueprint $table): void {
            $table->enum('role', ['administrator', 'treasurer', 'brother', 'secretary', 'worshipful_master'])->change();
        });
    }

    public function down(): void
    {
        if (DB::table('lodge_user_roles')->whereIn('role', ['secretary', 'worshipful_master'])->exists()) {
            throw new RuntimeException('Rollback bloccato: esistono assegnazioni dei nuovi ruoli da gestire esplicitamente.');
        }
        Schema::table('lodge_user_roles', function (Blueprint $table): void {
            $table->enum('role', ['administrator', 'treasurer', 'brother'])->change();
        });
    }
};
