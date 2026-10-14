<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false);
        });
        Schema::create('lodge_user', function (Blueprint $table) {
            $table->foreignId('lodge_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->primary(['lodge_id', 'user_id']);
            $table->index(['user_id', 'is_active']);
        });
        Schema::create('lodge_user_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('lodge_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('role', ['administrator', 'treasurer', 'brother']);
            $table->primary(['lodge_id', 'user_id', 'role']);
            $table->index('user_id');
            $table->foreign(['lodge_id', 'user_id'])->references(['lodge_id', 'user_id'])->on('lodge_user')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lodge_user_roles');
        Schema::dropIfExists('lodge_user');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_platform_admin'));
    }
};
