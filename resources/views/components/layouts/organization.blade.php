<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><title>Gestionale Multi-Loggia · Area riservata</title>
    @vite(['resources/css/app.css', 'resources/js/app.js']) @livewireStyles
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900">
<a class="sr-only focus:not-sr-only focus:fixed focus:z-50 focus:bg-white focus:p-4" href="#contenuto">Vai al contenuto</a>
<div class="lg:grid lg:min-h-screen lg:grid-cols-[16rem_1fr]">
    <aside class="border-b border-slate-200 bg-white p-5 lg:border-r lg:border-b-0 lg:p-7">
        <div class="flex items-center justify-between gap-3"><a href="{{ route('dashboard') }}" class="flex min-h-12 items-center gap-3 rounded font-semibold focus-visible:outline-2 focus-visible:outline-teal-700"><span class="flex size-10 items-center justify-center rounded-xl bg-teal-800 text-xl text-white" aria-hidden="true">M</span>Multi-Loggia</a><button type="button" data-menu-toggle hidden aria-controls="app-navigation" aria-expanded="false" class="min-h-11 rounded-xl border border-slate-300 px-4 text-sm font-semibold lg:hidden">Menu</button></div>
        <nav id="app-navigation" aria-label="Amministrazione" class="mt-6 hidden flex-wrap gap-2 lg:flex lg:flex-col"><a href="{{ route('dashboard') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Dashboard</a>
        <a href="{{ route('account.security') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Sicurezza account</a>
        @can('manage-organization')
        <a href="{{ route('communications.platform') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Notifiche piattaforma</a>
            @foreach(['obediences' => 'Obbedienze', 'rites' => 'Riti', 'structures' => 'Sovrastrutture', 'lodges' => 'Logge'] as $key => $label)
                <a href="{{ route('organization.index', $key) }}" @if(request()->route('kind') === $key) aria-current="page" @endif class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold hover:bg-teal-50 focus-visible:outline-2 focus-visible:outline-teal-700 {{ request()->route('kind') === $key ? 'bg-teal-50 text-teal-900' : 'text-slate-700' }}">{{ $label }}</a>
            @endforeach
            <a href="{{ route('platform.invites') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Inviti piattaforma</a>
            <a href="{{ route('assistance') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Assistenza</a>
        <a href="{{ route('levies.platform') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Regole generali canoni</a>
        @endcan
        @php($currentLodge = app(\App\Domains\Organization\Actions\ActiveLodge::class)->current())
        @if($currentLodge)
            <a href="{{ route('privacy.index',$currentLodge->id) }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Dati personali e privacy</a>
            <a href="{{ route('communications.index',$currentLodge->id) }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Notifiche</a>
            <a href="{{ route('reports.dashboard',$currentLodge->id) }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Panoramica loggia</a>
            @if(\App\Domains\Organization\Models\Lodge::query()->whereKey($currentLodge->id)->where('brothers_treasury_visible',true)->exists())<a href="{{ route('treasury.summary') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Riepilogo Tesoreria</a>@endif
            <a href="{{ route('capitations.personal') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Il mio prospetto capitazioni</a>
            <a href="{{ route('members.position') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">La mia posizione</a>
            @if(app(\App\Domains\Identity\Authorization\TenantAccess::class)->fullRead(\App\Domains\Identity\VerifiedSession::user(), $currentLodge->id))
                <a href="{{ route('members.index') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Fratelli della loggia</a>
                <a href="{{ route('treasury.index') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Tesoreria</a>
                <a href="{{ route('levies.index') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Canoni sovrastrutture</a>
                <a href="{{ route('capitations.index') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Capitazioni</a>
            @endif
        @endif
        @if($currentLodge && app(\App\Domains\Identity\Authorization\TenantAccess::class)->allows(\App\Domains\Identity\VerifiedSession::user(), $currentLodge->id, 'administration'))
            <a href="{{ route('tenant.access') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Accessi della loggia</a>
            <a href="{{ route('members.settings') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Impostazioni loggia</a>
        @endif
        @if($currentLodge && app(\App\Domains\Identity\Authorization\TenantAccess::class)->allows(\App\Domains\Identity\VerifiedSession::user(), $currentLodge->id, 'reports'))
            <a href="{{ route('reports.show',[$currentLodge->id,'financial']) }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Report gestionali</a>
            <a href="{{ route('tenant.report') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Report accessi</a>
        @endif
            <a href="{{ route('lodge.select') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Loggia attiva</a>
        </nav>
        <p class="mt-6 hidden text-sm leading-6 lg:block text-slate-600">Gli accessi alle singole logge restano separati dai permessi della piattaforma.</p>
    </aside>
    <div class="min-w-0">
        <header class="border-b border-slate-200 bg-white px-5 py-4 lg:px-9"><span class="text-sm font-semibold text-slate-600">Area riservata · Gestionale</span><form method="POST" action="{{ route('logout') }}" class="mt-2">@csrf<button class="min-h-11 rounded-lg px-3 text-sm font-semibold text-teal-900 underline">Esci</button></form></header>
        <main id="contenuto" class="mx-auto max-w-6xl px-5 py-8 lg:px-9" tabindex="-1">{{ $slot }}</main>
    </div>
</div>
<noscript><style>#app-navigation{display:flex}</style></noscript>
@livewireScripts
</body></html>
