@extends('membership.page')
@section('title','Il mio prospetto capitazioni')
@section('content')
@php($financeAccess=app(\App\Domains\Identity\Authorization\TenantAccess::class))@include('capitations.nav')<p class="rounded-2xl border border-slate-200 bg-white p-5">Nessuna anagrafica è associata al tuo account in questa loggia. Chiedi all’amministratore locale un invito dalla tua scheda.</p>@endsection
