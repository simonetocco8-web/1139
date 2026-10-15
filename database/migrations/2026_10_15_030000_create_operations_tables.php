<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_preferences', function (Blueprint $t): void {
            $t->id();
            $t->string('scope', 40);
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('type', 30);
            $t->boolean('enabled');
            $t->string('channel', 8)->default('email');
            $t->unique(['scope', 'user_id', 'type'], 'communication_preference_unique');
        });
        Schema::create('communication_rules', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lodge_id')->constrained()->restrictOnDelete();
            $t->string('type', 30);
            $t->boolean('enabled');
            $t->unique(['lodge_id', 'type']);
        });
        Schema::create('communications', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lodge_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->char('request_key', 64);
            $t->text('body');
            $t->timestamp('created_at', 6);
            $t->unique(['lodge_id', 'request_key']);
            $t->unique(['lodge_id', 'id']);
        });
        Schema::create('communication_outbox', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lodge_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('type', 30);
            $t->unsignedBigInteger('source_id')->nullable();
            $t->char('dedupe_key', 64)->unique();
            $t->string('topic', 40)->nullable();
            $t->string('state', 16)->default('pending');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->boolean('retry_authorized')->default(false);
            $t->string('channel', 8)->nullable();
            $t->string('result_code', 40)->nullable();
            $t->timestamp('available_at', 6);
            $t->timestamp('expires_at', 6);
            $t->timestamp('queued_at', 6)->nullable();
            $t->timestamp('claimed_at', 6)->nullable();
            $t->timestamp('accepted_at', 6)->nullable();
            $t->timestamp('created_at', 6);
            $t->index(['state', 'available_at']);
            $t->index(['lodge_id', 'user_id', 'created_at'], 'communication_history_idx');
        });
        Schema::create('communication_attempts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('outbox_id')->constrained('communication_outbox')->restrictOnDelete();
            $t->unsignedTinyInteger('number');
            $t->string('outcome', 16);
            $t->string('code', 40);
            $t->timestamp('occurred_at', 6);
            $t->unique(['outbox_id', 'number']);
        });
        Schema::create('privacy_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lodge_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('person_id')->nullable();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->string('kind', 24);
            $t->text('details');
            $t->string('state', 20)->default('open');
            $t->text('decision')->nullable();
            $t->string('policy_reference', 160)->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at', 6);
            $t->timestamp('resolved_at', 6)->nullable();
            $t->foreign(['lodge_id', 'person_id'])->references(['lodge_id', 'id'])->on('member_people')->restrictOnDelete();
            $t->index(['lodge_id', 'requested_by', 'state']);
        });
        Schema::create('backup_runs', function (Blueprint $t): void {
            $t->id();
            $t->string('state', 16);
            $t->string('archive', 120)->nullable();
            $t->char('sha256', 64)->nullable();
            $t->char('recipient_fingerprint', 64);
            $t->timestamp('started_at', 6);
            $t->timestamp('completed_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        foreach (['backup_runs', 'privacy_requests', 'communication_attempts', 'communication_outbox', 'communications', 'communication_rules', 'communication_preferences'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
