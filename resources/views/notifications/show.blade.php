@extends('membership.page')
@section('title','Avviso riservato')
@section('content')
<h2 class="text-xl font-bold">{{ config('communications.types.'.$row->type) }}</h2><p class="my-4">{{ __('communications.states.'.$row->state) }}</p>
@if($body!==null)<p class="whitespace-pre-wrap rounded-2xl bg-white p-5">{{ $body }}</p>@else<p class="rounded-2xl bg-white p-5">Consulta la panoramica autorizzata per verificare le scadenze e lo stato attuale.</p>@endif
<a class="my-4 inline-block min-h-11 py-3 font-semibold text-teal-900 underline" href="{{ route('reports.dashboard',$lodge->id) }}">Apri panoramica</a>
<h2 class="my-4 text-xl font-bold">Tentativi</h2>@forelse($attempts as $attempt)<p class="my-2 rounded-xl bg-white p-4">{{ $attempt->number }} · {{ \Carbon\CarbonImmutable::parse($attempt->occurred_at,'UTC')->setTimezone('Europe/Rome')->format('d/m/Y H:i') }} · {{ __('communications.states.'.$attempt->outcome) }}</p>@empty<p>Nessun tentativo effettuato.</p>@endforelse
@endsection
