<?php

namespace App\Domains\Capitations;

use Illuminate\Validation\ValidationException;

final class Money
{
    public static function cents(string $value, bool $signed = false): int
    {
        $value = str_replace(',', '.', trim($value));
        if (! preg_match('/^(-?)(\d{1,13})(?:\.(\d{1,2}))?$/D', $value, $m) || (! $signed && $m[1] === '-')) {
            throw ValidationException::withMessages(['amount' => 'Importo non valido: massimo due decimali, senza separatori delle migliaia.']);
        }
        $n = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$n : $n;
    }

    public static function aggregate(string $value, bool $signed = false): int
    {
        if (! preg_match('/^(-?)(\d{1,16})(?:\.(\d{1,2}))?$/D', trim($value), $m) || (! $signed && $m[1] === '-')) {
            throw new \LogicException('Totale economico non rappresentabile.');
        }
        $digits = ltrim($m[2].str_pad($m[3] ?? '', 2, '0'), '0');
        $limit = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new \LogicException('Totale oltre il limite intero.');
        }

        return $m[1] === '-' ? -(int) $digits : (int) $digits;
    }

    public static function add(int ...$values): int
    {
        $total = 0;
        foreach ($values as $value) {
            if (($value > 0 && $total > PHP_INT_MAX - $value) || ($value < 0 && $total < -PHP_INT_MAX - $value)) {
                throw new \LogicException('Totale oltre il limite intero.');
            }
            $total += $value;
        }

        return $total;
    }

    public static function decimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function display(int $cents): string
    {
        return str_replace('.', ',', self::decimal($cents)).' €';
    }

    public static function ratio(int $cents, int $numerator, int $denominator): int
    {
        if ($cents < 0 || $numerator < 0 || $denominator < 1 || $numerator > $denominator) {
            throw new \LogicException('Rapporto economico non valido.');
        }

        return intdiv($cents, $denominator) * $numerator + intdiv(($cents % $denominator) * $numerator + intdiv($denominator, 2), $denominator);
    }
}
