<?php

namespace App\Domains\Privacy;

use App\Domains\Capitations\Models\Installment;
use App\Domains\Capitations\Models\Receipt;
use App\Domains\Identity\Audit;
use App\Domains\Identity\Authorization\TenantAccess;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Levies\Models\Obligation;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Organization\Models\Obedience;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class Cases
{
    public function administrator(int $lodge): void
    {
        app(TenantContext::class)->assertTenant($lodge, 'administration');
        abort_unless(app(TenantAccess::class)->ordinary(VerifiedSession::user(), $lodge, 'administration'), 403);
    }

    public function submit(int $lodge, int $person, string $kind, string $details): int
    {
        app(TenantContext::class)->assertTenant($lodge, 'read');
        $p = Person::query()->where('lodge_id', $lodge)->whereKey($person)->firstOrFail();
        if ($p->user_id !== VerifiedSession::user()->id) {
            $this->administrator($lodge);
        }
        Validator::make(['kind' => $kind, 'details' => $details], ['kind' => ['required', 'in:export,rectification,erasure'], 'details' => ['required', 'string', 'max:2000']])->validate();
        $id = DB::table('privacy_requests')->insertGetId(['lodge_id' => $lodge, 'person_id' => $person, 'requested_by' => VerifiedSession::user()->id, 'kind' => $kind, 'details' => Crypt::encryptString($details), 'state' => 'open', 'created_at' => now('UTC')]);
        Audit::record('privacy.request.created', VerifiedSession::user()->id, $lodge, metadata: ['request_id' => $id, 'kind' => $kind]);

        return $id;
    }

    public function visible(int $lodge, int $case): object
    {
        app(TenantContext::class)->assertTenant($lodge, 'read');
        $admin = app(TenantAccess::class)->ordinary(VerifiedSession::user(), $lodge, 'administration');

        return DB::table('privacy_requests')->where('lodge_id', $lodge)->where('id', $case)->when(! $admin, fn ($q) => $q->where('requested_by', VerifiedSession::user()->id))->firstOrFail();
    }

    /** @return array<string,mixed> */
    public function export(int $lodge, int $person): array
    {
        app(TenantContext::class)->assertTenant($lodge, 'read');
        $p = Person::query()->where('lodge_id', $lodge)->whereKey($person)->firstOrFail();
        if ($p->user_id !== VerifiedSession::user()->id) {
            $this->administrator($lodge);
            app(TenantContext::class)->assertTenant($lodge, 'export');
        }

        return DB::transaction(function () use ($p, $lodge): array {
            $rates = Installment::query()->where('lodge_id', $lodge)->where('person_id', $p->id)->with(['corrections', 'allocations'])->orderBy('id')->get()->map(fn ($r) => ['id' => $r->id, 'competenza_dal' => $r->period_start->toDateString(), 'competenza_al' => $r->period_end->toDateString(), 'scadenza' => $r->due_on->toDateString(), 'importo_originario' => $r->original_amount, 'rettifiche' => $r->corrections->map(fn ($c) => $c->only(['amount', 'reason', 'created_at']))->all(), 'imputazioni' => $r->allocations->map(fn ($a) => $a->only(['amount', 'receipt_id', 'created_at']))->all()])->all();
            $receipts = Receipt::query()->where('lodge_id', $lodge)->where('person_id', $p->id)->get()->map(fn ($r) => ['id' => $r->id, 'data' => $r->received_on->toDateString(), 'importo' => $r->amount, 'stornato' => $r->isVoided()])->all();
            Audit::record('privacy.data.exported', VerifiedSession::user()->id, $lodge, metadata: ['person_id' => $p->id]);

            return ['ambito' => 'Dati della sola persona nella loggia autorizzata; documenti con dati di terzi richiedono revisione separata.', 'generato' => now()->format('d/m/Y H:i'), 'persona' => $p->only(['id', 'reference', 'first_name', 'last_name', 'birth_date', 'email', 'phone', 'address']), 'iscrizioni' => $p->enrollments()->get()->map(fn ($e) => $e->only(['starts_on', 'ends_on', 'status', 'administrative_exemption', 'exemption_reason']))->all(), 'rate' => $rates, 'versamenti' => $receipts];
        });
    }

    public function erase(int $lodge, int $case, string $decision): void
    {
        $this->administrator($lodge);
        Validator::make(['decision' => $decision], ['decision' => ['required', 'string', 'min:10', 'max:2000']])->validate();
        $policy = config('operations.policy_reference');
        $days = (int) config('operations.person_retention_days');
        abort_unless(is_string($policy) && $policy !== '' && $days > 0, 422, 'Configurare prima la politica approvata e il periodo di conservazione.');
        $obedience = Lodge::query()->findOrFail($lodge)->obedience_id;
        DB::transaction(function () use ($lodge, $case, $decision, $policy, $days, $obedience): void {
            Obedience::query()->whereKey($obedience)->lockForUpdate()->firstOrFail();
            Lodge::query()->whereKey($lodge)->lockForUpdate()->firstOrFail();
            $this->administrator($lodge);
            $c = DB::table('privacy_requests')->where('lodge_id', $lodge)->where('id', $case)->lockForUpdate()->firstOrFail();
            if ($c->state === 'erased') {
                return;
            }
            abort_unless($c->state === 'open' && $c->kind === 'erasure' && $c->person_id !== null, 422);
            $p = Person::query()->where('lodge_id', $lodge)->whereKey($c->person_id)->lockForUpdate()->firstOrFail();
            abort_if(! $p->enrollments()->exists() || $p->enrollments()->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', now()->subDays($days)->toDateString()))->exists(), 422, 'Iscrizione aperta o periodo di conservazione non decorso.');
            foreach (['capitation_installments', 'capitation_receipts', 'capitation_person_rules', 'treasury_operations'] as $table) {
                abort_if(DB::table($table)->where('lodge_id', $lodge)->where('person_id', $p->id)->exists(), 422, 'Conservazione necessaria: esistono riferimenti contabili. La cancellazione è bloccata.');
            }
            foreach (Obligation::query()->where('lodge_id', $lodge)->cursor() as $due) {
                foreach (($due->snapshot['members'] ?? []) as $member) {
                    abort_if((int) $member['person_id'] === $p->id, 422, 'La persona è presente in uno snapshot contabile da conservare.');
                }
            }
            // Explicit privacy exception: only non-financial records, after the approved retention period.
            DB::table('privacy_requests')->where('lodge_id', $lodge)->where('person_id', $p->id)->update(['person_id' => null]);
            DB::table('identity_invitations')->where('lodge_id', $lodge)->where('person_id', $p->id)->delete();
            DB::table('member_history')->where('lodge_id', $lodge)->where('person_id', $p->id)->delete();
            DB::table('member_enrollments')->where('lodge_id', $lodge)->where('person_id', $p->id)->delete();
            DB::table('member_people')->where('lodge_id', $lodge)->where('id', $p->id)->delete();
            if ($p->user_id !== null) {
                DB::table('lodge_user')->where('lodge_id', $lodge)->where('user_id', $p->user_id)->update(['is_active' => false]);
            }
            DB::table('privacy_requests')->where('id', $case)->update(['state' => 'erased', 'decision' => Crypt::encryptString($decision), 'policy_reference' => $policy, 'resolved_by' => VerifiedSession::user()->id, 'resolved_at' => now('UTC')]);
            Audit::record('privacy.nonfinancial.erased', VerifiedSession::user()->id, $lodge, metadata: ['request_id' => $case, 'person_id' => $p->id, 'policy' => $policy]);
        }, 3);
    }
}
