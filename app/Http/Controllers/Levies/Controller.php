<?php

namespace App\Http\Controllers\Levies;

use App\Domains\Capitations\Money;
use App\Domains\Identity\Authorization\TenantAccess;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Levies\Actions\Access;
use App\Domains\Levies\Actions\Balances;
use App\Domains\Levies\Actions\Generator;
use App\Domains\Levies\Actions\Payments;
use App\Domains\Levies\Actions\Rules;
use App\Domains\Levies\Models\Payment;
use App\Domains\Levies\Models\Program;
use App\Domains\Organization\Actions\ActiveLodge;
use App\Domains\Organization\Models\Obedience;
use App\Domains\Organization\Models\Structure;
use App\Domains\Treasury\Adapters\TreasuryUnavailable;
use App\Domains\Treasury\Contracts\DisbursementGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class Controller
{
    public function platform(Rules $rules): View
    {
        $rules->platform();

        // No local conditions, obligations or counts are loaded by global administration.
        return view('levies.platform', ['programs' => Program::query()->with(['conditions' => fn ($q) => $q->whereNull('lodge_id')->orderByDesc('effective_on')])->orderByDesc('id')->paginate(10), 'obediences' => Obedience::query()->where('is_active', true)->orderBy('name')->get(), 'structures' => Structure::query()->orderBy('name')->get()]);
    }

    public function create(Request $request, Rules $rules): RedirectResponse
    {
        $request->validate(['confirmation' => ['accepted']]);
        $rules->create($request->all());

        return back()->with('status', 'Programma di canone creato.');
    }

    public function general(Request $request, Rules $rules, int $program): RedirectResponse
    {
        $request->validate(['confirmation' => ['accepted']]);
        $rules->general($program, $request->all());

        return back()->with('status', 'Nuova condizione generale registrata.');
    }

    public function index(Request $request, Access $access, string $mode = 'dashboard'): View
    {
        $lodge = app(ActiveLodge::class)->current();
        abort_unless($lodge !== null, 403);
        $access->guard($lodge->id, $mode === 'scadenze' ? 'reports' : 'read');
        $v = $request->validate(['from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2101-12-31'], 'state' => ['nullable', 'in:open,paid,all']]);
        $from = $v['from'] ?? '2000-01-01';
        $to = $v['to'] ?? '2101-12-31';
        $state = $v['state'] ?? 'all';
        $query = $access->obligations($lodge->id)->whereBetween('due_on', [$from, $to]);
        if ($state === 'open') {
            $query->whereRaw('amount > COALESCE((SELECT SUM(p.amount) FROM levy_payments p WHERE p.obligation_id=levy_obligations.id AND p.lodge_id=levy_obligations.lodge_id),0)');
        }
        if ($state === 'paid') {
            $query->whereRaw('amount = COALESCE((SELECT SUM(p.amount) FROM levy_payments p WHERE p.obligation_id=levy_obligations.id AND p.lodge_id=levy_obligations.lodge_id),0)');
        }
        $sums = DB::query()->fromSub((clone $query)->toBase(), 'obligations')->selectRaw('COALESCE(SUM(amount),0) as due, COALESCE(SUM(payments_sum_amount),0) as paid')->first();
        $due = Money::aggregate((string) $sums->due);
        $paid = Money::aggregate((string) $sums->paid);

        return view('levies.index', compact('lodge', 'mode', 'from', 'to', 'state', 'due', 'paid') + ['obligations' => $query->orderBy('due_on')->orderBy('id')->paginate(15)->withQueryString()]);
    }

    public function conditions(Access $access): View
    {
        $lodge = app(ActiveLodge::class)->current();
        abort_unless($lodge !== null, 403);
        $access->guard($lodge->id);

        return view('levies.conditions', ['lodge' => $lodge, 'programs' => Program::query()->where('obedience_id', $lodge->obedience_id)->with(['conditions' => fn ($q) => $q->where(fn ($q) => $q->whereNull('lodge_id')->orWhere('lodge_id', $lodge->id))->orderByDesc('effective_on')])->orderBy('code')->paginate(10)]);
    }

    public function local(Request $request, Rules $rules, int $lodge, int $program): RedirectResponse
    {
        $request->validate(['confirmation' => ['accepted']]);
        $rules->local($lodge, $program, $request->all());

        return back()->with('status', 'Deroga locale registrata per i cicli futuri.');
    }

    public function generate(Request $request, Access $access, Generator $generator, int $lodge): RedirectResponse
    {
        $access->guard($lodge, 'create');
        $v = $request->validate(['confirmation' => ['accepted'], 'through' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $count = $generator->run($lodge, $v['through'], VerifiedSession::user()->id);

        return back()->with('status', $count.' obblighi generati. Nessuna uscita finanziaria.');
    }

    public function detail(Access $access, DisbursementGateway $gateway, int $lodge, int $obligation): View
    {
        $record = $access->obligations($lodge)->whereKey($obligation)->firstOrFail();
        $canPay = app(TenantAccess::class)->allows(VerifiedSession::user(), $lodge, 'create');

        return view('levies.detail', ['lodge' => $access->guard($lodge), 'obligation' => $record, 'balance' => Balances::of($record), 'payments' => Payment::query()->where('lodge_id', $lodge)->where('obligation_id', $record->id)->orderByDesc('paid_on')->paginate(15), 'available' => $gateway->available(), 'accounts' => $canPay ? $gateway->accounts($lodge) : [], 'canPay' => $canPay]);
    }

    public function history(Request $request, Access $access): View
    {
        $lodge = app(ActiveLodge::class)->current();
        abort_unless($lodge !== null, 403);
        $access->guard($lodge->id, 'reports');
        $v = $request->validate(['from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2101-12-31']]);
        $from = $v['from'] ?? '2000-01-01';
        $to = $v['to'] ?? now()->toDateString();
        $q = Payment::query()->where('lodge_id', $lodge->id)->whereBetween('paid_on', [$from, $to]);
        $total = Money::aggregate((string) (clone $q)->sum('amount'));

        return view('levies.history', compact('lodge', 'from', 'to', 'total') + ['payments' => $q->with('obligation')->orderByDesc('paid_on')->orderByDesc('id')->paginate(20)->withQueryString()]);
    }

    public function pay(Request $request, Payments $payments, int $lodge, int $obligation): RedirectResponse
    {
        $v = $request->validate(['confirmation' => ['accepted'], 'amount' => ['required', 'string'], 'paid_on' => ['required', 'string'], 'account' => ['required', 'string'], 'key' => ['required', 'string']]);
        try {
            $payments->pay($lodge, $obligation, $v['amount'], $v['paid_on'], $v['account'], $v['key']);
        } catch (TreasuryUnavailable $e) {
            return back()->withErrors(['treasury' => $e->getMessage()]);
        }

        return back()->with('status', 'Pagamento registrato insieme alla relativa uscita finanziaria.');
    }
}
