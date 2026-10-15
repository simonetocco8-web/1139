<?php

namespace App\Domains\Levies\Actions;

use App\Domains\Capitations\Money;
use App\Domains\Levies\Models\Obligation;

final class Balances
{
    /** @return array{due:int,paid:int,residual:int,status:string} */
    public static function of(Obligation $obligation): array
    {
        $due = Money::cents($obligation->amount);
        $paid = Money::aggregate((string) ($obligation->payments_sum_amount ?? $obligation->payments()->sum('amount')));
        $residual = $due - $paid;
        if ($residual < 0) {
            throw new \LogicException('Pagamenti superiori al dovuto.');
        }
        $status = $due === 0 ? 'zero' : ($residual === 0 ? 'paid' : ($obligation->due_on->lessThan(now()->startOfDay()) ? ($paid > 0 ? 'overdue_partial' : 'overdue') : ($paid > 0 ? 'partial' : 'due')));

        return compact('due', 'paid', 'residual', 'status');
    }
}
