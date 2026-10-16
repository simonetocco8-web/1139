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
    'permissions' => ['read', 'create', 'update', 'reverse', 'export', 'administration', 'reports', 'members.manage', 'financial.read'],
    'roles' => [
        'administrator' => ['read', 'create', 'update', 'reverse', 'export', 'administration', 'reports', 'members.manage', 'financial.read'],
        'treasurer' => ['read', 'create', 'update', 'reverse', 'export', 'reports', 'financial.read'],
        'brother' => ['read'],
        'secretary' => ['read', 'members.manage', 'reports', 'export'],
        'worshipful_master' => ['read', 'create', 'update', 'reverse', 'export', 'administration', 'reports', 'members.manage', 'financial.read'],
    ],
];
