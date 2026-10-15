<?php

namespace App\Domains\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

final readonly class Period
{
    public function __construct(public string $from, public string $to) {}

    /** @param array<string,mixed> $input */
    public static function make(array $input, bool $annual = false): self
    {
        if ($annual) {
            $v = Validator::make($input, ['year' => ['nullable', 'integer', 'between:2000,'.now()->year]])->validate();
            $year = (int) ($v['year'] ?? now()->year);

            return new self($year.'-01-01', min($year.'-12-31', now()->toDateString()));
        }
        $values = ['from' => $input['from'] ?? now()->startOfMonth()->toDateString(), 'to' => $input['to'] ?? now()->toDateString()];
        foreach ($values as $key => $value) {
            if (is_string($value) && preg_match('~^(\d{2})/(\d{2})/(\d{4})$~D', $value, $parts)) {
                $values[$key] = $parts[3].'-'.$parts[2].'-'.$parts[1];
            }
        }
        $v = Validator::make($values, ['from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:today'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today']])->validate();

        return new self($v['from'], $v['to']);
    }

    public function before(): string
    {
        return CarbonImmutable::parse($this->from, 'Europe/Rome')->subDay()->toDateString();
    }

    public static function italian(string $date): string
    {
        return CarbonImmutable::parse($date, 'Europe/Rome')->format('d/m/Y');
    }
}
