<?php

namespace App\Domains\Treasury\Actions;

use App\Domains\Capitations\Money;
use App\Domains\Treasury\Models\Account;
use App\Domains\Treasury\Models\Line;
use Illuminate\Validation\ValidationException;

final class Balances
{
    public static function account(int $lodgeId, int $accountId, ?string $through = null): int
    {
        $q = Line::query()->where('treasury_lines.lodge_id', $lodgeId)->where('account_id', $accountId)->where('bucket', 'financial');
        if ($through !== null) {
            $q->whereHas('operation', fn ($q) => $q->where('booked_on', '<=', $through));
        }

        return Money::aggregate((string) $q->sum('amount'), true);
    }

    /** @param list<int> $accountIds */
    public static function checkCash(int $lodgeId, array $accountIds): void
    {
        foreach (Account::query()->where('lodge_id', $lodgeId)->where('type', 'cash')->whereIn('id', $accountIds)->get() as $account) {
            $balance = 0;
            foreach (Line::query()->join('treasury_operations as o', 'o.id', '=', 'treasury_lines.operation_id')->where('treasury_lines.lodge_id', $lodgeId)->where('treasury_lines.account_id', $account->id)->orderBy('o.booked_on')->orderBy('o.id')->select('treasury_lines.*')->get() as $line) {
                $balance = Money::add($balance, Money::cents($line->amount, true));
                if ($balance < 0) {
                    throw ValidationException::withMessages(['amount' => 'Operazione bloccata: produrrebbe una cassa negativa, anche nello storico.']);
                }
            }
        }
    }
}
