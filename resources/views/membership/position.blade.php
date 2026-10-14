@extends('membership.page')
@section('title', 'La mia posizione')
@section('content')
@if($person)
<div class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7"><h2 class="text-xl font-semibold">{{ $person->first_name }} {{ $person->last_name }}</h2><p class="mt-2 text-slate-600">Codice locale {{ $person->reference }}</p><p class="mt-5 rounded-xl bg-slate-50 p-4 leading-7">Il modulo economico non è ancora disponibile. Qui trovi le tue iscrizioni nella loggia selezionata; importi e saldi saranno disponibili nelle fasi contabili.</p>@foreach($enrollments as $enrollment)<div class="mt-5 border-t border-slate-200 pt-4"><p class="font-semibold">{{ __('membership.status.'.$enrollment->status) }}</p><p class="mt-2 text-sm">Dal {{ $enrollment->starts_on->format('d/m/Y') }} @if($enrollment->ends_on)al {{ $enrollment->ends_on->format('d/m/Y') }}@endif</p><p class="mt-2 text-sm">Esenzione amministrativa: {{ $enrollment->administrative_exemption ? 'Sì' : 'No' }}</p></div>@endforeach</div>
@else<p class="rounded-2xl border border-slate-200 bg-white p-5 leading-7">Nessuna anagrafica è associata al tuo account in questa loggia. Chiedi all’amministratore locale un invito dalla tua scheda anagrafica.</p>@endif
@endsection
