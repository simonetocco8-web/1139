<?php

namespace App\Domains\Reports;

use App\Domains\Capitations\Money;
use App\Domains\Identity\Authorization\TenantAccess;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Membership\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class Builder
{
    public const TITLES = ['financial' => 'Situazione finanziaria', 'categories' => 'Entrate e uscite per categoria', 'balances' => 'Saldi banca e cassa', 'capitations' => 'Capitazioni per fratello', 'arrears' => 'Arretrati capitazioni', 'levies' => 'Canoni pagati e da pagare', 'annual' => 'Prospetto annuale', 'personal' => 'Il mio prospetto capitazioni'];

    public function __construct(private Sources $sources) {}

    /** @param array<string,mixed> $input */
    public function make(int $lodgeId, string $kind, array $input = []): Document
    {
        abort_unless(isset(self::TITLES[$kind]), 404);
        $own = $kind === 'personal';
        app(TenantContext::class)->assertTenant($lodgeId, $own ? 'read' : 'reports');
        $user = VerifiedSession::user();
        if (! $own) {
            abort_unless(app(TenantAccess::class)->fullRead($user, $lodgeId), 403);
        }
        $personId = null;
        if ($own) {
            $personId = Person::query()->where('lodge_id', $lodgeId)->where('user_id', $user->id)->value('id');
        }
        if ($kind === 'arrears' && ! isset($input['from'])) {
            $input['from'] = '2000-01-01';
        }
        $period = Period::make($input, $kind === 'annual');

        return DB::transaction(function () use ($lodgeId, $kind, $own, $personId, $period): Document {
            $money = fn (string $label) => ['label' => $label, 'type' => 'money'];
            $text = fn (string $label) => ['label' => $label, 'type' => 'text'];
            $date = fn (string $label) => ['label' => $label, 'type' => 'date'];
            $metrics = [];
            $rows = [];
            $note = '';
            $columns = [];
            if (in_array($kind, ['financial', 'categories', 'balances', 'annual'], true)) {
                $f = $this->sources->finance($lodgeId, $period);
                $metrics = ['Saldo finale periodo' => $f['closing'], 'Saldo iniziale periodo' => $f['opening'], 'Entrate nette' => $f['income'], 'Uscite nette' => $f['expense'], 'Aperture e rettifiche' => $f['equity'], 'Variazione finanziaria' => $f['change']];
                $note = 'Saldi e movimenti per data contabile. Saldo iniziale + entrate − uscite + aperture/rettifiche = saldo finale. Giroconti interni esclusi dai totali economici; storni inclusi con segno opposto.';
                if (in_array($kind, ['financial', 'balances'], true)) {
                    $rows = $this->sources->accounts($lodgeId, $period);
                    $columns = ['account' => $text('Conto / cassa'), 'type' => $text('Tipo'), 'opening' => $money('Saldo iniziale periodo'), 'change' => $money('Variazione'), 'closing' => $money('Saldo finale periodo')];
                }
                if ($kind === 'categories') {
                    $rows = $this->sources->categories($lodgeId, $period);
                    $columns = ['category' => $text('Categoria'), 'type' => $text('Tipo'), 'income' => $money('Entrate nette'), 'expense' => $money('Uscite nette'), 'net' => $money('Risultato netto')];
                }
                if ($kind === 'annual') {
                    for ($d = CarbonImmutable::parse($period->from, 'Europe/Rome'); $d->toDateString() <= $period->to; $d = $d->addMonth()) {
                        $end = min($d->endOfMonth()->toDateString(), $period->to);
                        $m = $this->sources->finance($lodgeId, new Period($d->toDateString(), $end));
                        $rows[] = ['month' => $d->format('m/Y'), 'opening' => $m['opening'], 'income' => $m['income'], 'expense' => $m['expense'], 'equity' => $m['equity'], 'closing' => $m['closing']];
                    }
                    $columns = ['month' => $text('Mese'), 'opening' => $money('Saldo iniziale'), 'income' => $money('Entrate nette'), 'expense' => $money('Uscite nette'), 'equity' => $money('Aperture/rettifiche'), 'closing' => $money('Saldo finale')];
                }
            } else {
                $q = $kind === 'levies' ? $this->sources->levies($lodgeId) : $this->sources->rates($lodgeId, $personId);
                if ($own && $personId === null) {
                    $q->whereRaw('1=0');
                }
                $q->whereBetween('due_on', [$period->from, $period->to]);
                if ($kind === 'arrears') {
                    $q = DB::query()->fromSub($q, 'a')->where('due_on', '<', now()->toDateString())->where('residual', '>', 0);
                }
                $totals = $this->sources->debtTotals(clone $q);
                $metrics = ['Dovuto' => $totals['due'], 'Imputato / versato' => $totals['paid'], 'Residuo' => $totals['residual']];
                $note = 'Situazione delle obbligazioni aggiornata al '.now()->format('d/m/Y').', selezionate per scadenza nel periodo. Il versato indica le imputazioni nette attuali, anche successive alla scadenza; non è un flusso finanziario del periodo. Crediti non imputati non compensano automaticamente i residui.';
                if (in_array($kind, ['capitations', 'arrears'], true)) {
                    $g = DB::query()->fromSub($q, 'g')->select(['person_id', 'reference', 'first_name', 'last_name'])->selectRaw('SUM(due) as due,SUM(paid) as paid,SUM(residual) as residual,MIN(due_on) as first_due')->groupBy('person_id', 'reference', 'first_name', 'last_name')->orderBy('last_name')->orderBy('first_name');
                    abort_if((clone $g)->getCountForPagination() > 5000, 422, 'Restringi il periodo: massimo 5.000 righe per report.');
                    foreach ($g->get() as $v) {
                        $rows[] = ['person' => $v->last_name.' '.$v->first_name, 'reference' => $v->reference, 'due' => Money::aggregate((string) $v->due, true), 'paid' => Money::aggregate((string) $v->paid, true), 'residual' => Money::aggregate((string) $v->residual, true), 'first_due' => substr($v->first_due, 0, 10)];
                    }
                    $columns = ['person' => $text('Fratello'), 'reference' => $text('Riferimento'), 'due' => $money('Dovuto'), 'paid' => $money('Imputato'), 'residual' => $money('Residuo'), 'first_due' => $date('Prima scadenza selezionata')];
                } else {
                    abort_if((clone $q)->count() > 5000, 422, 'Restringi il periodo: massimo 5.000 righe per report.');
                    foreach ($q->orderBy('due_on')->orderBy('r.id')->get() as $v) {
                        $due = Money::aggregate((string) $v->due, true);
                        $paid = Money::aggregate((string) $v->paid, true);
                        $residual = Money::aggregate((string) $v->residual, true);
                        if ($due < 0 || $paid < 0 || $residual < 0) {
                            throw new \LogicException('Residui incoerenti.');
                        }
                        $rows[] = ['reference' => $kind === 'levies' ? $v->recipient_name : 'Rata #'.$v->id, 'period_start' => substr($v->period_start, 0, 10), 'due_on' => substr($v->due_on, 0, 10), 'due' => $due, 'paid' => $paid, 'residual' => $residual, 'status' => $residual === 0 ? ($due === 0 ? 'Importo nullo' : 'Saldato') : ($v->due_on < now()->toDateString() ? 'Scaduto' : 'Da pagare')];
                    }
                    $columns = ['reference' => $text($kind === 'levies' ? 'Destinatario storico' : 'Rata'), 'period_start' => $date('Inizio competenza'), 'due_on' => $date('Scadenza'), 'due' => $money('Dovuto'), 'paid' => $money('Versato / imputato'), 'residual' => $money('Residuo'), 'status' => $text('Stato attuale')];
                }
            }

            return new Document(self::TITLES[$kind], $note, $period, $metrics, $columns, $rows);
        });
    }
}
