<?php

namespace App\Http\Controllers\Capitations;

use App\Domains\Capitations\Actions\Access;
use App\Domains\Capitations\Actions\AdvanceGeneration;
use App\Domains\Capitations\Actions\Balances;
use App\Domains\Capitations\Actions\Generator;
use App\Domains\Capitations\Actions\Payments;
use App\Domains\Capitations\Actions\Plans;
use App\Domains\Capitations\Models\Installment;
use App\Domains\Capitations\Models\PersonRule;
use App\Domains\Capitations\Models\Plan;
use App\Domains\Capitations\Models\Receipt;
use App\Domains\Capitations\Money;
use App\Domains\Identity\Authorization\TenantAccess;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Treasury\Adapters\TreasuryUnavailable;
use App\Domains\Treasury\Contracts\ReceiptGateway;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class Controller
{
    public function __construct(private Access $access) {}

    public function index(): View
    {
        $lodge = app(TenantContext::class)->resolve();
        $this->access->guard($lodge->id);

        return view('capitations.plans', ['lodge' => $lodge, 'plans' => Plan::query()->where('lodge_id', $lodge->id)->with('versions')->orderBy('id')->get()]);
    }

    public function advancePreview(Request $request, int $lodge, int $plan, AdvanceGeneration $advance): View
    {
        $v = $request->validate(['cycle' => ['required', 'date_format:Y-m-d']]);
        $tenant = $this->access->guard($lodge, 'create');

        return view('capitations.advance', ['lodge' => $tenant, 'preview' => $advance->preview($lodge, $plan, $v['cycle'])]);
    }

    public function advanceConfirm(Request $request, int $lodge, AdvanceGeneration $advance): RedirectResponse
    {
        $this->confirm($request);
        $v = $request->validate(['token' => ['required', 'string']]);
        $count = $advance->confirm($lodge, $v['token']);

        return redirect()->route('capitations.report', 'future')->with('status', $count.' rate anticipate create. Nessun incasso o movimento finanziario registrato.');
    }

    public function plan(Request $request, int $lodge, Plans $plans): RedirectResponse
    {
        $this->confirm($request);
        $plans->create($lodge, $request->all());

        return redirect()->route('capitations.index')->with('status', 'Piano inserito.');
    }

    public function revise(Request $request, int $lodge, int $plan, Plans $plans): RedirectResponse
    {
        $this->confirm($request);
        $plans->revise($lodge, $plan, $request->all());

        return back()->with('status', 'Versione futura inserita. Rate maturate conservate e rate anticipate aggiornate con rettifiche tracciate.');
    }

    public function generate(Request $request, int $lodge, Generator $generator): RedirectResponse
    {
        $this->access->guard($lodge, 'create');
        $this->confirm($request);
        $n = DB::transaction(function () use ($lodge, $generator): int {
            Lodge::query()->whereKey($lodge)->lockForUpdate()->firstOrFail();
            $this->access->guard($lodge, 'create');

            return $generator->run($lodge, now()->toDateString(), VerifiedSession::user()->id);
        }, 3);

        return back()->with('status', $n.' rate create. Nessun incasso registrato.');
    }

    public function report(Request $request, string $mode): View
    {
        abort_unless(in_array($mode, ['scadenze', 'morosi', 'riepilogo', 'future'], true), 404);
        $lodge = app(TenantContext::class)->resolve('reports');
        $this->access->guard($lodge->id, 'reports');
        $v = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:2101-12-31']]);
        $from = $v['from'] ?? ($mode === 'riepilogo' ? now()->startOfYear()->toDateString() : '2000-01-01');
        $to = $v['to'] ?? ($mode === 'morosi' ? now()->toDateString() : (in_array($mode, ['scadenze', 'future'], true) ? '2101-12-31' : now()->endOfYear()->toDateString()));
        abort_if($from > $to, 422);
        $q = $this->access->installments($lodge->id)->with('person');
        if ($mode === 'future') {
            $q->where('period_start', '>', now()->toDateString());
        } else {
            $q->where('period_start', '<=', now()->toDateString());
        }
        $column = $mode === 'riepilogo' ? 'period_start' : 'due_on';
        $q->whereBetween($column, [$from, $to]);
        if ($mode === 'morosi') {
            $q->where('due_on', '<', now()->toDateString())->whereRaw('(original_amount + COALESCE((select SUM(c.amount) from capitation_corrections c where c.installment_id=capitation_installments.id),0) - COALESCE((select SUM(a.amount) from capitation_allocations a where a.installment_id=capitation_installments.id),0)) > 0');
        }
        $debtors = null;
        if ($mode === 'morosi') {
            $debtors = DB::query()->fromSub((clone $q)->toBase(), 'arrears')
                ->join('member_people as p', fn ($j) => $j->on('p.id', '=', 'arrears.person_id')->on('p.lodge_id', '=', 'arrears.lodge_id'))
                ->select(['p.id', 'p.first_name', 'p.last_name', 'p.reference'])
                ->selectRaw('COUNT(*) as rate_count, MIN(due_on) as first_due, SUM(original_amount + COALESCE(corrections_sum_amount,0) - COALESCE(allocations_sum_amount,0)) as residual')
                ->groupBy('p.id', 'p.first_name', 'p.last_name', 'p.reference')->orderBy('p.last_name')->paginate(20, ['*'], 'debtor_page')->withQueryString();
        }
        $totals = $this->totals(clone $q);

        return view('capitations.report', ['lodge' => $lodge, 'mode' => $mode, 'from' => $from, 'to' => $to, 'totals' => $totals, 'debtors' => $debtors, 'installments' => $q->orderBy('due_on')->orderBy('id')->paginate(20)->withQueryString()]);
    }

    public function person(int $lodge, int $person, ReceiptGateway $gateway): View
    {
        return $this->statement($lodge, $person, true, $gateway);
    }

    public function personal(ReceiptGateway $gateway): View
    {
        $lodge = app(TenantContext::class)->resolve();
        $person = Person::query()->where('lodge_id', $lodge->id)->where('user_id', VerifiedSession::user()->id)->first();
        if ($person === null) {
            return view('capitations.no-person', ['lodge' => $lodge]);
        }

        return $this->statement($lodge->id, $person->id, false, $gateway);
    }

    private function statement(int $lodgeId, int $id, bool $staff, ReceiptGateway $gateway): View
    {
        $lodge = $this->access->guard($lodgeId, 'read', $staff);
        $person = $this->access->person($lodgeId, $id, $staff);
        $query = $this->access->installments($lodgeId, $id, $staff);
        $receipts = Receipt::query()->where('lodge_id', $lodgeId)->where('person_id', $id)->with('allocations.installment')->orderByDesc('id')->get();
        $credit = 0;
        foreach ($receipts as $receipt) {
            $credit = Money::add($credit, Balances::credit($receipt));
        }

        $canReceive = $staff && app(TenantAccess::class)->allows(VerifiedSession::user(), $lodgeId, 'create');

        return view('capitations.person', ['lodge' => $lodge, 'person' => $person, 'staff' => $staff, 'totals' => $this->totals((clone $query)->where('period_start', '<=', now()->toDateString())), 'credit' => $credit, 'receipts' => $receipts, 'installments' => $query->with('corrections')->orderBy('due_on')->orderBy('id')->paginate(20),
            'plans' => $staff ? Plan::query()->where('lodge_id', $lodgeId)->get() : collect(), 'rules' => $staff ? PersonRule::query()->where('lodge_id', $lodgeId)->where('person_id', $id)->orderByDesc('id')->get() : collect(),
            'accounts' => $canReceive ? $gateway->accounts($lodgeId) : [], 'treasuryAvailable' => $canReceive && $gateway->available()]);
    }

    /** @param Builder<Installment> $query
     * @return array<string,int> */
    private function totals(Builder $query): array
    {
        $row = DB::query()->fromSub($query->toBase(), 'dues')->selectRaw('COALESCE(SUM(original_amount),0) as original, COALESCE(SUM(corrections_sum_amount),0) as correction, COALESCE(SUM(allocations_sum_amount),0) as paid')->first();
        $original = Money::aggregate((string) $row->original);
        $correction = Money::aggregate((string) $row->correction, true);
        $paid = Money::aggregate((string) $row->paid, true);

        return ['original' => $original, 'correction' => $correction, 'due' => Money::add($original, $correction), 'paid' => $paid, 'residual' => Money::add($original, $correction, -$paid)];
    }

    public function rule(Request $r, int $lodge, int $person, Plans $plans): RedirectResponse
    {
        $this->confirm($r);
        $r->validate(['plan_id' => ['required', 'integer']]);
        $plans->rule($lodge, $r->integer('plan_id'), $person, $r->all());

        return back()->with('status', 'Condizione individuale inserita. Le rate anticipate interessate sono state ricalcolate in modo tracciato.');
    }

    public function correction(Request $r, int $lodge, int $installment, Payments $payments): RedirectResponse
    {
        $this->confirm($r);
        $r->validate(['amount' => ['required', 'string'], 'reason' => ['required', 'string'], 'key' => ['required', 'string']]);
        $payments->correct($lodge, $installment, $r->string('amount')->toString(), $r->string('reason')->toString(), $r->string('key')->toString());

        return back()->with('status', 'Rettifica registrata.');
    }

    public function receive(Request $r, int $lodge, int $person, Payments $payments): RedirectResponse
    {
        $this->confirm($r);
        $r->validate(['amount' => ['required', 'string'], 'received_on' => ['required', 'date_format:Y-m-d'], 'account' => ['required', 'string'], 'key' => ['required', 'string'], 'allocations' => ['nullable', 'array'], 'allocations.*' => ['nullable', 'string']]);
        try {
            $payments->receive($lodge, $person, $r->string('amount')->toString(), $r->string('received_on')->toString(), $r->string('account')->toString(), $r->string('key')->toString(), $this->allocations($r));
        } catch (TreasuryUnavailable $e) {
            return back()->withErrors(['treasury' => $e->getMessage()]);
        }

        return back()->with('status', 'Incasso confermato dal servizio Tesoreria e imputazioni registrate.');
    }

    public function allocate(Request $r, int $lodge, int $person, int $receipt, Payments $payments): RedirectResponse
    {
        $this->confirm($r);
        $r->validate(['key' => ['required', 'string'], 'allocations' => ['required', 'array'], 'allocations.*' => ['nullable', 'string']]);
        $payments->allocate($lodge, $person, $receipt, $r->string('key')->toString(), $this->allocations($r));

        return back()->with('status', 'Credito imputato. Nessun nuovo movimento finanziario.');
    }

    public function reverse(Request $r, int $lodge, int $allocation, Payments $payments): RedirectResponse
    {
        $this->confirm($r);
        $r->validate(['reason' => ['required', 'string']]);
        $payments->reverse($lodge, $allocation, $r->string('reason')->toString());

        return back()->with('status', 'Imputazione annullata e credito liberato. Incasso originale conservato.');
    }

    /** @return array<int,string> */
    private function allocations(Request $r): array
    {
        $rows = $r->input('allocations', []);
        abort_unless(is_array($rows), 422);
        $out = [];
        foreach ($rows as $id => $amount) {
            if ($amount !== null) {
                abort_unless(is_int($id) && is_string($amount), 422);
                $out[$id] = $amount;
            }
        }

        return $out;
    }

    private function confirm(Request $r): void
    {
        $r->validate(['confirmation' => ['required', 'accepted']]);
    }
}
