<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="color-scheme" content="light">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
<x-favicon/></head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
<a href="#contenuto" class="sr-only focus:not-sr-only focus:fixed focus:z-50 focus:rounded focus:bg-white focus:p-4">Vai al contenuto</a>
<div class="min-h-screen lg:grid lg:grid-cols-[17rem_1fr]">
    <aside class="border-b border-slate-200 bg-white p-5 lg:border-r lg:border-b-0 lg:p-7" aria-label="Navigazione principale">
        <a href="{{ route('home') }}" class="flex min-h-12 items-center gap-3 rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-700">
            <span aria-hidden="true" class="flex size-11 items-center justify-center rounded-xl bg-teal-800 text-xl font-bold text-white">M</span>
            <span class="font-semibold">Gestionale<br><span class="text-sm font-normal text-slate-600">Multi-Loggia</span></span>
        </a>
        <nav class="mt-5 lg:mt-12">
            <a href="{{ route('home') }}" aria-current="page" class="flex min-h-12 items-center rounded-xl bg-teal-50 px-4 font-semibold text-teal-900 focus-visible:outline-2 focus-visible:outline-teal-700">Panoramica</a>
        </nav>
        <p class="mt-5 text-sm leading-6 text-slate-600 lg:mt-12">Uno spazio amministrativo ordinato, progettato per ogni loggia.</p>
    </aside>
    <div class="min-w-0">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4 lg:px-10">
            <span class="text-sm font-medium text-slate-600">Area di presentazione</span>
            <span class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-sm text-amber-900">Configurazione iniziale</span>
        </header>
        <main id="contenuto" class="mx-auto max-w-6xl px-5 py-9 lg:px-10 lg:py-14" tabindex="-1">
            {{ $slot }}
        </main>
        <footer class="px-5 pb-7 text-sm text-slate-600 lg:px-10">Accesso riservato · Nessuna registrazione pubblica</footer>
    </div>
</div>
@livewireScripts
</body>
</html>
