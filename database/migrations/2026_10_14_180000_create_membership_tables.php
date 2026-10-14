<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_people', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lodge_id')->constrained('lodges')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reference', 40);
            $table->string('first_name', 120);
            $table->string('last_name', 120);
            $table->date('birth_date')->nullable();
            $table->string('email')->nullable();
            $table->text('phone')->nullable();
            $table->text('address')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['lodge_id', 'id'], 'people_tenant_id_unique');
            $table->unique(['lodge_id', 'reference'], 'people_reference_unique');
            $table->unique(['lodge_id', 'user_id'], 'people_account_unique');
            $table->index(['lodge_id', 'last_name', 'first_name'], 'people_search_idx');
        });
        Schema::create('member_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lodge_id')->constrained('lodges')->restrictOnDelete();
            $table->unsignedBigInteger('person_id');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->enum('status', ['active', 'suspended', 'ended']);
            $table->boolean('administrative_exemption')->default(false);
            $table->text('exemption_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('open_person_id')->nullable()->storedAs('CASE WHEN ends_on IS NULL THEN person_id ELSE NULL END');
            $table->unique(['lodge_id', 'open_person_id'], 'enrollment_open_unique');
            $table->timestamps();
            $table->foreign(['lodge_id', 'person_id'], 'enrollment_person_fk')->references(['lodge_id', 'id'])->on('member_people')->restrictOnDelete();
            $table->unique(['lodge_id', 'id'], 'enrollment_tenant_unique');
            $table->unique(['lodge_id', 'person_id', 'starts_on'], 'enrollment_start_unique');
            $table->index(['lodge_id', 'status', 'starts_on'], 'enrollment_status_idx');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE member_enrollments ADD CONSTRAINT enrollment_date_check CHECK (ends_on IS NULL OR ends_on >= starts_on), ADD CONSTRAINT enrollment_ended_check CHECK (status <> 'ended' OR ends_on IS NOT NULL)");
        }
        Schema::create('member_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lodge_id')->constrained('lodges')->restrictOnDelete();
            $table->unsignedBigInteger('person_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event', 40);
            $table->text('snapshot');
            $table->timestamp('occurred_at', 6);
            $table->foreign(['lodge_id', 'person_id'], 'history_person_fk')->references(['lodge_id', 'id'])->on('member_people')->restrictOnDelete();
            $table->index(['lodge_id', 'person_id', 'id'], 'history_person_idx');
        });
        Schema::create('member_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lodge_id')->constrained('lodges')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('token_digest', 64)->unique();
            $table->string('session_binding', 64);
            $table->longText('payload');
            $table->timestamp('expires_at', 6);
            $table->timestamp('consumed_at', 6)->nullable();
            $table->timestamps();
            $table->index(['lodge_id', 'actor_id', 'expires_at'], 'import_expiry_idx');
        });
        Schema::table('identity_invitations', function (Blueprint $table): void {
            $table->unsignedBigInteger('person_id')->nullable();
            $table->foreign(['lodge_id', 'person_id'], 'invitation_person_fk')->references(['lodge_id', 'id'])->on('member_people')->restrictOnDelete();
        });
        Schema::table('lodges', function (Blueprint $table): void {
            $table->string('administrative_email')->nullable();
            $table->unsignedTinyInteger('reminder_days')->default(7);
            $table->boolean('brothers_treasury_visible')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('identity_invitations', function (Blueprint $table): void {
            $table->dropForeign('invitation_person_fk');
            $table->dropColumn('person_id');
        });
        Schema::table('lodges', fn (Blueprint $table) => $table->dropColumn(['administrative_email', 'reminder_days', 'brothers_treasury_visible']));
        Schema::dropIfExists('member_imports');
        Schema::dropIfExists('member_history');
        Schema::dropIfExists('member_enrollments');
        Schema::dropIfExists('member_people');
    }
};
