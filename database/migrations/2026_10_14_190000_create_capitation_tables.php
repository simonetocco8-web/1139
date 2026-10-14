<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_enrollments', fn (Blueprint $t) => $t->unique(['lodge_id', 'person_id', 'id'], 'enrollment_cap_owner'));
        Schema::create('capitation_plans', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lodge_id')->constrained('lodges')->restrictOnDelete();
            $t->string('description', 160);
            $t->enum('periodicity', ['monthly', 'quarterly', 'semiannual', 'annual']);
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->timestamps(6);
            $t->unique(['lodge_id', 'id'], 'cap_plan_tenant');
        });
        Schema::create('capitation_plan_versions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('lodge_id');
            $t->unsignedBigInteger('plan_id');
            $t->date('effective_on');
            $t->string('description', 160);
            $t->decimal('amount', 15, 2);
            $t->enum('due_basis', ['start', 'end']);
            $t->unsignedTinyInteger('due_days');
            $t->enum('prorata', ['none', 'daily']);
            $t->boolean('is_active');
            $t->timestamp('created_at', 6);
            $t->foreign(['lodge_id', 'plan_id'], 'cap_version_plan')->references(['lodge_id', 'id'])->on('capitation_plans')->restrictOnDelete();
            $t->unique(['lodge_id', 'plan_id', 'effective_on'], 'cap_version_date');
        });
        Schema::create('capitation_person_rules', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('lodge_id');
            $t->unsignedBigInteger('plan_id');
            $t->unsignedBigInteger('person_id');
            $t->date('effective_on');
            $t->date('ends_on')->nullable();
            $t->enum('kind', ['standard', 'exempt', 'discount', 'custom']);
            $t->unsignedSmallInteger('discount_bps')->nullable();
            $t->decimal('amount', 15, 2)->nullable();
            $t->text('reason');
            $t->timestamp('created_at', 6);
            $t->foreign(['lodge_id', 'plan_id'], 'cap_rule_plan')->references(['lodge_id', 'id'])->on('capitation_plans')->restrictOnDelete();
            $t->foreign(['lodge_id', 'person_id'], 'cap_rule_person')->references(['lodge_id', 'id'])->on('member_people')->restrictOnDelete();
            $t->unique(['lodge_id', 'plan_id', 'person_id', 'effective_on'], 'cap_rule_date');
        });
        Schema::create('capitation_installments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('lodge_id');
            $t->unsignedBigInteger('plan_id');
            $t->unsignedBigInteger('person_id');
            $t->unsignedBigInteger('enrollment_id');
            $t->date('period_start');
            $t->date('period_end');
            $t->date('due_on');
            $t->decimal('original_amount', 15, 2);
            $t->json('snapshot');
            $t->timestamp('created_at', 6);
            $t->foreign(['lodge_id', 'plan_id'], 'cap_due_plan')->references(['lodge_id', 'id'])->on('capitation_plans')->restrictOnDelete();
            $t->foreign(['lodge_id', 'person_id'], 'cap_due_person')->references(['lodge_id', 'id'])->on('member_people')->restrictOnDelete();
            $t->foreign(['lodge_id', 'person_id', 'enrollment_id'], 'cap_due_enrollment')->references(['lodge_id', 'person_id', 'id'])->on('member_enrollments')->restrictOnDelete();
            $t->unique(['lodge_id', 'plan_id', 'person_id', 'period_start'], 'cap_due_cycle');
            $t->unique(['lodge_id', 'id'], 'cap_due_tenant');
            $t->unique(['lodge_id', 'person_id', 'id'], 'cap_due_owner');
            $t->index(['lodge_id', 'due_on'], 'cap_due_calendar');
        });
        Schema::create('capitation_corrections', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('lodge_id');
            $t->unsignedBigInteger('installment_id');
            $t->decimal('amount', 15, 2);
            $t->text('reason');
            $t->string('idempotency_key', 64);
            $t->string('fingerprint', 64);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at', 6);
            $t->foreign(['lodge_id', 'installment_id'], 'cap_correction_due')->references(['lodge_id', 'id'])->on('capitation_installments')->restrictOnDelete();
            $t->unique(['lodge_id', 'idempotency_key'], 'cap_correction_key');
        });
        Schema::create('capitation_receipts', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('lodge_id');
            $t->unsignedBigInteger('person_id');
            $t->decimal('amount', 15, 2);
            $t->date('received_on');
            $t->string('account_reference', 64);
            $t->string('account_label', 160);
            $t->string('operation_reference', 64);
            $t->string('idempotency_key', 64);
            $t->string('fingerprint', 64);
            $t->timestamp('created_at', 6);
            $t->foreign(['lodge_id', 'person_id'], 'cap_receipt_person')->references(['lodge_id', 'id'])->on('member_people')->restrictOnDelete();
            $t->unique(['lodge_id', 'id'], 'cap_receipt_tenant');
            $t->unique(['lodge_id', 'person_id', 'id'], 'cap_receipt_owner');
            $t->unique(['lodge_id', 'idempotency_key'], 'cap_receipt_key');
            $t->unique(['lodge_id', 'operation_reference'], 'cap_receipt_posting');
        });
        Schema::create('capitation_allocation_batches', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('lodge_id');
            $t->unsignedBigInteger('receipt_id');
            $t->string('idempotency_key', 64);
            $t->string('fingerprint', 64);
            $t->timestamp('created_at', 6);
            $t->foreign(['lodge_id', 'receipt_id'], 'cap_batch_receipt')->references(['lodge_id', 'id'])->on('capitation_receipts')->restrictOnDelete();
            $t->unique(['lodge_id', 'idempotency_key'], 'cap_batch_key');
        });
        Schema::create('capitation_allocations', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('lodge_id');
            $t->unsignedBigInteger('person_id');
            $t->unsignedBigInteger('receipt_id');
            $t->unsignedBigInteger('installment_id');
            $t->decimal('amount', 15, 2);
            $t->unsignedBigInteger('reverses_id')->nullable();
            $t->text('reason')->nullable();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at', 6);
            $t->foreign(['lodge_id', 'person_id', 'receipt_id'], 'cap_alloc_receipt')->references(['lodge_id', 'person_id', 'id'])->on('capitation_receipts')->restrictOnDelete();
            $t->foreign(['lodge_id', 'person_id', 'installment_id'], 'cap_alloc_due')->references(['lodge_id', 'person_id', 'id'])->on('capitation_installments')->restrictOnDelete();
            $t->unique(['lodge_id', 'id'], 'cap_alloc_tenant');
            $t->foreign(['lodge_id', 'reverses_id'], 'cap_alloc_reverse')->references(['lodge_id', 'id'])->on('capitation_allocations')->restrictOnDelete();
            $t->unique('reverses_id', 'cap_alloc_one_reverse');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE capitation_plan_versions ADD CONSTRAINT cap_version_amount CHECK(amount > 0), ADD CONSTRAINT cap_version_due CHECK(due_days <= 60)');
            DB::statement("ALTER TABLE capitation_person_rules ADD CONSTRAINT cap_rule_values CHECK ((kind IN ('standard','exempt') AND amount IS NULL AND discount_bps IS NULL) OR (kind='custom' AND amount IS NOT NULL AND amount >= 0 AND discount_bps IS NULL) OR (kind='discount' AND amount IS NULL AND discount_bps IS NOT NULL AND discount_bps <= 10000))");
            DB::statement('ALTER TABLE capitation_installments ADD CONSTRAINT cap_due_nonnegative CHECK(original_amount >= 0), ADD CONSTRAINT cap_due_dates CHECK(period_end >= period_start)');
            DB::statement('ALTER TABLE capitation_receipts ADD CONSTRAINT cap_receipt_positive CHECK(amount > 0)');
            DB::statement('ALTER TABLE capitation_allocations ADD CONSTRAINT cap_alloc_nonzero CHECK(amount <> 0)');
        }
    }

    public function down(): void
    {
        foreach (['capitation_allocations', 'capitation_allocation_batches', 'capitation_receipts', 'capitation_corrections', 'capitation_installments', 'capitation_person_rules', 'capitation_plan_versions', 'capitation_plans'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('member_enrollments', fn (Blueprint $t) => $t->dropUnique('enrollment_cap_owner'));
    }
};
