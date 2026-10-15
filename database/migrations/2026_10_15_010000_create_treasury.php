<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('levy_payments', fn (Blueprint $t) => $t->unique(['lodge_id', 'id'], 'levy_payment_treasury_owner'));
        Schema::create('treasury_accounts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lodge_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->enum('type', ['bank', 'cash']);
            $t->text('iban')->nullable();
            $t->char('currency', 3);
            $t->decimal('opening_amount', 15, 2);
            $t->date('opened_on');
            $t->string('idempotency_key', 64);
            $t->string('fingerprint', 64);
            $t->timestamp('created_at', 6);
            $t->unique(['lodge_id', 'id'], 'treasury_account_tenant');
            $t->unique(['lodge_id', 'name'], 'treasury_account_name');
            $t->unique(['lodge_id', 'idempotency_key'], 'treasury_account_key');
        });
        Schema::create('treasury_categories', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lodge_id')->constrained()->restrictOnDelete();
            $t->string('name', 80);
            $t->enum('type', ['income', 'expense']);
            $t->unique(['lodge_id', 'id'], 'treasury_category_tenant');
            $t->unique(['lodge_id', 'name', 'type'], 'treasury_category_name');
        });
        Schema::create('treasury_operations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lodge_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('obedience_id');
            $t->foreign(['obedience_id', 'lodge_id'], 'treasury_operation_lodge')->references(['obedience_id', 'id'])->on('lodges')->restrictOnDelete();
            $t->ulid('public_id')->unique();
            $t->string('kind', 24);
            $t->date('booked_on');
            $t->text('description');
            $t->unsignedBigInteger('category_id')->nullable();
            $t->unsignedBigInteger('person_id')->nullable();
            $t->foreignId('structure_id')->nullable()->constrained('structures')->restrictOnDelete();
            $t->unsignedBigInteger('reverses_id')->nullable();
            $t->string('idempotency_key', 80);
            $t->string('fingerprint', 64);
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at', 6);
            $t->foreign(['obedience_id', 'structure_id'], 'treasury_operation_structure')->references(['obedience_id', 'id'])->on('structures')->restrictOnDelete();
            $t->unique(['lodge_id', 'id'], 'treasury_operation_tenant');
            $t->unique(['lodge_id', 'idempotency_key'], 'treasury_operation_key');
            $t->unique('reverses_id', 'treasury_one_reversal');
            $t->foreign(['lodge_id', 'reverses_id'], 'treasury_reversal_owner')->references(['lodge_id', 'id'])->on('treasury_operations')->restrictOnDelete();
            $t->foreign(['lodge_id', 'category_id'], 'treasury_category_owner')->references(['lodge_id', 'id'])->on('treasury_categories')->restrictOnDelete();
            $t->foreign(['lodge_id', 'person_id'], 'treasury_person_owner')->references(['lodge_id', 'id'])->on('member_people')->restrictOnDelete();
            $t->index(['lodge_id', 'booked_on', 'kind']);
        });
        Schema::create('treasury_lines', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('lodge_id');
            $t->unsignedBigInteger('operation_id');
            $t->unsignedBigInteger('account_id')->nullable();
            $t->enum('bucket', ['financial', 'income', 'expense', 'equity']);
            $t->decimal('amount', 15, 2);
            $t->unique(['lodge_id', 'id'], 'treasury_line_tenant');
            $t->foreign(['lodge_id', 'operation_id'], 'treasury_line_operation')->references(['lodge_id', 'id'])->on('treasury_operations')->restrictOnDelete();
            $t->foreign(['lodge_id', 'account_id'], 'treasury_line_account')->references(['lodge_id', 'id'])->on('treasury_accounts')->restrictOnDelete();
            $t->index(['lodge_id', 'account_id', 'operation_id']);
        });
        Schema::create('treasury_source_links', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('lodge_id');
            $t->unsignedBigInteger('operation_id');
            $t->unsignedBigInteger('receipt_id')->nullable();
            $t->unsignedBigInteger('payment_id')->nullable();
            $t->foreign(['lodge_id', 'operation_id'], 'treasury_source_operation')->references(['lodge_id', 'id'])->on('treasury_operations')->restrictOnDelete();
            $t->foreign(['lodge_id', 'receipt_id'], 'treasury_source_receipt')->references(['lodge_id', 'id'])->on('capitation_receipts')->restrictOnDelete();
            $t->foreign(['lodge_id', 'payment_id'], 'treasury_source_payment')->references(['lodge_id', 'id'])->on('levy_payments')->restrictOnDelete();
            $t->unique('operation_id');
            $t->unique('receipt_id');
            $t->unique('payment_id');
        });
        foreach (['capitation_receipt_voids' => ['receipt_id', 'capitation_receipts'], 'levy_payment_voids' => ['payment_id', 'levy_payments']] as $table => [$col,$parent]) {
            Schema::create($table, function (Blueprint $t) use ($col, $parent, $table): void {
                $t->id();
                $t->unsignedBigInteger('lodge_id');
                $t->unsignedBigInteger($col)->unique();
                $t->unsignedBigInteger('operation_id');
                $t->foreign(['lodge_id', $col], $table.'_source')->references(['lodge_id', 'id'])->on($parent)->restrictOnDelete();
                $t->foreign(['lodge_id', 'operation_id'], $table.'_operation')->references(['lodge_id', 'id'])->on('treasury_operations')->restrictOnDelete();
            });
        }
        Schema::create('treasury_attachments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('lodge_id');
            $t->unsignedBigInteger('operation_id');
            $t->text('name');
            $t->string('path', 200)->unique();
            $t->string('mime', 50);
            $t->unsignedInteger('size');
            $t->char('sha256', 64);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at', 6);
            $t->foreign(['lodge_id', 'operation_id'], 'treasury_attachment_owner')->references(['lodge_id', 'id'])->on('treasury_operations')->restrictOnDelete();
        });
        Schema::create('treasury_statement_entries', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('lodge_id');
            $t->unsignedBigInteger('account_id');
            $t->date('booked_on');
            $t->decimal('amount', 15, 2);
            $t->text('description');
            $t->text('reference');
            $t->char('reference_digest', 64);
            $t->char('fingerprint', 64);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at', 6);
            $t->unique(['lodge_id', 'id'], 'treasury_statement_tenant');
            $t->unique(['account_id', 'reference_digest'], 'treasury_statement_reference');
            $t->index(['account_id', 'fingerprint'], 'treasury_statement_similarity');
            $t->foreign(['lodge_id', 'account_id'], 'treasury_statement_account')->references(['lodge_id', 'id'])->on('treasury_accounts')->restrictOnDelete();
        });
        Schema::create('treasury_reconciliations', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('lodge_id');
            $t->unsignedBigInteger('statement_entry_id');
            $t->unsignedBigInteger('line_id');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at', 6);
            $t->timestamp('ended_at', 6)->nullable();
            $t->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('release_reason')->nullable();
            $t->unsignedBigInteger('active_entry_id')->nullable()->storedAs('CASE WHEN ended_at IS NULL THEN statement_entry_id ELSE NULL END');
            $t->unsignedBigInteger('active_line_id')->nullable()->storedAs('CASE WHEN ended_at IS NULL THEN line_id ELSE NULL END');
            $t->unique(['lodge_id', 'active_entry_id'], 'treasury_reconcile_entry');
            $t->unique(['lodge_id', 'active_line_id'], 'treasury_reconcile_line');
            $t->foreign(['lodge_id', 'statement_entry_id'], 'treasury_reconcile_statement')->references(['lodge_id', 'id'])->on('treasury_statement_entries')->restrictOnDelete();
            $t->foreign(['lodge_id', 'line_id'], 'treasury_reconcile_line_owner')->references(['lodge_id', 'id'])->on('treasury_lines')->restrictOnDelete();
        });
        Schema::create('treasury_imports', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lodge_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('account_id');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->char('token_digest', 64);
            $t->char('session_digest', 64);
            $t->longText('payload');
            $t->timestamp('expires_at', 6);
            $t->timestamp('confirmed_at', 6)->nullable();
            $t->unsignedInteger('created_count')->nullable();
            $t->timestamp('created_at', 6);
            $t->foreign(['lodge_id', 'account_id'], 'treasury_import_account')->references(['lodge_id', 'id'])->on('treasury_accounts')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE treasury_accounts ADD CONSTRAINT treasury_account_currency CHECK(currency='EUR'), ADD CONSTRAINT treasury_cash_opening CHECK(type <> 'cash' OR opening_amount >= 0)");
            DB::statement("ALTER TABLE treasury_lines ADD CONSTRAINT treasury_line_bucket CHECK((bucket='financial' AND account_id IS NOT NULL) OR (bucket<>'financial' AND account_id IS NULL))");
            DB::statement('ALTER TABLE treasury_source_links ADD CONSTRAINT treasury_source_kind CHECK((receipt_id IS NULL) <> (payment_id IS NULL))');
            DB::statement('ALTER TABLE treasury_statement_entries ADD CONSTRAINT treasury_statement_nonzero CHECK(amount <> 0)');
        }
    }

    public function down(): void
    {
        foreach (['treasury_imports', 'treasury_reconciliations', 'treasury_statement_entries', 'treasury_attachments', 'levy_payment_voids', 'capitation_receipt_voids', 'treasury_source_links', 'treasury_lines', 'treasury_operations', 'treasury_categories', 'treasury_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('levy_payments', fn (Blueprint $t) => $t->dropUnique('levy_payment_treasury_owner'));
    }
};
