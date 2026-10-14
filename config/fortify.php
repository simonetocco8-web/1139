<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web', 'passwords' => 'users', 'username' => 'email', 'email' => 'email',
    'lowercase_usernames' => true, 'home' => '/dashboard', 'prefix' => '', 'domain' => null,
    'middleware' => ['web'], 'views' => true,
    'features' => [Features::resetPasswords()],
    'limiters' => ['login' => 'identity-login'],
];
