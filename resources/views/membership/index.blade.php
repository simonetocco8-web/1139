@extends('membership.page')
@section('title', 'Fratelli della loggia')
@section('content')
@php($admin = app(\App\Domains\Identity\Authorization\TenantAccess::class)->allows(\App\Domains\Identity\VerifiedSession::user(), $lodge->id, 'members.manage'))
<div class="mb-5 flex flex-wrap gap-3">
@if($admin)<a class="flex min-h-12 items-center rounded-xl bg-teal-800 px-5 font-semibold text-white" href="{{ route('members.create', $lodge->id) }}">Inserisci fratello</a><a class="flex min-h-12 items-center rounded-xl border border-slate-300 px-4 font-semibold" href="{{ route('members.import') }}">Importa CSV</a>@if(app(\App\Domains\Identity\Authorization\TenantAccess::class)->allows(\App\Domains\Identity\VerifiedSession::user(),$lodge->id,'administration'))<a class="flex min-h-12 items-center rounded-xl border border-slate-300 px-4 font-semibold" href="{{ route('members.settings') }}">Impostazioni loggia</a>@endif@endif
@if(app(\App\Domains\Identity\Authorization\TenantAccess::class)->allows(\App\Domains\Identity\VerifiedSession::user(), $lodge->id, 'export'))<a class="flex min-h-12 items-center rounded-xl border border-slate-300 px-4 font-semibold" href="{{ route('members.export') }}">Esporta iscrizioni CSV</a>@endif
</div>
<form method="GET" class="mb-6 grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-[1fr_12rem_auto]">
<label class="text-sm font-semibold">Nome o cognome<input name="search" maxlength="120" value="{{ $filters['search'] ?? '' }}" class="mt-2 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal"></label>
<label class="text-sm font-semibold">Stato ultima iscrizione<select name="status" class="mt-2 min-h-12 w-full rounded-xl border border-slate-300 px-3"><option value="">Tutti</option>@foreach(__('membership.status') as $key => $label)<option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
<button class="min-h-12 self-end rounded-xl border border-teal-700 px-5 font-semibold text-teal-900">Cerca</button>
</form>
<div class="space-y-3">@forelse($people as $person)@php($latest = $person->enrollments->sortByDesc('starts_on')->first())<article class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-5"><div><h2 class="text-lg font-semibold">{{ $person->last_name }} {{ $person->first_name }}</h2><p class="mt-1 text-sm text-slate-600">{{ $person->reference }} · {{ $latest ? __('membership.status.'.$latest->status) : 'Nessuna iscrizione' }}</p><p class="mt-1 text-sm text-slate-600">{{ $person->user_id ? 'Account associato' : 'Nessun account associato' }}</p></div><a class="flex min-h-12 items-center rounded-xl border border-slate-300 px-4 font-semibold" href="{{ route('members.show', [$lodge->id, $person->id]) }}">Apri scheda</a></article>@empty<p class="rounded-xl bg-white p-5">Nessun fratello corrisponde alla ricerca.</p>@endforelse</div>
<div class="mt-6">{{ $people->links() }}</div>
@endsection
