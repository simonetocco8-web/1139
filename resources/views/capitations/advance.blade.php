@extends('membership.page')
@section('title','Anteprima rate anticipate')
@section('content')
@include('capitations.nav')
<h2 class="mb-3 text-xl font-semibold">{{ $preview['description'] }}</h2>
<p class="mb-5 rounded-xl bg-amber-50 p-4">Competenza {{ \Carbon\CarbonImmutable::parse($preview['cycle'])->format('d/m/Y') }} — {{ \Carbon\CarbonImmutable::parse($preview['end'])->format('d/m/Y') }}. Sono rate future: non entrano nei debiti maturati o nei morosi. L’anteprima scade dopo 15 minuti.</p>
<div class="space-y-3">@forelse($preview['rows'] as $row)<article class="rounded-xl border border-slate-200 bg-white p-4"><h3 class="font-semibold">{{ $row['label'] }}</h3>
@if($row['candidate'])<p class="mt-2">Importo {{ $row['candidate']['original_amount'] }} EUR · Scadenza {{ \Carbon\CarbonImmutable::parse($row['candidate']['due_on'])->format('d/m/Y') }}</p>@else<p>Non più prevista dalle condizioni attuali.</p>@endif
@if($row['existing_id'])<p class="mt-2 font-semibold">Già generata: rata #{{ $row['existing_id'] }} · {{ $row['existing_amount'] }} EUR. Non sarà duplicata o modificata.</p>@endif
@if($row['changed'])<p class="mt-2 font-semibold text-red-800">Dati modificati dopo la generazione: verifica iscrizione, esenzione e condizioni. La rata originale resta conservata; usa una rettifica motivata per correggerla.</p><a class="inline-block min-h-11 py-3 text-teal-900 underline" href="{{ route('capitations.person',[$lodge->id,$row['person_id']]) }}">Verifica posizione e rettifiche</a>@endif
</article>@empty<p>Nessun fratello interessato secondo iscrizioni, esenzioni e condizioni attuali.</p>@endforelse</div>
<form method="POST" action="{{ route('capitations.advance.confirm',$lodge->id) }}" class="mt-6 rounded-xl bg-white p-5">@csrf
<input type="hidden" name="token" value="{{ $preview['token'] }}">
<p>Conferma solo dopo aver verificato fratelli, competenza, importi e scadenze. Nessun incasso verrà creato.</p>
@include('membership.confirmation')<button class="min-h-12 rounded-xl bg-teal-800 px-5 font-semibold text-white">Conferma generazione rate anticipate</button></form>
@endsection
