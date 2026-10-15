<?php

namespace App\Domains\Notifications;

use App\Domains\Identity\Audit;
use App\Domains\Identity\Delivery\CodeTransport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class Delivery
{
    public function __construct(private Preferences $preferences, private Producer $producer, private CodeTransport $transport) {}

    public function send(int $id): void
    {
        $claim = DB::transaction(function () use ($id): ?array {
            $o = Outbox::query()->whereKey($id)->lockForUpdate()->first();
            if ($o === null || ! in_array($o->state, ['pending', 'blocked'], true) || ($o->attempts >= 3 && ! $o->retry_authorized) || $o->attempts >= 10 || $o->available_at->isFuture()) {
                return null;
            }
            $u = User::query()->find($o->user_id);
            $pref = $u === null ? ['enabled' => false, 'channel' => 'email'] : $this->preferences->delivery($u, $o->lodge_id, $o->type);
            if ($u === null || ! $pref['enabled'] || $o->expires_at->lessThanOrEqualTo(now('UTC')) || ! $this->producer->relevant($o, $u)) {
                $o->forceFill(['queued_at' => null, 'state' => 'cancelled', 'result_code' => 'no_longer_authorized_or_due'])->save();

                return null;
            }
            $destination = $pref['channel'] === 'email' ? $u->email : ($u->phone_verified_at === null ? null : $u->phone);
            $number = $o->attempts + 1;
            $available = $destination !== null && $this->transport->available($pref['channel']);
            $o->forceFill(['retry_authorized' => false, 'queued_at' => null, 'state' => $available ? 'sending' : 'blocked', 'channel' => $pref['channel'], 'attempts' => $number, 'claimed_at' => now('UTC'), 'available_at' => now('UTC')->addMinutes(15), 'result_code' => $available ? null : 'channel_unavailable'])->save();
            DB::table('communication_attempts')->insert(['outbox_id' => $id, 'number' => $number, 'outcome' => $available ? 'sending' : 'blocked', 'code' => $available ? 'started' : 'channel_unavailable', 'occurred_at' => now('UTC')]);
            if (! $available) {
                return null;
            }

            return ['channel' => $pref['channel'], 'destination' => $destination, 'attempt' => $number];
        }, 3);
        if ($claim === null) {
            return;
        }
        // The sending state is committed before external I/O. Ambiguous outcomes are never auto-retried.
        $accepted = false;
        try {
            $this->transport->send($claim['channel'], $claim['destination'], 'Avviso area riservata', 'È disponibile un avviso nella tua area riservata. Accedi per consultarlo: '.route('dashboard'));
            $accepted = true;
        } catch (\Throwable) {
        }
        DB::transaction(function () use ($id, $claim, $accepted): void {
            $o = Outbox::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($o->state !== 'sending' || $o->attempts !== $claim['attempt']) {
                return;
            }
            $o->forceFill(['state' => $accepted ? 'accepted' : 'uncertain', 'result_code' => $accepted ? 'provider_accepted' : 'transport_ambiguous', 'accepted_at' => $accepted ? now('UTC') : null])->save();
            DB::table('communication_attempts')->where('outbox_id', $id)->where('number', $claim['attempt'])->update(['outcome' => $o->state, 'code' => $o->result_code]);
            Audit::record('communications.result', lodge: $o->lodge_id, subject: $o->user_id, metadata: ['outbox_id' => $id, 'state' => $o->state]);
        }, 3);
    }
}
