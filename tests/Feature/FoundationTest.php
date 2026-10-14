<?php

namespace Tests\Feature;

use App\Livewire\FoundationStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_page_renders_without_accounts_or_private_data(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Accesso non ancora disponibile')
            ->assertSee('lang="it"', false)
            ->assertSeeLivewire(FoundationStatus::class)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_health_has_only_a_neutral_status_and_does_not_query_database(): void
    {
        DB::enableQueryLog();

        $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'ok']);

        $this->assertSame([], DB::getQueryLog());
    }

    /** @return array<string, array{string}> */
    public static function closedPaths(): array
    {
        return array_combine(
            $paths = ['/register', '/login', '/forgot-password', '/reset-password/example', '/two-factor-challenge', '/dashboard', '/settings/profile', '/settings/security', '/user/password', '/user/two-factor-authentication'],
            array_map(fn (string $path) => [$path], $paths),
        );
    }

    #[DataProvider('closedPaths')]
    public function test_authentication_and_private_routes_are_closed_even_for_an_existing_session(string $path): void
    {
        $this->get($path)->assertNotFound();
        $this->postJson($path, ['email' => 'nobody@example.test', 'password' => 'invalid'])->assertNotFound();

        $this->actingAs(User::factory()->create())->get($path)->assertNotFound();
    }

    public function test_no_fortify_route_or_registration_feature_is_available(): void
    {
        $this->assertFalse(Route::has('login'));
        $this->assertFalse(Route::has('register'));
        $this->assertSame([], config('fortify.features'));
        $this->assertFalse(config('platform.authentication_available'));
    }

    public function test_public_livewire_component_contains_only_basic_settings(): void
    {
        Livewire::test(FoundationStatus::class)
            ->assertSee('Europe/Rome')
            ->assertSee('EUR');
    }

    public function test_seed_does_not_create_demo_credentials(): void
    {
        $this->seed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_italian_and_shared_hosting_defaults(): void
    {
        $this->assertSame('it', config('app.locale'));
        $this->assertSame('Europe/Rome', config('app.timezone'));
        $this->assertSame('EUR', config('platform.currency'));
        $this->assertSame('local', config('filesystems.default'));
        $this->assertTrue(config('session.encrypt'));
    }
}
