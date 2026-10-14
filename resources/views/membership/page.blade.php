<x-layouts.organization>
<div class="mb-6"><p class="text-sm font-semibold uppercase tracking-wide text-teal-800">{{ $lodge->name }}</p><h1 class="mt-2 break-words text-3xl font-bold">@yield('title')</h1><nav class="mt-4 flex flex-wrap gap-4 text-sm font-semibold"><a class="text-teal-900 underline" href="{{ route('lodge.select') }}">Cambia loggia</a><a class="text-teal-900 underline" href="{{ route('members.position') }}">La mia posizione</a>@if(app(\App\Domains\Identity\Authorization\TenantAccess::class)->fullRead(\App\Domains\Identity\VerifiedSession::user(), $lodge->id))<a class="text-teal-900 underline" href="{{ route('members.index') }}">Elenco fratelli</a>@endif</nav></div>
@if(session('status'))<p role="status" class="mb-5 rounded-xl bg-teal-50 p-4 text-teal-900">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4"><p class="font-semibold">Controlla i dati inseriti</p><ul class="mt-2 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="min-w-0 [overflow-wrap:anywhere]">@yield('content')</div>
</x-layouts.organization>
