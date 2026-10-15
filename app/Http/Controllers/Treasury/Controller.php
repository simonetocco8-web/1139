<?php

namespace App\Http\Controllers\Treasury;

use App\Domains\Capitations\Models\Receipt;
use App\Domains\Capitations\Money;
use App\Domains\Identity\Audit;
use App\Domains\Identity\Authorization\TenantAccess;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Levies\Models\Payment;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Actions\ActiveLodge;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Structure;
use App\Domains\Treasury\Actions\Access;
use App\Domains\Treasury\Actions\Attachments;
use App\Domains\Treasury\Actions\Balances;
use App\Domains\Treasury\Actions\Banking;
use App\Domains\Treasury\Actions\Ledger;
use App\Domains\Treasury\Actions\Reports;
use App\Domains\Treasury\Models\Account;
use App\Domains\Treasury\Models\Category;
use App\Domains\Treasury\Models\Line;
use App\Domains\Treasury\Models\Operation;
use App\Domains\Treasury\Models\Reconciliation;
use App\Domains\Treasury\Models\SourceLink;
use App\Domains\Treasury\Models\StatementEntry;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\HeaderUtils;

final class Controller
{
    private function lodge(string $permission = 'read'): Lodge
    {
        $l = app(ActiveLodge::class)->current();
        abort_unless($l !== null, 403);

        return app(Access::class)->guard($l->id, $permission);
    }

    public function summary(): View
    {
        $lodge = app(TenantContext::class)->resolve('read');
        $staff = app(TenantAccess::class)->fullRead(VerifiedSession::user(), $lodge->id);
        abort_unless($staff || Lodge::query()->whereKey($lodge->id)->where('brothers_treasury_visible', true)->exists(), 403);
        $totals = DB::table('treasury_lines as l')->join('treasury_accounts as a', 'a.id', '=', 'l.account_id')->where('l.lodge_id', $lodge->id)->where('a.lodge_id', $lodge->id)->where('l.bucket', 'financial')->selectRaw('a.type, SUM(l.amount) as total')->groupBy('a.type')->pluck('total', 'type');

        return view('treasury.summary', ['lodge' => $lodge, 'bank' => Money::aggregate((string) ($totals['bank'] ?? '0'), true), 'cash' => Money::aggregate((string) ($totals['cash'] ?? '0'), true)]);
    }

    public function index(Request $request, Reports $reports): View
    {
        $lodge = $this->lodge();
        $filters = $request->only(['from', 'to', 'kind', 'category_id', 'account_id']);

        return DB::transaction(function () use ($lodge, $filters, $reports): View {
            $accounts = Account::query()->where('lodge_id', $lodge->id)->orderBy('type')->orderBy('name')->get();
            $balances = [];
            foreach ($accounts as $a) {
                $balances[$a->id] = Balances::account($lodge->id, $a->id);
            }
            $query = $reports->query($lodge->id, $filters);
            $totals = Line::query()->where('lodge_id', $lodge->id)->whereIn('operation_id', (clone $query)->select('id'))->selectRaw('bucket,SUM(amount) as total')->groupBy('bucket')->pluck('total', 'bucket');

            return view('treasury.index', compact('lodge', 'accounts', 'balances', 'filters') + ['income' => -Money::aggregate((string) ($totals['income'] ?? '0'), true), 'expense' => Money::aggregate((string) ($totals['expense'] ?? '0'), true), 'operations' => $query->with(['lines.account', 'category', 'reversal'])->orderByDesc('booked_on')->orderByDesc('id')->paginate(20)->withQueryString(), 'categories' => Category::query()->where('lodge_id', $lodge->id)->orderBy('name')->get()]);
        });
    }

    public function accounts(Request $request): View
    {
        $lodge = $this->lodge();
        $v = $request->validate(['type' => ['nullable', 'in:bank,cash']]);
        $q = Account::query()->where('lodge_id', $lodge->id);
        if (! empty($v['type'])) {
            $q->where('type', $v['type']);
        }$accounts = $q->orderBy('name')->get();

        return view('treasury.accounts', compact('lodge', 'accounts'));
    }

    public function account(Request $request, Ledger $ledger, int $lodge): RedirectResponse
    {
        $request->validate(['confirmation' => ['accepted'], 'key' => ['required', 'string']]);
        $ledger->account($lodge, $request->all(), $request->string('key')->toString());

        return back()->with('status', 'Conto e saldo iniziale contabilizzati.');
    }

    public function category(Request $request, Ledger $ledger, int $lodge): RedirectResponse
    {
        $v = $request->validate(['confirmation' => ['accepted'], 'name' => ['required', 'string'], 'type' => ['required', 'string']]);
        $ledger->category($lodge, $v['name'], $v['type']);

        return back()->with('status', 'Categoria registrata.');
    }

    public function form(): View
    {
        $lodge = $this->lodge('create');

        return view('treasury.form', compact('lodge') + ['accounts' => Account::query()->where('lodge_id', $lodge->id)->orderBy('name')->get(), 'categories' => Category::query()->where('lodge_id', $lodge->id)->orderBy('type')->orderBy('name')->get(), 'people' => Person::query()->where('lodge_id', $lodge->id)->orderBy('last_name')->get(), 'structures' => Structure::query()->where('obedience_id', $lodge->obedience_id)->orderBy('name')->get()]);
    }

    public function post(Request $request, Ledger $ledger, int $lodge): RedirectResponse
    {
        $request->validate(['confirmation' => ['accepted'], 'key' => ['required', 'string']]);
        $op = $ledger->post($lodge, $request->all(), $request->string('key')->toString());

        return redirect()->route('treasury.detail', [$lodge, $op->id])->with('status', 'Operazione contabilizzata.');
    }

    public function detail(int $lodge, int $operation): View
    {
        $l = app(Access::class)->guard($lodge);
        $op = Operation::query()->where('lodge_id', $lodge)->whereKey($operation)->with(['lines.account', 'attachments', 'category', 'original', 'reversal', 'person', 'structure'])->firstOrFail();
        $link = SourceLink::query()->where('lodge_id', $lodge)->where('operation_id', $op->id)->first();
        $reconciliations = Reconciliation::query()->where('lodge_id', $lodge)->whereIn('line_id', $op->lines->pluck('id'))->orderBy('id')->get();

        $sourceUrl = null;
        if ($link?->receipt_id !== null) {
            $receipt = Receipt::query()->where('lodge_id', $lodge)->whereKey($link->receipt_id)->firstOrFail();
            $sourceUrl = route('capitations.person', [$lodge, $receipt->person_id]);
        }
        if ($link?->payment_id !== null) {
            $payment = Payment::query()->where('lodge_id', $lodge)->whereKey($link->payment_id)->firstOrFail();
            $sourceUrl = route('levies.detail', [$lodge, $payment->obligation_id]);
        }

        return view('treasury.detail', ['lodge' => $l, 'op' => $op, 'link' => $link, 'reconciliations' => $reconciliations, 'sourceUrl' => $sourceUrl]);
    }

    public function reverse(Request $request, Ledger $ledger, int $lodge, int $operation): RedirectResponse
    {
        $v = $request->validate(['confirmation' => ['accepted'], 'booked_on' => ['required', 'string'], 'reason' => ['required', 'string'], 'key' => ['required', 'string']]);
        $op = $ledger->reverse($lodge, $operation, $v['booked_on'], $v['reason'], $v['key']);

        return redirect()->route('treasury.detail', [$lodge, $op->id])->with('status', 'Storno contabilizzato e collegamenti economici aggiornati.');
    }

    public function upload(Request $request, Attachments $files, int $lodge, int $operation): RedirectResponse
    {
        $request->validate(['confirmation' => ['accepted'], 'file' => ['required', 'file']]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $files->upload($lodge, $operation, $file);

        return back()->with('status', 'Allegato privato salvato.');
    }

    public function download(Attachments $files, int $lodge, int $attachment): Response
    {
        $data = $files->download($lodge, $attachment);
        $name = str_replace(["\r", "\n", '"'], '_', $data['record']->name);

        return response($data['bytes'], 200, ['Content-Type' => $data['record']->mime, 'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $name, 'allegato'), 'X-Content-Type-Options' => 'nosniff']);
    }

    public function bank(Request $request, Banking $banking, int $lodge, int $account): View
    {
        $l = app(Access::class)->guard($lodge, 'update');
        $a = $banking->bank($lodge, $account);
        $v = $request->validate(['state' => ['nullable', 'in:all,open,matched']]);
        $q = StatementEntry::query()->where('lodge_id', $lodge)->where('account_id', $a->id);
        if (($v['state'] ?? 'all') === 'open') {
            $q->whereDoesntHave('reconciliations', fn ($q) => $q->whereNull('ended_at'));
        }if (($v['state'] ?? 'all') === 'matched') {
            $q->whereHas('reconciliations', fn ($q) => $q->whereNull('ended_at'));
        }
        $entries = $q->with(['reconciliations' => fn ($q) => $q->whereNull('ended_at'), 'reconciliations.line.operation'])->orderByDesc('booked_on')->orderByDesc('id')->paginate(15)->withQueryString();
        $candidates = [];
        foreach ($entries as $e) {
            $candidates[$e->id] = $banking->candidates($lodge, $e)->with('operation')->orderByDesc('id')->limit(50)->get();
        }

        return view('treasury.bank', ['lodge' => $l, 'account' => $a, 'entries' => $entries, 'candidates' => $candidates, 'categories' => Category::query()->where('lodge_id', $lodge)->orderBy('name')->get()]);
    }

    public function entry(Request $request, Banking $banking, int $lodge, int $account): RedirectResponse
    {
        $request->validate(['confirmation' => ['accepted']]);
        $banking->entry($lodge, $account, $request->all());

        return back()->with('status', 'Riga di estratto conto salvata, senza contabilizzare denaro.');
    }

    public function match(Request $request, Banking $banking, int $lodge, int $entry): RedirectResponse
    {
        $v = $request->validate(['confirmation' => ['accepted'], 'line_id' => ['required', 'integer']]);
        $banking->match($lodge, $entry, (int) $v['line_id']);

        return back()->with('status', 'Movimento riconciliato senza nuova contabilizzazione.');
    }

    public function approve(Request $request, Banking $banking, int $lodge, int $entry): RedirectResponse
    {
        $v = $request->validate(['confirmation' => ['accepted'], 'category_id' => ['required', 'integer'], 'key' => ['required', 'string']]);
        $banking->approve($lodge, $entry, (int) $v['category_id'], $v['key']);

        return back()->with('status', 'Nuova registrazione approvata e riconciliata.');
    }

    public function release(Request $request, Banking $banking, int $lodge, int $reconciliation): RedirectResponse
    {
        $v = $request->validate(['confirmation' => ['accepted'], 'reason' => ['required', 'string']]);
        $banking->release($lodge, $reconciliation, $v['reason']);

        return back()->with('status', 'Riconciliazione rilasciata. Storico conservato.');
    }

    public function preview(Request $request, Banking $banking, int $lodge, int $account): View
    {
        $request->validate(['confirmation' => ['accepted'], 'csv' => ['required', 'file', 'max:256']]);
        $file = $request->file('csv');
        abort_unless($file instanceof UploadedFile, 422);
        $data = $banking->preview($lodge, $account, $file->getContent());

        return view('treasury.preview', ['lodge' => app(Access::class)->guard($lodge, 'update'), 'batch' => $data['batch'], 'token' => $data['token']]);
    }

    public function confirm(Request $request, Banking $banking, int $lodge, int $batch): RedirectResponse
    {
        $v = $request->validate(['confirmation' => ['accepted'], 'token' => ['required', 'string']]);
        $record = $banking->batch($lodge, $batch, $v['token']);
        $count = $banking->confirm($lodge, $batch, $v['token']);

        return redirect()->route('treasury.bank', [$lodge, $record->account_id])->with('status', $count.' righe importate. Nessun movimento finanziario creato.');
    }

    public function export(Request $request, Reports $reports, string $format): Response
    {
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);
        $lodge = $this->lodge('export');
        app(Access::class)->guard($lodge->id, 'reports');
        $filters = $request->only(['from', 'to', 'kind', 'account_id', 'category_id']);
        $ops = DB::transaction(function () use ($lodge, $filters, $reports, $format) {
            $q = $reports->query($lodge->id, $filters);
            $limit = $format === 'pdf' ? 250 : 2000;
            abort_if($q->count() > $limit, 422, 'Restringi la selezione: massimo '.$limit.' operazioni per questa esportazione.');

            return $q->with(['lines.account', 'category'])->orderBy('booked_on')->orderBy('id')->get();
        });
        Audit::record('treasury.exported', VerifiedSession::user()->id, $lodge->id, metadata: ['format' => $format, 'count' => $ops->count()]);
        if ($format === 'pdf') {
            $pdfCache = storage_path('framework/cache/dompdf');
            File::ensureDirectoryExists($pdfCache, 0700, true);
            $options = new Options;
            $options->set('fontCache', $pdfCache);
            $options->set('tempDir', $pdfCache);
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('isJavascriptEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $pdf = new Dompdf($options);
            $pdf->loadHtml(view('treasury.pdf', compact('lodge', 'ops', 'filters'))->render());
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();

            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="registro-tesoreria.pdf"']);
        }
        $f = fopen('php://temp', 'r+');
        if ($f === false) {
            throw new \RuntimeException('Esportazione non disponibile.');
        }
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, ['operazione', 'data_contabile', 'inserita_utc', 'utente_id', 'tipo', 'conto', 'valuta', 'importo', 'causale', 'categoria', 'storno_di'], ';', '"', '');
        foreach ($ops as $op) {
            foreach ($op->lines->where('bucket', 'financial') as $line) {
                fputcsv($f, [$op->public_id, $op->booked_on->toDateString(), $op->created_at->format('Y-m-d H:i:s'), $op->actor_id, __('treasury.kind.'.$op->kind), Reports::cell($line->account->name), 'EUR', str_replace('.', ',', $line->amount), Reports::cell($op->description), Reports::cell($op->category_id === null ? __('treasury.kind.'.$op->kind) : $op->category->name), $op->reverses_id ?? ''], ';', '"', '');
            }
        }
        rewind($f);
        $body = stream_get_contents($f);
        fclose($f);

        return response($body, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="registro-tesoreria.csv"']);
    }
}
