<?php

namespace App\Domains\Reports;

use App\Domains\Capitations\Models\Installment;
use App\Domains\Capitations\Money;
use App\Domains\Levies\Models\Obligation;
use App\Domains\Treasury\Actions\Balances;
use App\Domains\Treasury\Models\Account;
use App\Domains\Treasury\Models\Line;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class Sources
{
    // Callers authorize the selected tenant before using these internal source queries.
    public function rates(int $lodgeId, ?int $personId = null): Builder
    {
        $q = Installment::query()->where('lodge_id', $lodgeId);
        if ($personId !== null) {
            $q->where('person_id', $personId);
        }
        $q->withSum('corrections', 'amount')->withSum('allocations', 'amount');

        return DB::query()->fromSub($q->toBase(), 'r')->join('member_people as p', fn ($j) => $j->on('p.id', '=', 'r.person_id')->on('p.lodge_id', '=', 'r.lodge_id'))->select(['r.id', 'r.person_id', 'r.period_start', 'r.period_end', 'r.due_on', 'p.reference', 'p.first_name', 'p.last_name'])->selectRaw('(r.original_amount + COALESCE(r.corrections_sum_amount,0)) as due, COALESCE(r.allocations_sum_amount,0) as paid, (r.original_amount + COALESCE(r.corrections_sum_amount,0)-COALESCE(r.allocations_sum_amount,0)) as residual');
    }

    public function levies(int $lodgeId): Builder
    {
        $q = Obligation::query()->where('lodge_id', $lodgeId)->withSum('payments', 'amount');

        return DB::query()->fromSub($q->toBase(), 'r')->select(['r.id', 'r.period_start', 'r.period_end', 'r.due_on', 'r.recipient_id', 'r.recipient_name'])->selectRaw('r.amount as due, COALESCE(r.payments_sum_amount,0) as paid, r.amount-COALESCE(r.payments_sum_amount,0) as residual');
    }

    public function openRates(int $lodgeId, ?int $personId = null): Builder
    {
        return DB::query()->fromSub($this->rates($lodgeId, $personId), 'd')->where('residual', '>', 0);
    }

    public function openLevies(int $lodgeId): Builder
    {
        return DB::query()->fromSub($this->levies($lodgeId), 'd')->where('residual', '>', 0);
    }

    /** @return array{due:int,paid:int,residual:int} */
    public function debtTotals(Builder $q): array
    {
        $s = DB::query()->fromSub($q, 'd')->selectRaw('COALESCE(SUM(due),0) as due, COALESCE(SUM(paid),0) as paid, COALESCE(SUM(residual),0) as residual')->first();
        $due = Money::aggregate((string) $s->due, true);
        $paid = Money::aggregate((string) $s->paid, true);
        $residual = Money::aggregate((string) $s->residual, true);
        if ($due < 0 || $paid < 0 || $residual < 0 || $due - $paid !== $residual) {
            throw new \LogicException('Dati economici incoerenti.');
        }

        return compact('due', 'paid', 'residual');
    }

    /** @return array{opening:int,closing:int,bank:int,cash:int,income:int,expense:int,equity:int,change:int} */
    public function finance(int $lodgeId, Period $p): array
    {
        $q = Line::query()->join('treasury_operations as o', fn ($j) => $j->on('o.id', '=', 'treasury_lines.operation_id')->on('o.lodge_id', '=', 'treasury_lines.lodge_id'))->where('treasury_lines.lodge_id', $lodgeId);
        $opening = Money::aggregate((string) (clone $q)->where('bucket', 'financial')->where('o.booked_on', '<', $p->from)->sum('amount'), true);
        $totals = (clone $q)->whereBetween('o.booked_on', [$p->from, $p->to])->selectRaw('bucket, SUM(amount) as total')->groupBy('bucket')->pluck('total', 'bucket');
        $income = -Money::aggregate((string) ($totals['income'] ?? 0), true);
        $expense = Money::aggregate((string) ($totals['expense'] ?? 0), true);
        $equity = -Money::aggregate((string) ($totals['equity'] ?? 0), true);
        $change = Money::aggregate((string) ($totals['financial'] ?? 0), true);
        $types = (clone $q)->join('treasury_accounts as a', fn ($j) => $j->on('a.id', '=', 'treasury_lines.account_id')->on('a.lodge_id', '=', 'treasury_lines.lodge_id'))->where('bucket', 'financial')->where('o.booked_on', '<=', $p->to)->selectRaw('a.type, SUM(amount) as total')->groupBy('a.type')->pluck('total', 'type');
        $bank = Money::aggregate((string) ($types['bank'] ?? 0), true);
        $cash = Money::aggregate((string) ($types['cash'] ?? 0), true);
        $closing = Money::add($bank, $cash);
        if (Money::add($opening, $change) !== $closing || Money::add($income, -$expense, $equity) !== $change) {
            throw new \LogicException('Rendiconto finanziario non riconciliato.');
        }

        return compact('opening', 'closing', 'bank', 'cash', 'income', 'expense', 'equity', 'change');
    }

    /** @return list<array{account:string,type:string,opening:int,change:int,closing:int}> */
    public function accounts(int $lodgeId, Period $period): array
    {
        $rows = [];
        foreach (Account::query()->where('lodge_id', $lodgeId)->where('opened_on', '<=', $period->to)->orderBy('type')->orderBy('name')->get() as $a) {
            $opening = Balances::account($lodgeId, $a->id, $period->before());
            $closing = Balances::account($lodgeId, $a->id, $period->to);
            $rows[] = ['account' => $a->name, 'type' => $a->type === 'bank' ? 'Banca' : 'Cassa', 'opening' => $opening, 'change' => Money::add($closing, -$opening), 'closing' => $closing];
        }

        return $rows;
    }

    /** @return list<array<string,int|string>> */
    public function categories(int $lodgeId, Period $period): array
    {
        $q = Line::query()->join('treasury_operations as o', fn ($j) => $j->on('o.id', '=', 'treasury_lines.operation_id')->on('o.lodge_id', '=', 'treasury_lines.lodge_id'))->leftJoin('treasury_categories as c', fn ($j) => $j->on('c.id', '=', 'o.category_id')->on('c.lodge_id', '=', 'o.lodge_id'))->where('treasury_lines.lodge_id', $lodgeId)->whereBetween('o.booked_on', [$period->from, $period->to])->whereIn('bucket', ['income', 'expense'])->selectRaw('o.category_id,c.name,bucket,SUM(amount) as total')->groupBy('o.category_id', 'c.name', 'bucket')->orderBy('c.name')->get();
        $rows = [];
        foreach ($q as $r) {
            $amount = Money::aggregate((string) $r->getAttribute('total'), true);
            $rows[] = ['category' => $r->name ?? ($r->bucket === 'income' ? 'Capitazioni / entrate automatiche' : 'Canoni / uscite automatiche'), 'type' => $r->bucket === 'income' ? 'Entrata' : 'Uscita', 'income' => $r->bucket === 'income' ? -$amount : 0, 'expense' => $r->bucket === 'expense' ? $amount : 0, 'net' => -$amount];
        }

        return $rows;
    }
}
