@extends('membership.page')
@section('title','Riepilogo Tesoreria della loggia')
@section('content')
<p class="mb-5 rounded-xl bg-teal-50 p-4 text-sm">Totali aggregati condivisi dalla loggia. La posizione economica personale rimane disponibile nel prospetto capitazioni.</p><div class="grid gap-4 sm:grid-cols-3">@foreach(['Conti bancari'=>$bank,'Casse contanti'=>$cash,'Consolidato'=>\App\Domains\Capitations\Money::add($bank,$cash)] as $label=>$value)<div class="rounded-xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-600">{{ $label }}</p><p class="mt-2 text-2xl font-bold">{{ \App\Domains\Capitations\Money::display($value) }}</p></div>@endforeach</div>
@endsection