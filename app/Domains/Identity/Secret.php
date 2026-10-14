<?php

namespace App\Domains\Identity;

use RuntimeException;

final class Secret
{
    public static function digest(string $domain, string $value): string
    {
        $secret = config('identity.otp_secret');
        if ($secret !== null && $secret !== '' && (! is_string($secret) || strlen($secret) < 32)) {
            throw new RuntimeException('Il segreto OTP configurato non è valido.');
        }
        if ($secret === null || $secret === '') {
            $appKey = config('app.key');
            if (! is_string($appKey) || strlen($appKey) < 32) {
                throw new RuntimeException('Configurare la chiave applicativa.');
            }
            $secret = hash_hkdf('sha256', $appKey, 32, 'identity-security-v1');
        }

        return hash_hmac('sha256', $domain.'|'.$value, $secret);
    }
}
