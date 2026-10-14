<?php

namespace Tests\MySQL;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_eight_engine_and_stored_generated_active_assignment_key(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $version = DB::selectOne('SELECT VERSION() AS version');
        $this->assertStringStartsWith('8.', $version->version);
        $column = DB::selectOne("SELECT EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lodge_territorial_assignments' AND COLUMN_NAME = 'active_level'");
        $this->assertStringContainsString('STORED GENERATED', $column->extra);
    }

    public function test_organization_tables_use_innodb_and_database_enforces_foreign_keys(): void
    {
        foreach (['obediences', 'rites', 'structures', 'lodges', 'lodge_territorial_assignments'] as $table) {
            $row = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            $this->assertSame('InnoDB', $row->engine);
        }
        $settings = DB::selectOne('SELECT @@foreign_key_checks AS enabled');
        $this->assertSame(1, (int) $settings->enabled);
    }
}
