@extends('membership.page')
@section('title','La mia panoramica')
@section('content')
@if($person)
<p class="mb-4 text-sm text-slate-600">Rate con scadenza dal {{ \App\Domains\Reports\Period::italian($period->from) }} al {{ \App\Domains\Reports\Period::italian($period->to) }}, con imputazioni aggiornate a oggi.</p>
@include('reports.metrics',['metrics'=>['Capitazioni dovute nel periodo'=>$totals['due'],'Versamenti netti nel periodo'=>$netReceived,'Importi imputati alle rate'=>$totals['paid'],'Residuo delle rate selezionate'=>$totals['residual'],'Arretrati complessivi · oggi'=>$arrears,'Credito disponibile · oggi'=>$credit]])
<div class="mb-6 flex flex-wrap gap-3"><a class="min-h-11 rounded-xl bg-teal-800 px-4 py-3 font-semibold text-white" href="{{ route('capitations.personal') }}">Il mio prospetto completo</a><a class="min-h-11 rounded-xl bg-white px-4 py-3 font-semibold text-teal-900" href="{{ route('reports.show',[$lodge->id,'personal','from'=>$period->from,'to'=>$period->to]) }}">Report personale del periodo</a></div>
@include('reports.filter')
@include('reports.upcoming')
<section class="mb-6"><h2 class="mb-4 text-xl font-bold">Storico personale · incassi e storni nel periodo</h2><div class="space-y-3">@forelse($history as $item)<article class="rounded-2xl border border-slate-200 bg-white p-5"><p class="font-semibold">{{ \App\Domains\Reports\Period::italian($item['date']) }} · {{ \App\Domains\Capitations\Money::display($item['amount']) }}</p><p class="mt-2 text-sm">{{ $item['label'] }} · {{ $item['state'] }}</p></article>@empty<p class="rounded-xl bg-white p-4 text-sm">Nessun versamento personale nel periodo.</p>@endforelse</div></section>
@else<p role="status" class="mb-6 rounded-xl bg-amber-50 p-5">Non è ancora presente un’anagrafica associata a questo account nella loggia selezionata. Un amministratore può completare l’associazione tramite invito.</p>@endif
@if($shared)<section><h2 class="mb-3 text-xl font-bold">Totali condivisi dalla loggia · oggi</h2><p class="mb-4 text-sm text-slate-600">La loggia ha autorizzato la visibilità dei soli saldi aggregati.</p>@include('reports.metrics',['metrics'=>['Banca · totale'=>$sharedBank,'Cassa · totale'=>$sharedCash,'Disponibilità · totale'=>\App\Domains\Capitations\Money::add($sharedBank,$sharedCash)]])</section>@endif
@endsection