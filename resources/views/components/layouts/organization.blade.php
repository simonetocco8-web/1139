<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><title>Organizzazione · Gestionale Multi-Loggia</title>
    @vite(['resources/css/app.css', 'resources/js/app.js']) @livewireStyles
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900">
<a class="sr-only focus:not-sr-only focus:fixed focus:z-50 focus:bg-white focus:p-4" href="#contenuto">Vai al contenuto</a>
<div class="lg:grid lg:min-h-screen lg:grid-cols-[16rem_1fr]">
    <aside class="border-b border-slate-200 bg-white p-5 lg:border-r lg:border-b-0 lg:p-7">
        <a href="/" class="flex min-h-12 items-center gap-3 rounded font-semibold focus-visible:outline-2 focus-visible:outline-teal-700"><span class="flex size-10 items-center justify-center rounded-xl bg-teal-800 text-xl text-white" aria-hidden="true">M</span>Multi-Loggia</a>
        <nav aria-label="Amministrazione" class="mt-6 flex flex-wrap gap-2 lg:flex-col">
        @can('manage-organization')
            @foreach(['obediences' => 'Obbedienze', 'rites' => 'Riti', 'structures' => 'Sovrastrutture', 'lodges' => 'Logge'] as $key => $label)
                <a href="{{ route('organization.index', $key) }}" @if(request()->route('kind') === $key) aria-current="page" @endif class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold hover:bg-teal-50 focus-visible:outline-2 focus-visible:outline-teal-700 {{ request()->route('kind') === $key ? 'bg-teal-50 text-teal-900' : 'text-slate-700' }}">{{ $label }}</a>
            @endforeach
        @endcan
            <a href="{{ route('lodge.select') }}" class="flex min-h-12 items-center rounded-xl px-4 text-sm font-semibold text-slate-700 hover:bg-teal-50">Loggia attiva</a>
        </nav>
        <p class="mt-6 text-sm leading-6 text-slate-600">Gli accessi alle singole logge restano separati dai permessi della piattaforma.</p>
    </aside>
    <div class="min-w-0">
        <header class="border-b border-slate-200 bg-white px-5 py-4 lg:px-9"><span class="text-sm font-semibold text-slate-600">Area riservata · Organizzazione</span></header>
        <main id="contenuto" class="mx-auto max-w-6xl px-5 py-8 lg:px-9" tabindex="-1">{{ $slot }}</main>
    </div>
</div>
@livewireScripts
</body></html>
