<?php

namespace App\Http\Controllers\Notifications;

use App\Domains\Identity\Audit;
use App\Domains\Identity\Authorization\TenantAccess;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Notifications\Outbox;
use App\Domains\Notifications\Preferences;
use App\Domains\Notifications\Producer;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class Controller
{
    public function index(?int $lodge = null): View
    {
        $u = VerifiedSession::user();
        $tenant = $lodge === null ? null : app(TenantContext::class)->assertTenant($lodge, 'read');
        if ($lodge === null) {
            Gate::authorize('manage-organization');
        }
        $admin = $lodge === null || app(TenantAccess::class)->ordinary($u, $lodge, 'administration');
        $q = Outbox::query()->where('lodge_id', $lodge);
        if (! $admin) {
            $q->where('user_id', $u->id);
        }
        $types = [];
        $prefs = app(Preferences::class);
        foreach (config('communications.types') as $type => $label) {
            if ($prefs->eligible($u, $lodge, $type)) {
                $types[$type] = ['label' => $label] + $prefs->delivery($u, $lodge, $type) + ['stored' => DB::table('communication_preferences')->where('scope', Preferences::scope($lodge))->where('user_id', $u->id)->where('type', $type)->first()];
            }
        }

        return view('notifications.index', ['lodge' => $tenant, 'scopeId' => $lodge, 'admin' => $admin, 'types' => $types, 'rules' => $lodge === null ? collect() : DB::table('communication_rules')->where('lodge_id', $lodge)->pluck('enabled', 'type'), 'rows' => $q->orderByDesc('id')->paginate(20), 'key' => bin2hex(random_bytes(32))]);
    }

    public function preference(Request $r, ?int $lodge = null): RedirectResponse
    {
        $v = $r->validate(['type' => ['required', 'string'], 'enabled' => ['required', 'boolean'], 'channel' => ['required', 'in:email,sms'], 'confirm' => ['accepted']]);
        app(Preferences::class)->save($lodge, $v['type'], (bool) $v['enabled'], $v['channel']);

        return back()->with('status', 'Preferenze aggiornate. Le regole della loggia e i permessi restano necessari.');
    }

    public function rule(Request $r, int $lodge): RedirectResponse
    {
        app(TenantContext::class)->assertTenant($lodge, 'administration');
        abort_unless(app(TenantAccess::class)->ordinary(VerifiedSession::user(), $lodge, 'administration'), 403);
        $v = $r->validate(['type' => ['required', 'in:cap_upcoming,cap_overdue,levy_upcoming,levy_overdue,administrative'], 'enabled' => ['required', 'boolean'], 'confirm' => ['accepted']]);
        DB::transaction(function () use ($lodge, $v): void {
            Lodge::query()->whereKey($lodge)->lockForUpdate()->firstOrFail();
            app(TenantContext::class)->assertTenant($lodge, 'administration');
            DB::table('communication_rules')->updateOrInsert(['lodge_id' => $lodge, 'type' => $v['type']], ['enabled' => $v['enabled']]);
            Audit::record('communications.rule', VerifiedSession::user()->id, $lodge, metadata: ['type' => $v['type'], 'enabled' => $v['enabled']]);
        }, 3);

        return back()->with('status', 'Regola della loggia aggiornata.');
    }

    public function compose(Request $r, int $lodge): RedirectResponse
    {
        $v = $r->validate(['body' => ['required', 'string', 'max:3000'], 'key' => ['required', 'string'], 'confirm' => ['accepted']]);
        $count = app(Producer::class)->administrative($lodge, $v['body'], $v['key']);

        return back()->with('status', $count.' avvisi predisposti. L’accettazione del provider sarà registrata nella cronologia.');
    }

    public function show(int $lodge, int $notification): View
    {
        $tenant = app(TenantContext::class)->assertTenant($lodge, 'read');
        $u = VerifiedSession::user();
        $admin = app(TenantAccess::class)->ordinary($u, $lodge, 'administration');
        $o = Outbox::query()->where('lodge_id', $lodge)->whereKey($notification)->when(! $admin, fn ($q) => $q->where('user_id', $u->id))->firstOrFail();
        $body = null;
        if ($o->type === 'administrative') {
            $c = DB::table('communications')->where('lodge_id', $lodge)->where('id', $o->source_id)->first();
            $body = $c === null ? 'Contenuto non più conservato secondo la politica applicata.' : Crypt::decryptString($c->body);
        }

        return view('notifications.show', ['lodge' => $tenant, 'row' => $o, 'body' => $body, 'attempts' => DB::table('communication_attempts')->where('outbox_id', $o->id)->orderBy('number')->get()]);
    }

    public function retryLocal(Request $r, int $lodge, int $notification): RedirectResponse
    {
        return $this->retry($r, $notification, $lodge);
    }

    public function retry(Request $r, int $notification, ?int $lodge = null): RedirectResponse
    {
        $r->validate(['confirm' => ['accepted'], 'duplicate_risk' => ['accepted']]);
        if ($lodge === null) {
            Gate::authorize('manage-organization');
        } else {
            app(TenantContext::class)->assertTenant($lodge, 'administration');
        }
        DB::transaction(function () use ($notification, $lodge): void {
            $o = Outbox::query()->where('lodge_id', $lodge)->whereKey($notification)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($o->state, ['blocked', 'uncertain'], true) && $o->attempts < 10, 422, 'Nessun ulteriore tentativo consentito per questo avviso.');
            $o->forceFill(['retry_authorized' => true, 'queued_at' => null, 'state' => 'pending', 'available_at' => now('UTC'), 'expires_at' => now('UTC')->addDay(), 'result_code' => null])->save();
            Audit::record('communications.retry.authorized', VerifiedSession::user()->id, $lodge, metadata: ['outbox_id' => $o->id]);
        }, 3);

        return back()->with('status', 'Nuovo tentativo autorizzato. I permessi saranno ricontrollati prima dell’invio.');
    }
}
