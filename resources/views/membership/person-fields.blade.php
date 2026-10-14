<div class="grid gap-4 sm:grid-cols-2">
@foreach(['reference' => ['Codice persona', 'text', 40], 'first_name' => ['Nome', 'text', 120], 'last_name' => ['Cognome', 'text', 120], 'birth_date' => ['Data di nascita (facoltativa)', 'date', null], 'email' => ['Email anagrafica', 'email', 255], 'phone' => ['Telefono anagrafico', 'text', 40], 'address' => ['Indirizzo', 'text', 255]] as $key => [$label, $type, $max])
<label class="block text-sm font-semibold">{{ $label }}<input class="mt-2 min-h-12 w-full min-w-0 rounded-xl border border-slate-300 px-3 font-normal" name="{{ $key }}" type="{{ $type }}" value="{{ old($key, isset($person) ? ($key === 'birth_date' ? $person->birth_date?->format('Y-m-d') : $person->{$key}) : '') }}" @if($max) maxlength="{{ $max }}" @endif @if(in_array($key, ['reference','first_name','last_name'])) required @endif></label>
@endforeach
</div>
