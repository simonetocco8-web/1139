<div>
    <p class="text-sm font-semibold tracking-wide text-teal-800">IL TUO AMBIENTE</p><h1 class="mt-2 text-3xl font-semibold">Loggia attiva</h1>
    <p class="mt-3 text-sm leading-6 text-slate-600">Seleziona una delle logge per cui disponi di un’autorizzazione attiva.</p>
    @if(session('organization_message'))<p role="status" class="mt-5 rounded-xl bg-teal-50 p-4 text-teal-950">{{ session('organization_message') }}</p>@endif
    @if($current)<p class="mt-5 rounded-xl border border-teal-200 bg-teal-50 p-5">Ambiente selezionato: <strong>{{ $current->name }}</strong></p>@endif
    <ul class="mt-6 grid gap-4 sm:grid-cols-2">@forelse($lodges as $lodge)<li class="rounded-2xl border border-slate-200 bg-white p-5"><h2 class="font-semibold">{{ $lodge->name }}</h2><p class="mt-2 text-sm text-slate-600">Numero {{ $lodge->number }}</p><button wire:click="select({{ $lodge->id }})" class="mt-5 min-h-12 rounded-xl bg-teal-800 px-5 font-semibold text-white">{{ $current?->id === $lodge->id ? 'Selezionata' : 'Seleziona' }}</button></li>@empty<li class="rounded-2xl border border-dashed border-slate-300 p-6 text-slate-600 sm:col-span-2">Nessuna loggia autorizzata disponibile. Rivolgiti all’amministratore per verificare i tuoi accessi.</li>@endforelse</ul>
    <p class="mt-6 text-sm text-slate-600">La selezione predispone l’ambiente. Anagrafiche e moduli contabili non sono ancora disponibili.</p>
</div>
