<?php

return [
    'otp_secret' => env('OTP_SECRET'),
    'delivery_driver' => env('IDENTITY_DELIVERY_DRIVER', 'configured'),
    'dummy_password_hash' => '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.',
    'sms' => [
        'driver' => env('SMS_DRIVER', 'disabled'),
        'endpoint' => env('SMS_ENDPOINT'),
        'token' => env('SMS_TOKEN'),
        'sender' => env('SMS_SENDER'),
    ],
    'permissions' => ['read', 'create', 'update', 'reverse', 'export', 'administration', 'reports'],
    'roles' => [
        'administrator' => ['read', 'create', 'update', 'reverse', 'export', 'administration', 'reports'],
        'treasurer' => ['read', 'create', 'update', 'reverse', 'export', 'reports'],
        'brother' => ['read'],
    ],
];
