<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('member_exemption_periods')) {
            Schema::create('member_exemption_periods', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('enrollment_id');
                $table->foreignId('lodge_id')->constrained('lodges')->restrictOnDelete();
                $table->date('starts_on');
                $table->date('ends_on')->nullable();
                $table->timestamp('created_at');
                $table->unique(['enrollment_id', 'starts_on']);
                $table->foreign(['lodge_id', 'enrollment_id'])->references(['lodge_id', 'id'])->on('member_enrollments')->restrictOnDelete();
                $table->index(['lodge_id', 'enrollment_id', 'starts_on', 'ends_on']);
            });
        }
        // Normalize legacy flags without changing any financial record.
        DB::table('member_enrollments')->whereIn('status', ['suspended', 'ended'])->update(['administrative_exemption' => true]);
        foreach (DB::table('member_enrollments')->where('administrative_exemption', true)->orderBy('id')->cursor() as $e) {
            $start = $e->status === 'ended' && $e->ends_on ? CarbonImmutable::parse($e->ends_on)->addDay()->toDateString() : substr($e->starts_on, 0, 10);
            DB::table('member_exemption_periods')->insertOrIgnore(['enrollment_id' => $e->id, 'lodge_id' => $e->lodge_id, 'starts_on' => $start, 'created_at' => now('UTC')]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback automatico bloccato: i periodi di esenzione sono storico amministrativo da conservare.');
    }
};
