<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'member_exemption_periods';

    public function up(): void
    {
        $this->ensureStructure();

        // Backfill only after the complete structure has been verified/repaired.
        DB::table('member_enrollments')->whereIn('status', ['suspended', 'ended'])->update(['administrative_exemption' => true]);
        foreach (DB::table('member_enrollments')->where('administrative_exemption', true)->orderBy('id')->cursor() as $e) {
            $start = $e->status === 'ended' && $e->ends_on ? CarbonImmutable::parse($e->ends_on)->addDay()->toDateString() : substr($e->starts_on, 0, 10);
            // Do not hide FK/data errors with MySQL INSERT IGNORE.
            if (! DB::table(self::TABLE)->where('enrollment_id', $e->id)->where('starts_on', $start)->exists()) {
                DB::table(self::TABLE)->insert(['enrollment_id' => $e->id, 'lodge_id' => $e->lodge_id, 'starts_on' => $start, 'created_at' => now('UTC')]);
            }
        }
    }

    public function ensureStructure(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            // MySQL DDL is not transactional: create columns first, then reconcile
            // every index and constraint even if an earlier attempt left the table.
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('enrollment_id');
                $table->unsignedBigInteger('lodge_id');
                $table->date('starts_on');
                $table->date('ends_on')->nullable();
                $table->timestamp('created_at');
            });
        }
        if (! Schema::hasColumns(self::TABLE, ['id', 'enrollment_id', 'lodge_id', 'starts_on', 'ends_on', 'created_at'])) {
            throw new RuntimeException('Tabella esenzioni incompleta: mancano colonne obbligatorie. Ripristinare la struttura senza cancellare i dati e rieseguire la migrazione.');
        }
        if (! collect(Schema::getIndexes(self::TABLE))->contains(fn ($i) => $i['primary'] && $i['columns'] === ['id'])) {
            throw new RuntimeException('Tabella esenzioni incompleta: chiave primaria id mancante o incompatibile.');
        }
        $this->index('member_exemption_periods_unique', ['enrollment_id', 'starts_on'], true);
        $this->index('member_exemption_periods_idx', ['lodge_id', 'enrollment_id', 'starts_on', 'ends_on'], false);
        $this->foreign('member_exemption_lodge_fk', ['lodge_id'], 'lodges', ['id']);
        $this->foreign('member_exemption_enrollment_fk', ['lodge_id', 'enrollment_id'], 'member_enrollments', ['lodge_id', 'id']);
    }

    /** @param list<string> $columns */
    private function index(string $name, array $columns, bool $unique): void
    {
        $equivalent = false;
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            $matches = $index['columns'] === $columns && $index['unique'] === $unique && ! $index['primary'];
            if ($index['name'] === $name && ! $matches) {
                throw new RuntimeException('Indice esenzioni incompatibile: '.$name);
            }
            $equivalent = $equivalent || $matches;
        }
        if (! $equivalent) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $name, $unique): void {
                if ($unique) {
                    $table->unique($columns, $name);
                } else {
                    $table->index($columns, $name);
                }
            });
        }
    }

    /** @param list<string> $columns
     * @param list<string> $references */
    private function foreign(string $name, array $columns, string $parent, array $references): void
    {
        $equivalent = false;
        foreach (Schema::getForeignKeys(self::TABLE) as $foreign) {
            if ($foreign['columns'] === $columns || $foreign['name'] === $name) {
                $wrongSchema = DB::connection()->getDriverName() === 'mysql' && $foreign['foreign_schema'] !== DB::connection()->getDatabaseName();
                if ($wrongSchema || $foreign['columns'] !== $columns || $foreign['foreign_table'] !== $parent || $foreign['foreign_columns'] !== $references || ! in_array(strtolower($foreign['on_delete']), ['restrict', 'no action'], true) || ! in_array(strtolower($foreign['on_update']), ['restrict', 'no action'], true)) {
                    throw new RuntimeException('Vincolo esenzioni incompatibile: '.$name);
                }

                $equivalent = true;
            }
        }
        if ($equivalent) {
            return;
        }
        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $name, $parent, $references): void {
            $table->foreign($columns, $name)->references($references)->on($parent)->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Rollback automatico bloccato: i periodi di esenzione sono storico amministrativo da conservare.');
        }
        Schema::dropIfExists(self::TABLE);
    }
};
