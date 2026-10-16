<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><title>Gestionale Multi-Loggia · Area riservata</title>
    @vite(['resources/css/app.css', 'resources/js/app.js']) @livewireStyles
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900">
<a class="sr-only focus:not-sr-only focus:fixed focus:z-50 focus:bg-white focus:p-4" href="#contenuto">Vai al contenuto</a>
@php
$user = \App\Domains\Identity\VerifiedSession::user();
$activeContext = app(\App\Domains\Organization\Actions\ActiveLodge::class);
$currentLodge = $activeContext->current();
$access = app(\App\Domains\Identity\Authorization\TenantAccess::class);
$staff = $currentLodge && $access->fullRead($user, $currentLodge->id);
$finance = $staff && $access->allows($user, $currentLodge->id, 'financial.read');
$canAdmin = $currentLodge && $access->allows($user, $currentLodge->id, 'administration');
$canReport = $staff && $access->allows($user, $currentLodge->id, 'reports');
@endphp
<div class="lg:grid lg:min-h-screen lg:grid-cols-[17rem_1fr]">
    <aside class="border-b border-slate-200 bg-white p-4 lg:border-r lg:border-b-0 lg:p-5">
        <div class="flex items-center justify-between gap-3"><a href="{{ route('dashboard') }}" class="flex min-h-12 items-center gap-3 rounded font-semibold focus-visible:outline-2 focus-visible:outline-teal-700"><span class="flex size-10 items-center justify-center rounded-xl bg-teal-800 text-xl text-white" aria-hidden="true">M</span>Multi-Loggia</a><button type="button" data-menu-toggle hidden aria-controls="app-navigation" aria-expanded="false" class="min-h-11 rounded-xl border border-slate-300 px-4 text-sm font-semibold lg:hidden">Menu</button></div>
        <nav id="app-navigation" aria-label="Navigazione del gestionale" class="mt-6 hidden flex-col gap-5 lg:flex">
        @can('manage-organization')
            <section aria-labelledby="platform-navigation"><h2 id="platform-navigation" class="mb-2 px-3 text-xs font-bold uppercase tracking-wide text-slate-500">Sezione Piattaforma</h2>
                <x-navigation.item :href="route('dashboard')" icon="grid">Panoramica Piattaforma</x-navigation.item>
                @foreach(['obediences' => 'Obbedienze', 'rites' => 'Riti', 'structures' => 'Sovrastrutture', 'lodges' => 'Logge'] as $key => $label)
                    <x-navigation.item :href="route('organization.index', $key)" icon="building">{{ $label }}</x-navigation.item>
                @endforeach
                <x-navigation.item :href="route('platform.invites')" icon="mail">Inviti Piattaforma</x-navigation.item>
                <x-navigation.item :href="route('assistance')" icon="help">Assistenza</x-navigation.item>
                <x-navigation.item :href="route('levies.platform')" icon="coins">Regole Generali Canoni</x-navigation.item>
                <x-navigation.item :href="route('communications.platform')" icon="bell">Notifiche Piattaforma</x-navigation.item>
                @if($activeContext->canChoose())<x-navigation.item :href="route('lodge.select')" icon="building">Loggia autorizzata</x-navigation.item>@endif
            </section>
        @endcan
        <section aria-labelledby="lodge-navigation"><h2 id="lodge-navigation" class="mb-2 px-3 text-xs font-bold uppercase tracking-wide text-slate-500">Sezione Loggia</h2>
            @if($currentLodge)
                <x-navigation.item :href="route('reports.dashboard', $currentLodge->id)" icon="home">Panoramica Loggia</x-navigation.item>
                @if($staff)
                    <x-navigation.item :href="route('members.index')" icon="users">Fratelli della Loggia</x-navigation.item>
                    @endif
                @if($finance)
                    <x-navigation.item :href="route('treasury.index')" icon="wallet">Tesoreria</x-navigation.item>
                    <x-navigation.item :href="route('levies.index')" icon="building">Canoni Sovrastruttura</x-navigation.item>
                    <x-navigation.item :href="route('capitations.index')" icon="coins">Capitazioni</x-navigation.item>
                @elseif(\App\Domains\Organization\Models\Lodge::query()->whereKey($currentLodge->id)->where('brothers_treasury_visible',true)->exists())
                    <x-navigation.item :href="route('treasury.summary')" icon="wallet">Tesoreria</x-navigation.item>
                @endif
                @if($canAdmin)<x-navigation.item :href="route('tenant.access')" icon="key">Accessi della Loggia</x-navigation.item>@endif
                <div>
                    <div class="flex items-center gap-1">
                        @if($canAdmin)<x-navigation.item :href="route('members.settings')" icon="settings" class="flex-1">Impostazioni della Loggia</x-navigation.item>
                        @else<div class="flex min-h-11 flex-1 items-center gap-3 px-3 py-2 text-sm font-semibold text-slate-700"><x-navigation.icon name="settings"/><span>Impostazioni della Loggia</span></div>@endif
                        <button type="button" data-submenu-toggle hidden aria-controls="lodge-settings-navigation" aria-expanded="true" aria-label="Mostra o nascondi le impostazioni della loggia" class="flex size-11 shrink-0 items-center justify-center rounded-lg hover:bg-teal-50 focus-visible:outline-2 focus-visible:outline-teal-700"><x-navigation.icon name="chevron"/></button>
                    </div>
                    <div id="lodge-settings-navigation" class="ml-5 border-l border-slate-200 pl-2">
                        <x-navigation.item :href="route('account.security')" icon="shield">Sicurezza Account</x-navigation.item>
                        <x-navigation.item :href="route('privacy.index',$currentLodge->id)" icon="user">Dati Personali e Privacy (GDPR)</x-navigation.item>
                        <x-navigation.item :href="route('communications.index',$currentLodge->id)" icon="bell">Notifiche</x-navigation.item>
                    </div>
                </div>
                @if($canReport)
                    <x-navigation.item :href="route('reports.show',[$currentLodge->id,'financial'])" icon="chart">Report Gestionali</x-navigation.item>
                    <x-navigation.item :href="route('tenant.report')" icon="clipboard">Report Accessi</x-navigation.item>
                @endif
            @else<p class="px-3 py-2 text-sm text-slate-600">Nessuna loggia attiva.</p>
                <x-navigation.item :href="route('account.security')" icon="shield">Sicurezza Account</x-navigation.item>
            @endif
        </section>
        <section aria-labelledby="personal-navigation"><h2 id="personal-navigation" class="mb-2 px-3 text-xs font-bold uppercase tracking-wide text-slate-500">Sezione Personale</h2>
            @if($currentLodge)<x-navigation.item :href="route('members.position')" icon="user">La mia posizione</x-navigation.item>@else<p class="px-3 py-2 text-sm text-slate-600">Nessuna posizione associata.</p>@endif
        </section>
        </nav>
    </aside>
    <div class="min-w-0">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4 lg:px-9">
            <span class="text-sm font-semibold text-slate-600">Area riservata · Gestionale</span>
            <div class="flex flex-wrap items-center gap-3">
                <div data-session-clock data-session-key="{{ hash('sha256', session()->getId()) }}" data-expires-at="{{ session('auth.last_activity_at',session('auth.second_factor_verified_at')) + (int)config('session.lifetime',30)*60 }}" data-server-now="{{ now('UTC')->getTimestamp() }}" class="flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm"><x-navigation.icon name="clock"/><span>Sessione</span><strong data-session-remaining role="timer" aria-label="Tempo rimanente della sessione" aria-live="off" class="tabular-nums">--:--</strong><span data-session-expired hidden>Scaduta</span></div>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-semibold text-teal-900 underline"><x-navigation.icon name="logout"/>Esci</button></form>
            </div>
        </header>
        <main id="contenuto" class="mx-auto max-w-6xl px-5 py-8 lg:px-9" tabindex="-1">{{ $slot }}</main>
    </div>
</div>
<noscript><style>#app-navigation{display:flex}</style></noscript>
@livewireScripts
</body></html>
