<?php

namespace App\Providers;

use App\Domains\Identity\Delivery\CodeTransport;
use App\Domains\Identity\Delivery\FakeTransport;
use App\Domains\Identity\Delivery\TechnicalDelivery;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Organization\Actions\OrganizationActions;
use App\Domains\Organization\Policies\OrganizationPolicy;
use App\Domains\Treasury\Adapters\UnavailableReceiptGateway;
use App\Domains\Treasury\Contracts\ReceiptGateway;
use App\Http\Middleware\RequireVerifiedSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ReceiptGateway::class, UnavailableReceiptGateway::class);
        $this->app->singleton(FakeTransport::class);
        $this->app->singleton(CodeTransport::class, TechnicalDelivery::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        URL::forceRootUrl((string) config('app.url'));
        if (app()->isProduction()) {
            URL::forceScheme('https');
        }
        if (app()->isProduction()) {
            config(['app.debug' => false, 'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax']);
        }
        Livewire::addPersistentMiddleware([RequireVerifiedSession::class]);
        Gate::define('manage-organization', fn (User $user) => VerifiedSession::user()->is_platform_admin);
        foreach (OrganizationActions::MODELS as $class) {
            Gate::policy($class, OrganizationPolicy::class);
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
            : null,
        );
    }
}
