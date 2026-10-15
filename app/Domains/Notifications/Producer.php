<?php

namespace App\Domains\Notifications;

use App\Domains\Identity\Audit;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Reports\Sources;
use App\Jobs\DeliverCommunication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class Producer
{
    public function __construct(private Preferences $preferences, private Sources $sources) {}

    public function enqueue(?int $lodge, User $user, string $type, ?int $source, string $period): bool
    {
        if (! $this->preferences->delivery($user, $lodge, $type)['enabled']) {
            return false;
        }

        return DB::table('communication_outbox')->insertOrIgnore(['lodge_id' => $lodge, 'user_id' => $user->id, 'type' => $type, 'source_id' => $source, 'dedupe_key' => hash('sha256', Preferences::scope($lodge).'|'.$user->id.'|'.$type.'|'.$source.'|'.$period), 'topic' => $lodge === null ? explode('|', $period, 2)[0] : null, 'state' => 'pending', 'attempts' => 0, 'available_at' => now('UTC'), 'expires_at' => now('UTC')->addDay(), 'created_at' => now('UTC')]) === 1;
    }

    public function reminders(): int
    {
        if (! config('communications.enabled')) {
            return 0;
        }
        $count = 0;
        $today = now()->toDateString();
        $interval = max(1, min(30, (int) config('communications.overdue_interval_days')));
        $cycle = fn (string $due): string => (string) intdiv(max(0, (int) CarbonImmutable::parse($due, 'UTC')->diffInDays(CarbonImmutable::parse($today, 'UTC')) - 1), $interval);
        foreach (Lodge::query()->where('is_active', true)->whereHas('obedience', fn ($q) => $q->where('is_active', true))->orderBy('id')->cursor() as $lodge) {
            $limit = now()->addDays($lodge->reminder_days)->toDateString();
            foreach ($this->sources->openRates($lodge->id)->where('due_on', '<=', $limit)->orderBy('id')->cursor() as $r) {
                $person = Person::query()->where('lodge_id', $lodge->id)->whereKey($r->person_id)->first();
                $u = $person?->user_id === null ? null : User::query()->find($person->user_id);
                if ($u === null) {
                    continue;
                }
                $overdue = $r->due_on < $today;
                $count += (int) $this->enqueue($lodge->id, $u, $overdue ? 'cap_overdue' : 'cap_upcoming', (int) $r->id, $overdue ? $cycle((string) $r->due_on) : (string) $r->due_on);
            }
            $recipients = $lodge->users()->wherePivot('is_active', true)->get();
            foreach ($this->sources->openLevies($lodge->id)->where('due_on', '<=', $limit)->orderBy('id')->cursor() as $r) {
                $overdue = $r->due_on < $today;
                foreach ($recipients as $u) {
                    $count += (int) $this->enqueue($lodge->id, $u, $overdue ? 'levy_overdue' : 'levy_upcoming', (int) $r->id, $overdue ? $cycle((string) $r->due_on) : (string) $r->due_on);
                }
            }
        }

        return $count;
    }

    public function administrative(int $lodge, string $body, string $key): int
    {
        app(TenantContext::class)->assertTenant($lodge, 'administration');
        Validator::make(['body' => $body, 'key' => $key], ['body' => ['required', 'string', 'max:3000'], 'key' => ['required', 'regex:/^[a-f0-9]{64}$/D']])->validate();

        return DB::transaction(function () use ($lodge, $body, $key): int {
            Lodge::query()->whereKey($lodge)->lockForUpdate()->firstOrFail();
            app(TenantContext::class)->assertTenant($lodge, 'administration');
            abort_unless(config('communications.enabled') && DB::table('communication_rules')->where('lodge_id', $lodge)->where('type', 'administrative')->where('enabled', true)->exists(), 422, 'Abilitare prima le comunicazioni per la loggia e la piattaforma.');
            $existing = DB::table('communications')->where('lodge_id', $lodge)->where('request_key', $key)->first();
            if ($existing !== null) {
                abort_unless(hash_equals(Crypt::decryptString($existing->body), $body), 409);

                return 0;
            }
            $id = DB::table('communications')->insertGetId(['lodge_id' => $lodge, 'actor_id' => VerifiedSession::user()->id, 'request_key' => $key, 'body' => Crypt::encryptString($body), 'created_at' => now('UTC')]);
            $count = 0;
            foreach (Lodge::query()->findOrFail($lodge)->users()->wherePivot('is_active', true)->get() as $u) {
                $count += (int) $this->enqueue($lodge, $u, 'administrative', $id, (string) $id);
            }
            Audit::record('communications.created', VerifiedSession::user()->id, $lodge, metadata: ['communication_id' => $id, 'recipients' => $count]);

            return $count;
        }, 3);
    }

    public function relevant(Outbox $o, User $u): bool
    {
        if ($o->type === 'technical') {
            return $o->lodge_id === null && $o->source_id === null;
        }
        if ($o->lodge_id === null) {
            return false;
        }
        if ($o->type === 'administrative') {
            return DB::table('communications')->where('lodge_id', $o->lodge_id)->where('id', $o->source_id)->exists();
        }
        $today = now()->toDateString();
        $days = Lodge::query()->whereKey($o->lodge_id)->value('reminder_days');
        if (str_starts_with($o->type, 'cap_')) {
            $person = Person::query()->where('lodge_id', $o->lodge_id)->where('user_id', $u->id)->value('id');
            if ($person === null) {
                return false;
            }
            $q = $this->sources->openRates($o->lodge_id, (int) $person);
        } elseif (str_starts_with($o->type, 'levy_')) {
            $q = $this->sources->openLevies($o->lodge_id);
        } else {
            return false;
        }
        $q->where('id', $o->source_id);
        if (str_ends_with($o->type, 'overdue')) {
            $q->where('due_on', '<', $today);
        } else {
            $q->whereBetween('due_on', [$today, now()->addDays((int) $days)->toDateString()]);
        }

        return $q->exists();
    }

    public function pump(): int
    {
        $now = now('UTC')->format('Y-m-d H:i:s.u');
        DB::transaction(function () use ($now): void {
            foreach (Outbox::query()->where('state', 'sending')->where('claimed_at', '<', now('UTC')->subMinutes(5)->format('Y-m-d H:i:s.u'))->lockForUpdate()->get() as $o) {
                $o->forceFill(['state' => 'uncertain', 'result_code' => 'worker_interrupted'])->save();
                DB::table('communication_attempts')->where('outbox_id', $o->id)->where('number', $o->attempts)->update(['outcome' => 'uncertain', 'code' => 'worker_interrupted']);
            }
            Outbox::query()->whereIn('state', ['pending', 'blocked'])->where('expires_at', '<=', $now)->update(['state' => 'cancelled', 'result_code' => 'expired']);
        });
        if (! config('communications.enabled')) {
            return 0;
        }
        if (config('queue.connections.database.after_commit') || ! in_array(config('queue.connections.database.connection'), [null, config('database.default')], true)) {
            throw new \LogicException('La coda comunicazioni deve usare la stessa connessione database con after_commit=false.');
        }
        $count = 0;
        foreach (Outbox::query()->whereIn('state', ['pending', 'blocked'])->where('attempts', '<', 10)->where(fn ($q) => $q->where('attempts', '<', 3)->orWhere('retry_authorized', true))->where('available_at', '<=', $now)->where('expires_at', '>', $now)->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', now('UTC')->subMinutes(10)->format('Y-m-d H:i:s.u')))->orderBy('id')->limit(250)->pluck('id') as $id) {
            $count += (int) DB::transaction(function () use ($id): bool {
                $o = Outbox::query()->whereKey($id)->lockForUpdate()->first();
                if ($o === null || ! in_array($o->state, ['pending', 'blocked'], true) || ($o->attempts >= 3 && ! $o->retry_authorized) || $o->attempts >= 10 || $o->available_at->isFuture() || $o->expires_at->lessThanOrEqualTo(now('UTC')) || ($o->queued_at !== null && $o->queued_at->greaterThanOrEqualTo(now('UTC')->subMinutes(10)))) {
                    return false;
                }
                Bus::dispatch((new DeliverCommunication($o->id))->onConnection('database')->onQueue('communications'));
                $o->forceFill(['queued_at' => now('UTC')])->save();

                return true;
            }, 3);
        }

        return $count;
    }
}
