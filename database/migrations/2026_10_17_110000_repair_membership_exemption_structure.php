<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Also repair installations where the former hasTable shortcut caused
        // an incomplete table to be recorded as migrated. Do not rerun backfill.
        $migration = require __DIR__.'/2026_10_17_100000_track_membership_exemption_periods.php';
        if (! $migration instanceof Migration || ! method_exists($migration, 'ensureStructure')) {
            throw new RuntimeException('Rilasciare insieme la migrazione esenzioni corretta e la sua riparazione.');
        }
        $migration->ensureStructure();
    }

    public function down(): void
    {
        // A rollback must not remove constraints required to preserve history.
    }
};
