<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lodge_territorial_assignments', fn (Blueprint $t) => $t->unique(['lodge_id', 'obedience_id', 'structure_id', 'id'], 'assignment_levy_owner'));
        Schema::create('levy_programs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('obedience_id')->constrained()->restrictOnDelete();
            $t->string('code', 40);
            $t->string('description', 160);
            $t->enum('level', ['national', 'regional', 'provincial']);
            $t->enum('periodicity', ['monthly', 'quarterly', 'semiannual', 'annual']);
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->timestamp('created_at', 6);
            $t->unique(['obedience_id', 'code'], 'levy_program_code');
            $t->unique(['obedience_id', 'id'], 'levy_program_owner');
        });
        Schema::create('levy_conditions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('obedience_id');
            $t->unsignedBigInteger('program_id');
            $t->foreignId('structure_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('lodge_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('scope_key', 64);
            $t->date('effective_on');
            $t->date('ends_on')->nullable();
            $t->enum('basis', ['fixed', 'per_member']);
            $t->decimal('amount', 15, 2);
            $t->enum('count_criterion', ['active_at_start', 'active_non_exempt_at_start']);
            $t->enum('due_basis', ['start', 'end']);
            $t->unsignedSmallInteger('due_days');
            $t->string('recipient_iban', 34)->nullable();
            $t->boolean('is_active');
            $t->text('reason');
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at', 6);
            $t->foreign(['obedience_id', 'program_id'], 'levy_condition_program')->references(['obedience_id', 'id'])->on('levy_programs')->restrictOnDelete();
            $t->foreign(['obedience_id', 'structure_id'], 'levy_condition_structure')->references(['obedience_id', 'id'])->on('structures')->restrictOnDelete();
            $t->foreign(['obedience_id', 'lodge_id'], 'levy_condition_lodge')->references(['obedience_id', 'id'])->on('lodges')->restrictOnDelete();
            $t->unique(['obedience_id', 'program_id', 'id'], 'levy_condition_owner');
            $t->unique(['program_id', 'scope_key', 'effective_on'], 'levy_condition_version');
            $t->index(['lodge_id', 'program_id']);
        });
        Schema::create('levy_obligations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lodge_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('obedience_id');
            $t->unsignedBigInteger('program_id');
            $t->foreignId('condition_id')->constrained('levy_conditions')->restrictOnDelete();
            $t->foreignId('assignment_id')->constrained('lodge_territorial_assignments')->restrictOnDelete();
            $t->foreignId('recipient_id')->constrained('structures')->restrictOnDelete();
            $t->date('period_start');
            $t->date('period_end');
            $t->date('reference_on');
            $t->date('due_on');
            $t->string('count_criterion', 40);
            $t->unsignedInteger('member_count');
            $t->decimal('amount', 15, 2);
            $t->string('recipient_name', 160);
            $t->text('recipient_iban')->nullable();
            $t->json('snapshot');
            $t->timestamp('created_at', 6);
            $t->foreign(['obedience_id', 'program_id'], 'levy_obligation_program')->references(['obedience_id', 'id'])->on('levy_programs')->restrictOnDelete();
            $t->foreign(['obedience_id', 'lodge_id'], 'levy_obligation_lodge')->references(['obedience_id', 'id'])->on('lodges')->restrictOnDelete();
            $t->foreign(['obedience_id', 'program_id', 'condition_id'], 'levy_obligation_condition')->references(['obedience_id', 'program_id', 'id'])->on('levy_conditions')->restrictOnDelete();
            $t->foreign(['lodge_id', 'obedience_id', 'recipient_id', 'assignment_id'], 'levy_obligation_assignment')->references(['lodge_id', 'obedience_id', 'structure_id', 'id'])->on('lodge_territorial_assignments')->restrictOnDelete();
            $t->unique(['lodge_id', 'program_id', 'period_start'], 'levy_obligation_cycle');
            $t->unique(['lodge_id', 'id'], 'levy_obligation_tenant');
            $t->index(['lodge_id', 'due_on']);
        });
        Schema::create('levy_payments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('lodge_id');
            $t->unsignedBigInteger('obligation_id');
            $t->decimal('amount', 15, 2);
            $t->date('paid_on');
            $t->string('account_reference', 64);
            $t->string('account_label', 160);
            $t->string('operation_reference', 64);
            $t->string('idempotency_key', 64);
            $t->string('fingerprint', 64);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at', 6);
            $t->foreign(['lodge_id', 'obligation_id'], 'levy_payment_due')->references(['lodge_id', 'id'])->on('levy_obligations')->restrictOnDelete();
            $t->unique(['lodge_id', 'idempotency_key'], 'levy_payment_key');
            $t->unique(['lodge_id', 'operation_reference'], 'levy_payment_posting');
            $t->index(['lodge_id', 'paid_on']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE levy_programs ADD CONSTRAINT levy_program_dates CHECK(ends_on IS NULL OR ends_on >= starts_on)');
            DB::statement('ALTER TABLE levy_conditions ADD CONSTRAINT levy_condition_amount CHECK(amount >= 0), ADD CONSTRAINT levy_condition_due CHECK(due_days <= 365), ADD CONSTRAINT levy_condition_dates CHECK(ends_on IS NULL OR ends_on >= effective_on), ADD CONSTRAINT levy_condition_scope CHECK(structure_id IS NULL OR lodge_id IS NULL)');
            DB::statement('ALTER TABLE levy_obligations ADD CONSTRAINT levy_obligation_amount CHECK(amount >= 0), ADD CONSTRAINT levy_obligation_dates CHECK(period_end >= period_start AND reference_on = period_start)');
            DB::statement('ALTER TABLE levy_payments ADD CONSTRAINT levy_payment_positive CHECK(amount > 0)');
        }
    }

    public function down(): void
    {
        foreach (['levy_payments', 'levy_obligations', 'levy_conditions', 'levy_programs'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('lodge_territorial_assignments', fn (Blueprint $t) => $t->dropUnique('assignment_levy_owner'));
    }
};
