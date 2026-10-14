<dl class="mt-4 grid gap-4 sm:grid-cols-3">
    <div class="rounded-2xl border border-slate-200 bg-white p-6"><dt class="text-sm text-slate-600">Lingua</dt><dd class="mt-2 text-lg font-semibold">Italiano</dd></div>
    <div class="rounded-2xl border border-slate-200 bg-white p-6"><dt class="text-sm text-slate-600">Fuso orario</dt><dd class="mt-2 text-lg font-semibold">{{ config('app.timezone') }}</dd></div>
    <div class="rounded-2xl border border-slate-200 bg-white p-6"><dt class="text-sm text-slate-600">Valuta</dt><dd class="mt-2 text-lg font-semibold">Euro · {{ config('platform.currency') }}</dd></div>
</dl>
