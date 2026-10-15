<?php

namespace App\Http\Controllers\Privacy;

use App\Domains\Identity\Audit;
use App\Domains\Identity\Authorization\TenantAccess;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Membership\Models\Person;
use App\Domains\Privacy\Cases;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class Controller
{
    public function index(int $lodge): View
    {
        $tenant = app(TenantContext::class)->assertTenant($lodge, 'read');
        $u = VerifiedSession::user();
        $admin = app(TenantAccess::class)->ordinary($u, $lodge, 'administration');
        $q = DB::table('privacy_requests')->where('lodge_id', $lodge)->when(! $admin, fn ($q) => $q->where('requested_by', $u->id));
        $people = Person::query()->where('lodge_id', $lodge)->when(! $admin, fn ($q) => $q->where('user_id', $u->id))->orderBy('last_name')->get(['id', 'first_name', 'last_name']);

        return view('privacy.index', ['lodge' => $tenant, 'admin' => $admin, 'rows' => $q->orderByDesc('id')->paginate(15), 'people' => $people]);
    }

    public function store(Request $r, Cases $cases, int $lodge): RedirectResponse
    {
        $v = $r->validate(['person_id' => ['required', 'integer'], 'kind' => ['required', 'string'], 'details' => ['required', 'string'], 'confirm' => ['accepted']]);
        $cases->submit($lodge, (int) $v['person_id'], $v['kind'], $v['details']);

        return back()->with('status', 'Richiesta registrata per la valutazione del titolare.');
    }

    public function export(Cases $cases, int $lodge, int $person): Response
    {
        return response(json_encode($cases->export($lodge, $person), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 200, ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="dati-personali.json"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function erase(Request $r, Cases $cases, int $lodge, int $case): RedirectResponse
    {
        $v = $r->validate(['decision' => ['required', 'string'], 'confirm' => ['accepted']]);
        $cases->erase($lodge, $case, $v['decision']);

        return back()->with('status', 'Dati non contabili cancellati secondo la politica approvata. Account globale e documenti finanziari non modificati.');
    }

    public function resolve(Request $r, Cases $cases, int $lodge, int $case): RedirectResponse
    {
        $cases->administrator($lodge);
        $v = $r->validate(['decision' => ['required', 'string', 'min:10', 'max:2000'], 'state' => ['required', 'in:completed,declined'], 'confirm' => ['accepted']]);
        DB::transaction(function () use ($cases, $lodge, $case, $v): void {
            $cases->administrator($lodge);
            $c = DB::table('privacy_requests')->where('lodge_id', $lodge)->where('id', $case)->lockForUpdate()->firstOrFail();
            abort_unless($c->state === 'open', 409);
            abort_if($c->kind === 'erasure' && $v['state'] === 'completed', 422, 'Usare la procedura di cancellazione con controllo dei vincoli, oppure motivare il diniego.');
            DB::table('privacy_requests')->where('id', $case)->update(['state' => $v['state'], 'decision' => Crypt::encryptString($v['decision']), 'policy_reference' => config('operations.policy_reference'), 'resolved_by' => VerifiedSession::user()->id, 'resolved_at' => now('UTC')]);
            Audit::record('privacy.request.resolved', VerifiedSession::user()->id, $lodge, metadata: ['request_id' => $case, 'state' => $v['state']]);
        }, 3);

        return back()->with('status', 'Esito della valutazione registrato.');
    }
}
