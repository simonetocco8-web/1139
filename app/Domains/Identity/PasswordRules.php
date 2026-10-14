<?php

namespace App\Domains\Identity;

use Illuminate\Validation\Rules\Password;

final class PasswordRules
{
    /** @return array<int,mixed> */
    public static function strong(): array
    {
        return ['required', 'string', 'max:72', Password::min(12)->mixedCase()->numbers()->symbols(), 'confirmed',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                    $fail('La password è troppo lunga o contiene caratteri non validi.');
                }
            }];
    }
}
