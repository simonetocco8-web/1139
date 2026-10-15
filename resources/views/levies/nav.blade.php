<nav aria-label="Canoni" class="mb-6 flex flex-wrap gap-3 text-sm font-semibold text-teal-900">
<a class="min-h-11 rounded-lg bg-teal-50 px-4 py-3" href="{{ route('levies.index') }}">Dashboard</a>
<a class="min-h-11 rounded-lg bg-teal-50 px-4 py-3" href="{{ route('levies.conditions') }}">Condizioni e deroghe</a>
@if(app(\App\Domains\Identity\Authorization\TenantAccess::class)->allows(\App\Domains\Identity\VerifiedSession::user(), $lodge->id, 'reports'))
<a class="min-h-11 rounded-lg bg-teal-50 px-4 py-3" href="{{ route('levies.schedule','scadenze') }}">Scadenzario</a>
<a class="min-h-11 rounded-lg bg-teal-50 px-4 py-3" href="{{ route('levies.history') }}">Storico pagamenti</a>@endif
</nav>