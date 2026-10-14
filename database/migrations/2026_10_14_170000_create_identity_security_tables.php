<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at', 6)->nullable();
            $table->unsignedBigInteger('session_version')->default(0);
            $table->text('phone')->nullable();
            $table->timestamp('phone_verified_at', 6)->nullable();
            $table->enum('otp_channel', ['email', 'sms'])->default('email');
        });
        Schema::create('identity_controls', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('identity_controls')->insert(['id' => 1]);
        Schema::create('identity_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lodge_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('email');
            $table->json('roles');
            $table->boolean('platform_admin')->default(false);
            $table->boolean('bootstrap')->default(false);
            $table->char('token_digest', 64)->unique();
            $table->timestamp('expires_at', 6);
            $table->timestamp('accepted_at', 6)->nullable();
            $table->timestamp('revoked_at', 6)->nullable();
            $table->enum('delivery_state', ['created', 'accepted', 'failed'])->default('created');
            $table->timestamps();
            $table->index(['lodge_id', 'email'], 'invitation_tenant_email');
        });
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('purpose', 24);
            $table->enum('channel', ['email', 'sms']);
            $table->char('session_binding', 64);
            $table->char('code_digest', 64);
            $table->unsignedBigInteger('credential_version')->default(0);
            $table->text('payload')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->enum('delivery_state', ['created', 'accepted', 'failed'])->default('created');
            $table->timestamp('expires_at', 6);
            $table->timestamp('last_sent_at', 6);
            $table->timestamp('consumed_at', 6)->nullable();
            $table->timestamp('invalidated_at', 6)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'purpose', 'expires_at'], 'otp_user_purpose_expiry');
        });
        Schema::create('identity_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->char('digest', 64)->unique();
            $table->timestamp('consumed_at', 6)->nullable();
            $table->timestamp('created_at', 6);
        });
        Schema::create('assisted_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lodge_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->json('permissions');
            $table->unsignedSmallInteger('duration_minutes');
            $table->timestamp('approved_at', 6)->nullable();
            $table->timestamp('expires_at', 6)->nullable();
            $table->timestamp('revoked_at', 6)->nullable();
            $table->timestamps();
            $table->index(['lodge_id', 'requester_id', 'expires_at'], 'assistance_tenant_user_expiry');
        });
        Schema::create('identity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('lodge_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event', 60);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at', 6);
            $table->index(['lodge_id', 'occurred_at'], 'identity_tenant_audit_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_events');
        Schema::dropIfExists('assisted_access_grants');
        Schema::dropIfExists('identity_recovery_codes');
        Schema::dropIfExists('otp_challenges');
        Schema::dropIfExists('identity_invitations');
        Schema::dropIfExists('identity_controls');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['is_active', 'activated_at', 'session_version', 'phone', 'phone_verified_at', 'otp_channel']));
    }
};
