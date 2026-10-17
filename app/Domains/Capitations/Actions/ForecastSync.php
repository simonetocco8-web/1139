<?php

namespace App\Domains\Capitations\Actions;

use App\Domains\Capitations\Models\Allocation;
use App\Domains\Capitations\Models\Correction;
use App\Domains\Capitations\Models\Installment;
use App\Domains\Capitations\Money;
use App\Domains\Identity\Audit;
use Illuminate\Support\Facades\DB;

final class ForecastSync
{
    // Called within the source change's tenant transaction and lodge lock.
    public function sync(int $lodgeId, int $actorId, ?int $personId = null, ?int $planId = null): void
    {
        $query = Installment::query()->where('lodge_id', $lodgeId)->where('period_start', '>', now()->toDateString());
        if ($personId !== null) {
            $query->where('person_id', $personId);
        }
        if ($planId !== null) {
            $query->where('plan_id', $planId);
        }
        foreach ($query->orderBy('id')->lockForUpdate()->get() as $rate) {
            if (! ($rate->snapshot['generated_early'] ?? false)) {
                continue;
            }
            $projection = app(AdvanceGeneration::class)->projection($lodgeId, $rate->plan_id, $rate->period_start->toDateString(), false);
            $candidate = null;
            foreach ($projection['rows'] as $row) {
                if ($row['person_id'] === $rate->person_id) {
                    $candidate = $row['candidate'];
                    break;
                }
            }
            $sourceHash = $candidate['snapshot']['source_hash'] ?? hash('sha256', 'excluded:'.$rate->id);
            $last = $rate->corrections()->where('idempotency_key', 'like', 'forecast:%')->orderByDesc('id')->first();
            if (($last->fingerprint ?? $rate->snapshot['source_hash']) === $sourceHash) {
                continue;
            }
            $expected = $candidate === null ? 0 : Money::cents($candidate['original_amount']);
            $balance = Balances::installment($rate);
            if ($balance['paid'] > $expected) {
                // Release allocations back to the receipt's credit. Never refund or repost money.
                foreach ($rate->allocations()->whereNull('reverses_id')->get() as $allocation) {
                    if (Allocation::query()->where('lodge_id', $lodgeId)->where('reverses_id', $allocation->id)->exists()) {
                        continue;
                    }
                    Allocation::query()->create(['lodge_id' => $lodgeId, 'person_id' => $rate->person_id, 'receipt_id' => $allocation->receipt_id, 'installment_id' => $rate->id, 'amount' => Money::decimal(-Money::cents($allocation->amount)), 'reverses_id' => $allocation->id, 'reason' => 'Credito liberato per revisione tracciata di una rata futura.', 'actor_id' => $actorId, 'created_at' => now('UTC')]);
                }
            }
            $previousDue = $rate->due_on->toDateString();
            if ($candidate !== null && $candidate['due_on'] !== $previousDue) {
                // Only an unmatured forecast can change deadline; its original terms stay in the immutable snapshot.
                DB::table('capitation_installments')->where('lodge_id', $lodgeId)->where('id', $rate->id)->where('period_start', '>', now()->toDateString())->update(['due_on' => $candidate['due_on']]);
            }
            $key = 'forecast:'.substr(hash('sha256', $rate->id.':'.$sourceHash.':'.($last->id ?? 0)), 0, 55);
            Correction::query()->create(['lodge_id' => $lodgeId, 'installment_id' => $rate->id, 'amount' => Money::decimal($expected - $balance['due']), 'reason' => 'Ricalcolo rata futura per modifica di iscrizione, esenzione o condizioni. Importo ricalcolato: '.Money::decimal($expected).' EUR. Scadenza prevista dalle nuove regole: '.($candidate['due_on'] ?? 'non applicabile').'. Snapshot originale conservato.', 'idempotency_key' => $key, 'fingerprint' => $sourceHash, 'actor_id' => $actorId, 'created_at' => now('UTC')]);
            Audit::record('capitation.forecast.revised', $actorId, $lodgeId, metadata: ['installment_id' => $rate->id, 'source_hash' => $sourceHash, 'amount' => Money::decimal($expected), 'previous_due_on' => $previousDue, 'due_on' => $candidate['due_on'] ?? $previousDue]);
        }
    }
}
