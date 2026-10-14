<x-layouts.foundation>
    <p class="text-sm font-semibold tracking-wide text-teal-800">GESTIONALE MULTI-LOGGIA</p>
    <h1 class="mt-3 max-w-2xl text-3xl font-semibold leading-tight tracking-tight sm:text-4xl">Una base comune.<br>Uno spazio per ogni loggia.</h1>
    <p class="mt-5 max-w-2xl text-base leading-7 text-slate-600">Ogni loggia dispone di un ambiente amministrativo separato, con accessi dedicati e informazioni protette.</p>
    <section class="mt-9 rounded-2xl border border-teal-200 bg-teal-50 p-6 sm:p-8" aria-labelledby="accesso-title">
        <h2 id="accesso-title" class="text-lg font-semibold text-teal-950">Accesso tramite invito</h2>
        <p class="mt-2 max-w-2xl leading-7 text-teal-900">Accedi con email, password e un secondo codice di verifica sul canale configurato. Gli account vengono attivati esclusivamente tramite invito.</p>
        <a href="{{ route('login') }}" class="mt-5 inline-flex min-h-12 items-center rounded-xl bg-teal-800 px-5 font-semibold text-white">Accedi</a>
    </section>
    <section class="mt-10" aria-labelledby="ambiente-title">
        <h2 id="ambiente-title" class="text-xl font-semibold">Impostazioni dell’ambiente</h2>
        <livewire:foundation-status />
    </section>
    <section class="mt-9 rounded-2xl border border-slate-200 bg-white p-6" aria-labelledby="prossimi-title">
        <h2 id="prossimi-title" class="font-semibold">Le prossime funzionalità</h2>
        <p class="mt-2 leading-7 text-slate-600">Anagrafiche, capitazioni, canoni e tesoreria saranno introdotti nelle fasi successive. Questa pagina non contiene dati di logge o iscritti.</p>
    </section>
</x-layouts.foundation>
