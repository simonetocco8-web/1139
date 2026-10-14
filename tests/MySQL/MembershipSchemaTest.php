<?php

namespace Tests\MySQL;

use App\Domains\Membership\Models\Enrollment;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Rite;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MembershipSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function person(): Person
    {
        $this->assertSame('mysql', DB::getDriverName());
        $owner = Obedience::query()->create(['code' => 'M_SCHEMA', 'name' => 'Obbedienza Schema Fittizia', 'is_active' => true]);
        $rite = Rite::query()->create(['code' => 'M_SCHEMA', 'name' => 'Rito Schema Fittizio', 'is_active' => true]);
        $owner->rites()->attach($rite);
        $lodge = Lodge::query()->create(['obedience_id' => $owner->id, 'rite_id' => $rite->id, 'name' => 'Loggia Schema Fittizia', 'number' => '1', 'is_active' => true]);

        return Person::query()->forceCreate(['lodge_id' => $lodge->id, 'reference' => 'SCHEMA', 'first_name' => 'NomeFittizio', 'last_name' => 'CognomeFittizio']);
    }

    public function test_mysql_rejects_end_before_start(): void
    {
        $person = $this->person();
        $this->expectException(QueryException::class);
        Enrollment::query()->forceCreate(['lodge_id' => $person->lodge_id, 'person_id' => $person->id, 'starts_on' => '2026-01-02', 'ends_on' => '2026-01-01', 'status' => 'ended']);
    }

    public function test_mysql_rejects_ended_without_end_date(): void
    {
        $person = $this->person();
        $this->expectException(QueryException::class);
        Enrollment::query()->forceCreate(['lodge_id' => $person->lodge_id, 'person_id' => $person->id, 'starts_on' => '2026-01-02', 'status' => 'ended']);
    }

    public function test_mysql_unique_generated_column_allows_only_one_open_period(): void
    {
        $person = $this->person();
        Enrollment::query()->forceCreate(['lodge_id' => $person->lodge_id, 'person_id' => $person->id, 'starts_on' => '2026-01-01', 'status' => 'active']);
        $this->expectException(QueryException::class);
        Enrollment::query()->forceCreate(['lodge_id' => $person->lodge_id, 'person_id' => $person->id, 'starts_on' => '2026-02-01', 'status' => 'active']);
    }
}
