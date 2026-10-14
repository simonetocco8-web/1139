<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('obediences', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('rites', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('obedience_rite', function (Blueprint $table) {
            $table->foreignId('obedience_id')->constrained()->restrictOnDelete();
            $table->foreignId('rite_id')->constrained()->restrictOnDelete();
            $table->primary(['obedience_id', 'rite_id']);
            $table->index('rite_id');
        });
        Schema::create('structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obedience_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->enum('level', ['national', 'regional', 'provincial']);
            $table->string('name', 160);
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('iban', 34)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['obedience_id', 'id'], 'structures_owner_id');
            $table->unique(['obedience_id', 'id', 'level'], 'structures_owner_id_level');
            $table->index(['obedience_id', 'parent_id']);
            $table->index(['obedience_id', 'level', 'is_active']);
            $table->foreign(['obedience_id', 'parent_id'], 'structures_parent_owner')
                ->references(['obedience_id', 'id'])->on('structures')->restrictOnDelete();
        });
        Schema::create('lodges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obedience_id')->constrained()->restrictOnDelete();
            $table->foreignId('rite_id')->constrained()->restrictOnDelete();
            $table->string('number', 40);
            $table->string('name', 160);
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('iban', 34)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['obedience_id', 'number']);
            $table->unique(['obedience_id', 'id'], 'lodges_owner_id');
            $table->index(['obedience_id', 'is_active']);
            $table->index(['obedience_id', 'rite_id']);
            $table->foreign(['obedience_id', 'rite_id'], 'lodges_compatible_rite')
                ->references(['obedience_id', 'rite_id'])->on('obedience_rite')->restrictOnDelete();
        });
        Schema::create('lodge_territorial_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lodge_id');
            $table->unsignedBigInteger('obedience_id');
            $table->unsignedBigInteger('structure_id');
            $table->enum('level', ['national', 'regional', 'provincial']);
            $table->string('structure_name', 160);
            $table->timestamp('starts_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('active_level', 20)->storedAs('CASE WHEN ended_at IS NULL THEN level ELSE NULL END');
            $table->unique(['lodge_id', 'active_level'], 'assignment_one_active_level');
            $table->index(['lodge_id', 'starts_at']);
            $table->index(['obedience_id', 'structure_id', 'level'], 'assignment_structure_lookup');
            $table->index(['obedience_id', 'lodge_id'], 'assignment_lodge_lookup');
            $table->foreign(['obedience_id', 'lodge_id'], 'assignment_lodge_owner')
                ->references(['obedience_id', 'id'])->on('lodges')->restrictOnDelete();
            $table->foreign(['obedience_id', 'structure_id', 'level'], 'assignment_structure_owner')
                ->references(['obedience_id', 'id', 'level'])->on('structures')->restrictOnDelete();
        });
        Schema::create('organization_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('event', 40);
            $table->timestamp('occurred_at');
            $table->index(['entity_type', 'entity_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_events');
        Schema::dropIfExists('lodge_territorial_assignments');
        Schema::dropIfExists('lodges');
        Schema::dropIfExists('structures');
        Schema::dropIfExists('obedience_rite');
        Schema::dropIfExists('rites');
        Schema::dropIfExists('obediences');
    }
};
