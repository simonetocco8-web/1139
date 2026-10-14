@extends('membership.page')
@section('title', 'Inserisci un fratello')
@section('content')
<form method="POST" action="{{ route('members.store', $lodge->id) }}" class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7">@csrf
<h2 class="mb-4 text-xl font-semibold">Dati della persona</h2>@include('membership.person-fields')
<h2 class="mb-4 mt-8 text-xl font-semibold">Prima iscrizione</h2>@include('membership.enrollment-fields')
<p class="mt-4 text-sm text-slate-600">L’inserimento non crea account o autorizzazioni. L’invito potrà essere inviato dalla scheda.</p>
@include('membership.confirmation')<button class="min-h-12 rounded-xl bg-teal-800 px-5 font-semibold text-white">Inserisci persona e iscrizione</button>
</form>
@endsection
