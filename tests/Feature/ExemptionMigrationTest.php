<?php

namespace Tests\Feature;

use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExemptionMigrationTest extends TestCase
{
    private bool $isolated = false;

    private const TABLE = 'member_exemption_periods';

    private const MIGRATION = '2026_10_17_100000_track_membership_exemption_periods';

    protected function setUp(): void
    {
        parent::setUp();
        $driver = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || ! (($driver === 'sqlite' && $database === ':memory:') || ($driver === 'mysql' && str_ends_with($database, '_test')))) {
            throw new \LogicException('Usare esclusivamente un database di test isolato.');
        }
        $this->isolated = true;
        // DDL commits implicitly on MySQL: do not wrap these tests in a transaction.
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->isolated) {
                $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_all_migrations_use_mysql_compatible_identifier_lengths(): void
    {
        foreach (Schema::getTableListing() as $table) {
            foreach (array_merge(Schema::getIndexes($table), Schema::getForeignKeys($table)) as $entry) {
                if ($entry['name'] !== null) {
                    $this->assertLessThanOrEqual(64, strlen($entry['name']), $table.': '.$entry['name']);
                }
            }
        }
        $this->assertContains('member_exemption_periods_idx', array_column(Schema::getIndexes(self::TABLE), 'name'));
        $this->assertContains('member_exemption_periods_unique', array_column(Schema::getIndexes(self::TABLE), 'name'));
        $this->assertCount(2, Schema::getForeignKeys(self::TABLE));
    }

    public function test_migrate_repairs_a_bare_partial_table_preserving_rows_and_repeats_safely(): void
    {
        [$lodge, $enrollment] = $this->fixture();
        $this->partial();
        DB::table(self::TABLE)->insert(['id' => 17, 'lodge_id' => $lodge->id, 'enrollment_id' => $enrollment, 'starts_on' => '2026-02-01', 'ends_on' => '2026-02-28', 'created_at' => '2026-02-01 00:00:00']);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->migration()->up();
        $this->assertDatabaseHas(self::TABLE, ['id' => 17, 'ends_on' => '2026-02-28']);
        $this->assertDatabaseCount(self::TABLE, 1);
        $this->assertCount(2, Schema::getForeignKeys(self::TABLE));
        $this->assertContains('member_exemption_periods_idx', array_column(Schema::getIndexes(self::TABLE), 'name'));
        $this->assertDatabaseHas('migrations', ['migration' => self::MIGRATION]);
        try {
            DB::table(self::TABLE)->insert(['lodge_id' => $lodge->id, 'enrollment_id' => $enrollment, 'starts_on' => '2026-02-01', 'created_at' => now('UTC')]);
            $this->fail('Indice univoco non ripristinato.');
        } catch (QueryException) {
            $this->assertDatabaseCount(self::TABLE, 1);
        }
        $other = Lodge::query()->create(['obedience_id' => $lodge->obedience_id, 'rite_id' => $lodge->rite_id, 'name' => 'Altra Loggia Fittizia', 'number' => '2', 'is_active' => true]);
        $this->expectException(QueryException::class);
        DB::table(self::TABLE)->insert(['lodge_id' => $other->id, 'enrollment_id' => $enrollment, 'starts_on' => '2026-03-01', 'created_at' => now('UTC')]);
    }

    public function test_partial_table_with_legacy_unique_index_and_one_fk_is_completed(): void
    {
        $this->partial();
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->unique(['enrollment_id', 'starts_on']);
            $table->foreign('lodge_id')->references('id')->on('lodges')->restrictOnDelete();
        });
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->migration()->up();
        $this->assertCount(2, Schema::getForeignKeys(self::TABLE));
        $uniques = array_filter(Schema::getIndexes(self::TABLE), fn ($i) => $i['unique'] && ! $i['primary']);
        $this->assertCount(1, $uniques);
        $this->assertContains('member_exemption_periods_idx', array_column(Schema::getIndexes(self::TABLE), 'name'));
    }

    public function test_incompatible_named_index_fails_without_registering_migration(): void
    {
        $this->partial();
        Schema::table(self::TABLE, fn (Blueprint $table) => $table->index('starts_on', 'member_exemption_periods_idx'));
        try {
            $this->artisan('migrate', ['--force' => true]);
            $this->fail('Una struttura incompatibile non deve risultare migrata.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Indice esenzioni incompatibile', $e->getMessage());
            $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
        }
    }

    public function test_missing_columns_fail_closed_and_leave_migration_pending(): void
    {
        $this->partial();
        Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn('ends_on'));
        try {
            $this->artisan('migrate', ['--force' => true]);
            $this->fail('Non deve essere registrata una tabella incompleta.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('mancano colonne', $e->getMessage());
            $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
        }
    }

    public function test_duplicate_legacy_rows_block_unique_repair_without_discarding_data(): void
    {
        [$lodge,$enrollment] = $this->fixture();
        $this->partial();
        foreach ([17, 18] as $id) {
            DB::table(self::TABLE)->insert(['id' => $id, 'lodge_id' => $lodge->id, 'enrollment_id' => $enrollment, 'starts_on' => '2026-02-01', 'created_at' => now('UTC')]);
        }
        try {
            $this->artisan('migrate', ['--force' => true]);
            $this->fail('Dati duplicati non devono essere cancellati o ignorati.');
        } catch (QueryException) {
            $this->assertDatabaseCount(self::TABLE, 2);
            $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
        }
    }

    public function test_incompatible_foreign_key_does_not_get_accepted_as_complete(): void
    {
        $this->partial();
        Schema::table(self::TABLE, fn (Blueprint $table) => $table->foreign('lodge_id', 'legacy_lodge_fk')->references('id')->on('lodges')->cascadeOnDelete());
        try {
            $this->artisan('migrate', ['--force' => true]);
            $this->fail('Un vincolo distruttivo non deve essere accettato.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Vincolo esenzioni incompatibile', $e->getMessage());
            $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
        }
    }

    public function test_followup_repairs_an_incomplete_table_already_recorded_as_migrated(): void
    {
        [$lodge,$enrollment] = $this->fixture();
        $this->partial();
        DB::table('migrations')->insert(['migration' => self::MIGRATION, 'batch' => 1]);
        DB::table('migrations')->where('migration', '2026_10_17_110000_repair_membership_exemption_structure')->delete();
        DB::table(self::TABLE)->insert(['id' => 17, 'lodge_id' => $lodge->id, 'enrollment_id' => $enrollment, 'starts_on' => '2026-02-01', 'created_at' => now('UTC')]);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseCount(self::TABLE, 1);
        $this->assertDatabaseHas(self::TABLE, ['id' => 17]);
        $this->assertCount(2, Schema::getForeignKeys(self::TABLE));
        $this->assertContains('member_exemption_periods_idx', array_column(Schema::getIndexes(self::TABLE), 'name'));
        $this->assertDatabaseHas('migrations', ['migration' => '2026_10_17_110000_repair_membership_exemption_structure']);
    }

    public function test_rollback_can_remove_an_empty_table_but_preserves_exemption_history(): void
    {
        $this->migration()->down();
        $this->assertFalse(Schema::hasTable(self::TABLE));
        $this->migration()->up();
        [$lodge,$enrollment] = $this->fixture();
        DB::table(self::TABLE)->insert(['lodge_id' => $lodge->id, 'enrollment_id' => $enrollment, 'starts_on' => '2026-02-01', 'created_at' => now('UTC')]);
        try {
            $this->migration()->down();
            $this->fail('Lo storico delle esenzioni non deve essere eliminato.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('storico amministrativo', $e->getMessage());
            $this->assertDatabaseCount(self::TABLE, 1);
        }
    }

    private function partial(): void
    {
        Schema::drop(self::TABLE);
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('lodge_id');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamp('created_at');
        });
    }

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }

    /** @return array{Lodge,int} */
    private function fixture(): array
    {
        $o = Obedience::query()->create(['code' => 'MIG', 'name' => 'Obbedienza Fittizia Migrazioni', 'is_active' => true]);
        $r = Rite::query()->create(['code' => 'MIG', 'name' => 'Rito Fittizio', 'is_active' => true]);
        $o->rites()->attach($r);
        $lodge = Lodge::query()->create(['obedience_id' => $o->id, 'rite_id' => $r->id, 'number' => '1', 'name' => 'Loggia Fittizia Migrazioni', 'is_active' => true]);
        $person = Person::query()->forceCreate(['lodge_id' => $lodge->id, 'reference' => 'MIG_1', 'first_name' => 'NomeFittizio', 'last_name' => 'CognomeFittizio', 'version' => 1]);
        $enrollment = DB::table('member_enrollments')->insertGetId(['lodge_id' => $lodge->id, 'person_id' => $person->id, 'starts_on' => '2026-01-01', 'status' => 'active', 'administrative_exemption' => false, 'version' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return [$lodge, $enrollment];
    }
}
