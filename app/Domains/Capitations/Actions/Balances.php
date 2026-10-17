<?php

namespace App\Domains\Capitations\Actions;

use App\Domains\Capitations\Models\Installment;
use App\Domains\Capitations\Models\Receipt;
use App\Domains\Capitations\Money;

final class Balances
{
    /** @return array{original:int,correction:int,due:int,paid:int,residual:int,status:string} */
    public static function installment(Installment $i): array
    {
        $original = Money::cents($i->original_amount);
        $correction = Money::cents((string) ($i->getAttribute('corrections_sum_amount') ?? $i->corrections()->sum('amount')), true);
        $paid = Money::cents((string) ($i->getAttribute('allocations_sum_amount') ?? $i->allocations()->sum('amount')), true);
        $due = $original + $correction;
        $residual = $due - $paid;
        if ($due < 0 || $paid < 0 || $residual < 0) {
            throw new \LogicException('Invariante economico violato.');
        }
        $overdue = $i->due_on->toDateString() < now()->toDateString();
        $status = $due === 0 ? 'zero' : ($residual === 0 ? 'paid' : ($overdue ? ($paid > 0 ? 'overdue_partial' : 'overdue') : ($paid > 0 ? 'partial' : 'due')));

        if ($i->period_start->toDateString() > now()->toDateString()) {
            $status = 'future';
        }

        return compact('original', 'correction', 'due', 'paid', 'residual', 'status');
    }

    public static function credit(Receipt $receipt): int
    {
        if ($receipt->isVoided()) {
            return 0;
        }
        $credit = Money::cents($receipt->amount) - Money::cents((string) $receipt->allocations()->sum('amount'), true);
        if ($credit < 0) {
            throw new \LogicException('Credito negativo.');
        }

        return $credit;
    }
}
