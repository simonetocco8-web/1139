<?php

namespace App\Domains\Operations;

use App\Domains\Identity\Audit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class Retention
{
    /** @return array<string,int> */
    public function run(bool $apply = false): array
    {
        if ($apply && (! is_string(config('operations.policy_reference')) || config('operations.policy_reference') === '')) {
            throw new \RuntimeException('Politica approvata non configurata: nessuna cancellazione.');
        }
        $duration = (int) config('operations.communication_retention_days');
        if ($duration > 0 && $duration < 31) {
            throw new \RuntimeException('La conservazione degli invii deve essere almeno 31 giorni per proteggere la deduplicazione.');
        }

        return DB::transaction(function () use ($apply): array {
            $queries = [];
            $days = (int) config('operations.technical_retention_days');
            if ($days > 0) {
                $cut = now('UTC')->subDays($days)->format('Y-m-d H:i:s.u');
                $queries['otp_challenges'] = DB::table('otp_challenges')->where('expires_at', '<', $cut);
                $queries['password_reset_tokens'] = DB::table('password_reset_tokens')->where('created_at', '<', $cut);
                $queries['sessions'] = DB::table('sessions')->where('last_activity', '<', now('UTC')->subDays($days)->getTimestamp())->where('last_activity', '<', now('UTC')->subMinutes((int) config('session.lifetime'))->getTimestamp());
            }
            $result = [];
            foreach ($queries as $name => $q) {
                $result[$name] = $apply ? $q->delete() : $q->count();
            }
            $days = (int) config('operations.communication_retention_days');
            if ($days > 0) {
                $cut = now('UTC')->subDays($days)->format('Y-m-d H:i:s.u');
                $ids = DB::table('communication_outbox')->whereIn('state', ['accepted', 'cancelled'])->where('created_at', '<', $cut)->pluck('id');
                $result['communication_outbox'] = $ids->count();
                if ($apply) {
                    DB::table('communication_attempts')->whereIn('outbox_id', $ids)->delete();
                    DB::table('communication_outbox')->whereIn('id', $ids)->delete();
                }
                $old = DB::table('communications')->where('created_at', '<', $cut)->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('communication_outbox as o')->whereColumn('o.source_id', 'communications.id')->whereColumn('o.lodge_id', 'communications.lodge_id')->where('o.type', 'administrative'));
                $result['communications'] = $apply ? $old->delete() : $old->count();
            }
            if ($apply) {
                Audit::record('privacy.retention.applied', metadata: ['policy' => config('operations.policy_reference'), 'counts' => $result]);
            }

            return $result;
        }, 3);
    }
}
