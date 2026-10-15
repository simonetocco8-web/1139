<?php

namespace App\Domains\Operations;

use App\Domains\Capitations\Actions\Balances as CapBalances;
use App\Domains\Capitations\Models\Installment;
use App\Domains\Capitations\Models\Receipt;
use App\Domains\Capitations\Money;
use App\Domains\Organization\Models\Lodge;
use App\Domains\Reports\Period;
use App\Domains\Reports\Sources;
use App\Domains\Treasury\Actions\Balances;
use App\Domains\Treasury\Models\Account;
use App\Domains\Treasury\Models\Attachment;
use App\Domains\Treasury\Models\Operation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class Integrity
{
    /** @return array{checked:int,issues:list<string>} */
    public function check(): array
    {
        return DB::transaction(function (): array {
            $issues = [];
            $checked = 0;
            foreach (Lodge::query()->orderBy('id')->cursor() as $lodge) {
                $id = $lodge->id;
                $checked++;
                try {
                    $sources = app(Sources::class);
                    $period = new Period('2000-01-01', now()->toDateString());
                    $f = $sources->finance($id, $period);
                    $accounts = $sources->accounts($id, $period);
                    if (array_sum(array_column($accounts, 'closing')) !== $f['closing']) {
                        $issues[] = 'account_totals';
                    }
                    $sources->debtTotals($sources->rates($id));
                    $sources->debtTotals($sources->levies($id));
                    foreach (Installment::query()->where('lodge_id', $id)->cursor() as $rate) {
                        CapBalances::installment($rate);
                    }
                    foreach (Receipt::query()->where('lodge_id', $id)->cursor() as $receipt) {
                        CapBalances::credit($receipt);
                    }
                    $bad = DB::table('treasury_operations as o')->leftJoin('treasury_lines as l', fn ($j) => $j->on('l.operation_id', '=', 'o.id')->on('l.lodge_id', '=', 'o.lodge_id'))->where('o.lodge_id', $id)->groupBy('o.id')->havingRaw('COUNT(l.id)<2 OR SUM(l.amount)<>0')->select('o.id')->exists();
                    if ($bad) {
                        $issues[] = 'unbalanced_operation';
                    }
                    $cash = array_values(Account::query()->where('lodge_id', $id)->where('type', 'cash')->get()->map(fn (Account $a): int => $a->id)->all());
                    Balances::checkCash($id, $cash);
                    foreach ([['capitation_receipts', 'receipt_id', 1, 'capitation'], ['levy_payments', 'payment_id', -1, 'levy']] as [$table,$column,$sign,$kind]) {
                        foreach (DB::table($table)->where('lodge_id', $id)->orderBy('id')->cursor() as $source) {
                            $link = DB::table('treasury_source_links')->where('lodge_id', $id)->where($column, $source->id)->first();
                            $op = $link === null ? null : Operation::query()->where('lodge_id', $id)->whereKey($link->operation_id)->first();
                            if ($op === null || $op->public_id !== $source->operation_reference || $op->kind !== $kind || ($kind === 'capitation' && (int) $op->person_id !== (int) $source->person_id)) {
                                $issues[] = 'source_link';

                                continue;
                            }
                            $amount = Money::aggregate((string) $op->lines()->where('bucket', 'financial')->sum('amount'), true);
                            if ($amount !== $sign * Money::cents($source->amount)) {
                                $issues[] = 'source_amount';
                            }
                            $void = DB::table($kind === 'capitation' ? 'capitation_receipt_voids' : 'levy_payment_voids')->where('lodge_id', $id)->where($column, $source->id)->first();
                            if (($op->reversal !== null) !== ($void !== null) || ($void !== null && $void->operation_id !== $op->reversal?->id)) {
                                $issues[] = 'source_reversal';
                            }
                        }
                    }
                    foreach (Operation::query()->where('lodge_id', $id)->with('lines')->cursor() as $op) {
                        if ($op->description === '') {
                            $issues[] = 'encrypted_data';
                        }
                        if ($op->kind === 'transfer' && $op->lines->contains(fn ($l) => $l->bucket !== 'financial')) {
                            $issues[] = 'transfer_bucket';
                        }
                        if ($op->reverses_id !== null) {
                            $original = Operation::query()->where('lodge_id', $id)->whereKey($op->reverses_id)->with('lines')->first();
                            $group = fn ($lines) => $lines->groupBy(fn ($l) => $l->bucket.':'.($l->account_id ?? 'none'))->map(fn ($rows) => array_sum($rows->map(fn ($l) => Money::cents($l->amount, true))->all()))->sortKeys()->all();
                            $actual = $group($op->lines);
                            $expected = $original === null ? [] : array_map(fn ($n) => -$n, $group($original->lines));
                            if ($actual !== $expected) {
                                $issues[] = 'reversal_lines';
                            }
                        }
                        if ($op->actor_id !== null && ! DB::table('identity_events')->where('lodge_id', $id)->where('actor_id', $op->actor_id)->where('event', 'treasury.posted')->where('metadata->operation_id', $op->id)->exists()) {
                            $issues[] = 'financial_audit';
                        }
                    }
                } catch (\Throwable) {
                    $issues[] = 'financial_invariant';
                }
            }

            return ['checked' => $checked, 'issues' => array_values(array_unique($issues))];
        });
    }

    /** @return array{checked:int,issues:list<string>} */
    public function files(): array
    {
        $checked = 0;
        $issues = [];
        foreach (Attachment::query()->orderBy('id')->cursor() as $a) {
            $checked++;
            try {
                $encrypted = Storage::disk('local')->get($a->path);
                if (! is_string($encrypted)) {
                    throw new \RuntimeException;
                }
                $bytes = base64_decode(Crypt::decryptString($encrypted), true);
                if (! is_string($bytes) || ! hash_equals($a->sha256, hash('sha256', $bytes)) || strlen($bytes) !== $a->size) {
                    throw new \RuntimeException;
                }
            } catch (\Throwable) {
                $issues[] = 'private_attachment_integrity';
            }
        }

        return ['checked' => $checked, 'issues' => array_values(array_unique($issues))];
    }
}
