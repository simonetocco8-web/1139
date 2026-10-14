<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fail closed until the invitation and email/SMS OTP flow is implemented.
        Fortify::ignoreRoutes();
    }
}
