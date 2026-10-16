<?php

namespace App\Domains\Reports;

use App\Domains\Capitations\Models\Receipt;
use App\Domains\Capitations\Money;
use App\Domains\Identity\Authorization\TenantAccess;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Treasury\Models\Line;
use App\Domains\Treasury\Models\Operation;
use Illuminate\Support\Facades\DB;

final class Dashboards
{
    public function __construct(private Sources $sources) {}

    /** @return array<string,mixed> */
    public function staff(int $lodgeId, Period $period): array
    {
        $lodge = app(TenantContext::class)->assertTenant($lodgeId, 'read');
        $user = VerifiedSession::user();
        $access = app(TenantAccess::class);
        abort_unless($access->fullRead($user, $lodgeId), 403);
        $canFinance = $access->allows($user, $lodgeId, 'financial.read');
        $canReports = $access->allows($user, $lodgeId, 'reports');

        return DB::transaction(function () use ($lodgeId, $period, $lodge, $canReports, $canFinance, $access, $user): array {
            $accounts = $this->sources->accounts($lodgeId, $period);
            $bank = 0;
            $cash = 0;
            foreach ($accounts as $a) {
                if ($a['type'] === 'Banca') {
                    $bank = Money::add($bank, $a['closing']);
                } else {
                    $cash = Money::add($cash, $a['closing']);
                }
            }
            $rates = $this->sources->debtTotals($this->sources->rates($lodgeId));
            $levies = $this->sources->debtTotals($this->sources->levies($lodgeId));
            $flow = $canReports ? $this->sources->finance($lodgeId, $period) : null;
            $nextRates = $this->sources->openRates($lodgeId)->whereBetween('due_on', [now()->toDateString(), now()->addDays(30)->toDateString()])->orderBy('due_on')->limit(6)->get()->map(fn ($r) => ['label' => 'Capitazione · '.$r->last_name.' '.$r->first_name, 'due_on' => substr($r->due_on, 0, 10), 'amount' => Money::aggregate((string) $r->residual), 'url' => $canFinance ? route('capitations.person', [$lodgeId, $r->person_id]) : route('reports.show', [$lodgeId, 'capitations'])])->all();
            $nextLevies = $this->sources->openLevies($lodgeId)->whereBetween('due_on', [now()->toDateString(), now()->addDays(30)->toDateString()])->orderBy('due_on')->limit(6)->get()->map(fn ($r) => ['label' => 'Canone · '.$r->recipient_name, 'due_on' => substr($r->due_on, 0, 10), 'amount' => Money::aggregate((string) $r->residual), 'url' => $canFinance ? route('levies.detail', [$lodgeId, $r->id]) : route('reports.show', [$lodgeId, 'levies'])])->all();
            $next = array_merge($nextRates, $nextLevies);
            usort($next, fn ($a, $b) => strcmp($a['due_on'], $b['due_on']));
            $movements = $canFinance ? Operation::query()->where('lodge_id', $lodgeId)->whereBetween('booked_on', [$period->from, $period->to])->with('lines.account')->orderByDesc('booked_on')->orderByDesc('id')->limit(6)->get() : collect();

            return compact('lodge', 'period', 'bank', 'cash', 'rates', 'levies', 'flow', 'next', 'movements', 'canReports', 'canFinance') + ['canCreate' => $access->allows($user, $lodgeId, 'create'), 'canAdmin' => $access->allows($user, $lodgeId, 'administration')];
        });
    }

    /** @return array<string,mixed> */
    public function brother(int $lodgeId, Period $period): array
    {
        $lodge = app(TenantContext::class)->assertTenant($lodgeId, 'read');
        $user = VerifiedSession::user();
        $person = Person::query()->where('lodge_id', $lodgeId)->where('user_id', $user->id)->first();

        return DB::transaction(function () use ($person, $lodge, $lodgeId, $period): array {
            $zero = ['due' => 0, 'paid' => 0, 'residual' => 0];
            $totals = $zero;
            $arrears = 0;
            $next = [];
            $receipts = collect();
            $credit = 0;
            $received = 0;
            $refunded = 0;
            $netReceived = 0;
            $history = [];
            if ($person !== null) {
                $q = $this->sources->rates($lodgeId, $person->id)->whereBetween('due_on', [$period->from, $period->to]);
                $totals = $this->sources->debtTotals($q);
                $arrears = Money::aggregate((string) $this->sources->openRates($lodgeId, $person->id)->where('due_on', '<', now()->toDateString())->sum('residual'));
                $next = $this->sources->openRates($lodgeId, $person->id)->whereBetween('due_on', [now()->toDateString(), now()->addDays(30)->toDateString()])->orderBy('due_on')->limit(6)->get()->map(fn ($r) => ['label' => 'Rata #'.$r->id, 'due_on' => substr($r->due_on, 0, 10), 'amount' => Money::aggregate((string) $r->residual), 'url' => route('capitations.personal')])->all();
                $receipts = Receipt::query()->where('lodge_id', $lodgeId)->where('person_id', $person->id)->whereBetween('received_on', [$period->from, $period->to])->orderByDesc('received_on')->orderByDesc('id')->limit(6)->get();
                $received = Money::aggregate((string) Receipt::query()->where('lodge_id', $lodgeId)->where('person_id', $person->id)->whereBetween('received_on', [$period->from, $period->to])->sum('amount'));
                $voidQuery = DB::table('capitation_receipt_voids as v')->join('capitation_receipts as r', fn ($j) => $j->on('r.id', '=', 'v.receipt_id')->on('r.lodge_id', '=', 'v.lodge_id'))->join('treasury_operations as o', fn ($j) => $j->on('o.id', '=', 'v.operation_id')->on('o.lodge_id', '=', 'v.lodge_id'))->where('v.lodge_id', $lodgeId)->where('r.person_id', $person->id)->whereBetween('o.booked_on', [$period->from, $period->to]);
                $refunded = Money::aggregate((string) (clone $voidQuery)->sum('r.amount'));
                $netReceived = Money::add($received, -$refunded);
                foreach ($receipts as $r) {
                    $history[] = ['date' => $r->received_on->toDateString(), 'label' => 'Incasso personale', 'amount' => Money::cents($r->amount), 'state' => $r->isVoided() ? 'Stornato · stato attuale' : 'Registrato'];
                }
                foreach ((clone $voidQuery)->orderByDesc('o.booked_on')->orderByDesc('o.id')->limit(6)->select(['o.booked_on', 'r.amount'])->get() as $v) {
                    $history[] = ['date' => substr($v->booked_on, 0, 10), 'label' => 'Storno incasso personale', 'amount' => -Money::cents((string) $v->amount), 'state' => 'Rimborso / annullamento'];
                }
                usort($history, fn ($a, $b) => strcmp($b['date'], $a['date']));
                $history = array_slice($history, 0, 6);
                $sum = DB::table('capitation_receipts as r')->where('r.lodge_id', $lodgeId)->where('r.person_id', $person->id)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('capitation_receipt_voids as v')->whereColumn('v.receipt_id', 'r.id')->whereColumn('v.lodge_id', 'r.lodge_id'))->selectRaw('COALESCE(SUM(r.amount-COALESCE((SELECT SUM(a.amount) FROM capitation_allocations a WHERE a.receipt_id=r.id AND a.lodge_id=r.lodge_id),0)),0) as credit')->first();
                $credit = Money::aggregate((string) $sum->credit);
            }
            $shared = Lodge::query()->whereKey($lodgeId)->where('brothers_treasury_visible', true)->exists();
            $sharedBank = null;
            $sharedCash = null;
            if ($shared) {
                $types = Line::query()->join('treasury_accounts as a', fn ($j) => $j->on('a.id', '=', 'treasury_lines.account_id')->on('a.lodge_id', '=', 'treasury_lines.lodge_id'))->where('treasury_lines.lodge_id', $lodgeId)->where('bucket', 'financial')->selectRaw('a.type,SUM(amount) as total')->groupBy('a.type')->pluck('total', 'type');
                $sharedBank = Money::aggregate((string) ($types['bank'] ?? 0), true);
                $sharedCash = Money::aggregate((string) ($types['cash'] ?? 0), true);
            }

            return compact('lodge', 'period', 'person', 'totals', 'arrears', 'next', 'receipts', 'credit', 'shared', 'sharedBank', 'sharedCash', 'received', 'refunded', 'netReceived', 'history');
        });
    }
}
