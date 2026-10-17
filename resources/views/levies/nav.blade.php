<nav aria-label="Canoni" class="mb-6 flex flex-wrap gap-3 text-sm font-semibold text-teal-900">
<a class="min-h-11 rounded-lg bg-teal-50 px-4 py-3" href="{{ route('levies.index') }}"><x-navigation.icon name="home" class="mr-2 inline-block align-middle"/>Dashboard</a>
<a class="min-h-11 rounded-lg bg-teal-50 px-4 py-3" href="{{ route('levies.conditions') }}"><x-navigation.icon name="settings" class="mr-2 inline-block align-middle"/>Condizioni e deroghe</a>
@if(app(\App\Domains\Identity\Authorization\TenantAccess::class)->allows(\App\Domains\Identity\VerifiedSession::user(), $lodge->id, 'reports'))
<a class="min-h-11 rounded-lg bg-teal-50 px-4 py-3" href="{{ route('levies.schedule','scadenze') }}"><x-navigation.icon name="clock" class="mr-2 inline-block align-middle"/>Scadenzario</a>
<a class="min-h-11 rounded-lg bg-teal-50 px-4 py-3" href="{{ route('levies.history') }}"><x-navigation.icon name="wallet" class="mr-2 inline-block align-middle"/>Storico pagamenti</a>@endif
</nav>