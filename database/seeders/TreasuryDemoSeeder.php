<?php

namespace Database\Seeders;

use App\Domains\Organization\Models\Lodge;
use App\Domains\Treasury\Models\Account;
use App\Domains\Treasury\Models\Category;
use App\Domains\Treasury\Models\Line;
use App\Domains\Treasury\Models\Operation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TreasuryDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Solo dati fittizi in locale/testing.');
        }
        $this->call(LevyDemoSeeder::class);
        DB::transaction(function (): void {
            foreach (Lodge::query()->whereHas('obedience', fn ($q) => $q->where('code', 'DEMO_LEVY'))->get() as $l) {
                $date = now()->startOfYear()->toDateString();
                $bank = $this->account($l->id, 'Banca Dimostrativa Fittizia', 'bank', '1000.00', $date);
                $cash = $this->account($l->id, 'Cassa Dimostrativa Fittizia', 'cash', '100.00', $date);
                $income = Category::query()->firstOrCreate(['lodge_id' => $l->id, 'name' => 'Contributo dimostrativo fittizio', 'type' => 'income']);
                $expense = Category::query()->firstOrCreate(['lodge_id' => $l->id, 'name' => 'Spesa dimostrativa fittizia', 'type' => 'expense']);
                $this->operation($l->id, 'income', now()->toDateString(), 'Contributo interamente fittizio', 'demo:income', $income->id, [[$bank->id, 'financial', '30.00'], [null, 'income', '-30.00']]);
                $this->operation($l->id, 'expense', now()->toDateString(), 'Spesa interamente fittizia', 'demo:expense', $expense->id, [[$cash->id, 'financial', '-10.00'], [null, 'expense', '10.00']]);
                $this->operation($l->id, 'transfer', now()->toDateString(), 'Giroconto interamente fittizio', 'demo:transfer', null, [[$bank->id, 'financial', '-50.00'], [$cash->id, 'financial', '50.00']]);
            }
        });
    }

    private function account(int $lodgeId, string $name, string $type, string $amount, string $date): Account
    {
        $a = Account::query()->firstOrCreate(['lodge_id' => $lodgeId, 'name' => $name], ['type' => $type, 'currency' => 'EUR', 'opening_amount' => $amount, 'opened_on' => $date, 'idempotency_key' => hash('sha256', 'demo:'.$type), 'fingerprint' => hash('sha256', $name), 'created_at' => now('UTC')]);
        $this->operation($lodgeId, 'opening', $date, 'Apertura Fittizia · '.$name, 'opening:'.$a->id, null, [[$a->id, 'financial', $amount], [null, 'equity', '-'.$amount]]);

        return $a;
    }

    /** @param list<array{0:int|null,1:string,2:string}> $lines */
    private function operation(int $lodgeId, string $kind, string $date, string $description, string $key, ?int $category, array $lines): void
    {
        if (Operation::query()->where('lodge_id', $lodgeId)->where('idempotency_key', $key)->exists()) {
            return;
        }
        $op = Operation::query()->create(['lodge_id' => $lodgeId, 'obedience_id' => Lodge::query()->findOrFail($lodgeId)->obedience_id, 'public_id' => (string) Str::ulid(), 'kind' => $kind, 'booked_on' => $date, 'description' => $description, 'category_id' => $category, 'idempotency_key' => $key, 'fingerprint' => hash('sha256', $key), 'created_at' => now('UTC')]);
        foreach ($lines as [$account,$bucket,$amount]) {
            Line::query()->create(['lodge_id' => $lodgeId, 'operation_id' => $op->id, 'account_id' => $account, 'bucket' => $bucket, 'amount' => $amount]);
        }
    }
}
