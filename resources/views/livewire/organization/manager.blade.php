<div>
    @php($titles = ['obediences' => 'Obbedienze', 'rites' => 'Riti', 'structures' => 'Sovrastrutture', 'lodges' => 'Logge'])
    @php($levels = ['national' => 'Nazionale', 'regional' => 'Regionale', 'provincial' => 'Provinciale'])
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div><p class="text-sm font-semibold tracking-wide text-teal-800">STRUTTURA ORGANIZZATIVA</p><h1 class="mt-2 text-3xl font-semibold">{{ $titles[$kind] }}</h1></div>
        <button wire:click="create" class="min-h-12 rounded-xl bg-teal-800 px-5 font-semibold text-white hover:bg-teal-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700">Aggiungi</button>
    </div>
    <p class="mt-3 text-sm leading-6 text-slate-600">Gestisci i collegamenti e gli stati. La disattivazione conserva i dati e lo storico.</p>
    @if(session('organization_message'))<p role="status" class="mt-5 rounded-xl bg-teal-50 p-4 text-teal-950">{{ session('organization_message') }}</p>@endif
    @if($showForm)
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 sm:p-7" aria-labelledby="form-title">
        <h2 id="form-title" class="text-xl font-semibold">{{ $editingId ? 'Modifica' : 'Nuova voce' }}</h2>
        <form wire:submit="save" class="mt-5">
            @if($errors->any())<div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-red-900"><p class="font-semibold">Controlla i dati inseriti.</p><ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="grid gap-2 text-sm font-medium">Denominazione *<input wire:model="form.name" required maxlength="160" class="org-input"></label>
                @if(in_array($kind, ['obediences', 'rites']))
                    <label class="grid gap-2 text-sm font-medium">Identificativo *<input wire:model="form.code" required maxlength="40" class="org-input"><span class="font-normal text-slate-600">Lettere, numeri, trattino e underscore.</span></label>
                @endif
                @if(in_array($kind, ['structures', 'lodges']))
                    <label class="grid gap-2 text-sm font-medium">Obbedienza *<select wire:model.live="form.obedience_id" required @disabled($editingId !== null) class="org-input"><option value="">Seleziona</option>@foreach($obediences as $o)<option value="{{ $o->id }}">{{ $o->name }}{{ $o->is_active ? '' : ' · inattiva' }}</option>@endforeach</select>@if($editingId)<span class="font-normal text-slate-600">L’obbedienza rimane stabile per preservare lo storico.</span>@endif</label>
                @endif
                @if($kind === 'structures')
                    <label class="grid gap-2 text-sm font-medium">Livello *<select wire:model.live="form.level" @disabled($editingId !== null) class="org-input">@foreach($levels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                    <label class="grid gap-2 text-sm font-medium">Struttura genitore<select wire:model="form.parent_id" class="org-input"><option value="">Nessuna — livello autonomo</option>@foreach($structures as $s)@if($s->id !== $editingId && \App\Domains\Organization\Actions\OrganizationActions::LEVELS[$s->level] < \App\Domains\Organization\Actions\OrganizationActions::LEVELS[$form['level']])<option value="{{ $s->id }}">{{ $s->name }} · {{ $levels[$s->level] }}{{ $s->is_active ? '' : ' · inattiva' }}</option>@endif @endforeach</select><span class="font-normal text-slate-600">Non è necessario avere tutti i livelli intermedi.</span></label>
                @endif
                @if($kind === 'lodges')
                    <label class="grid gap-2 text-sm font-medium">Numero nell’obbedienza *<input wire:model="form.number" required maxlength="40" class="org-input"></label>
                    <label class="grid gap-2 text-sm font-medium">Rito compatibile *<select wire:model="form.rite_id" required class="org-input"><option value="">Seleziona</option>@foreach($rites as $r)<option value="{{ $r->id }}">{{ $r->name }}{{ $r->is_active ? '' : ' · inattivo' }}</option>@endforeach</select></label>
                @endif
                @if($kind !== 'rites')
                    <label class="grid gap-2 text-sm font-medium">Email<input type="email" wire:model="form.email" maxlength="255" class="org-input"></label>
                    <label class="grid gap-2 text-sm font-medium">Telefono<input type="tel" wire:model="form.phone" maxlength="40" class="org-input"></label>
                    <label class="grid gap-2 text-sm font-medium">Indirizzo<input wire:model="form.address" maxlength="255" class="org-input"></label>
                @endif
                @if(in_array($kind, ['structures', 'lodges']))<label class="grid gap-2 text-sm font-medium">IBAN<input wire:model="form.iban" maxlength="34" class="org-input"></label>@endif
                <label class="flex min-h-12 items-center gap-3 text-sm font-medium"><input type="checkbox" wire:model="form.is_active" class="size-5 accent-teal-800">Voce attiva</label>
            </div>
            @if($kind === 'obediences')
                <fieldset class="mt-6"><legend class="font-semibold">Riti compatibili</legend><div class="mt-3 grid gap-2 sm:grid-cols-2">@forelse($rites as $r)<label class="flex min-h-12 items-center gap-3 rounded-xl border border-slate-200 px-4 text-sm"><input type="checkbox" wire:model="form.rite_ids" value="{{ $r->id }}" class="size-5 accent-teal-800">{{ $r->name }}{{ $r->is_active ? '' : ' · inattivo' }}</label>@empty<p class="text-sm text-slate-600">Crea prima un rito nella sezione Riti.</p>@endforelse</div></fieldset>
            @endif
            @if($kind === 'lodges')
                <fieldset class="mt-6"><legend class="font-semibold">Collegamenti territoriali facoltativi</legend><p class="mt-2 text-sm text-slate-600">Al massimo una struttura per livello, nella stessa catena gerarchica. I collegamenti precedenti rimangono nello storico.</p><div class="mt-3 grid gap-2 sm:grid-cols-2">@forelse($structures as $s)<label class="flex min-h-12 items-center gap-3 rounded-xl border border-slate-200 px-4 text-sm"><input type="checkbox" wire:model="form.structure_ids" value="{{ $s->id }}" class="size-5 accent-teal-800"><span>{{ $s->name }}<span class="block text-xs text-slate-600">{{ $levels[$s->level] }}{{ $s->is_active ? '' : ' · inattiva' }}</span></span></label>@empty<p class="text-sm text-slate-600">Nessuna struttura per questa obbedienza. La loggia può restare senza collegamenti.</p>@endforelse</div></fieldset>
            @endif
            <div class="mt-7 flex flex-wrap gap-3"><button type="submit" wire:loading.attr="disabled" wire:target="save" wire:confirm="Confermi il salvataggio dei dati e dei collegamenti?" class="min-h-12 rounded-xl bg-teal-800 px-6 font-semibold text-white disabled:opacity-60">Salva</button><button type="button" wire:click="cancel" class="min-h-12 rounded-xl border border-slate-300 px-5 font-semibold">Annulla</button></div>
        </form>
        @if($history->isNotEmpty())
        <section class="mt-7 border-t border-slate-200 pt-5"><h3 class="font-semibold">Storico dei collegamenti territoriali</h3><ul class="mt-3 space-y-3">@foreach($history as $assignment)<li class="rounded-xl bg-slate-50 p-4 text-sm"><strong>{{ $assignment->structure_name }}</strong> · {{ $levels[$assignment->level] }}<span class="mt-1 block text-slate-600">Dal {{ $assignment->starts_at->timezone('Europe/Rome')->format('d/m/Y H:i') }} · {{ $assignment->ended_at ? 'al '.$assignment->ended_at->timezone('Europe/Rome')->format('d/m/Y H:i') : 'Collegamento attuale' }}</span></li>@endforeach</ul></section>
        @endif
    </section>
    @endif
    <div class="mt-7 flex flex-wrap gap-4"><label class="grid flex-1 gap-2 text-sm font-medium">Ricerca<input type="search" wire:model.live.debounce.300ms="search" maxlength="160" placeholder="Denominazione o identificativo" class="org-input min-w-0"></label><label class="grid gap-2 text-sm font-medium">Stato<select wire:model.live="status" class="org-input"><option value="all">Tutti</option><option value="active">Attivi</option><option value="inactive">Inattivi</option></select></label></div>
    <ul class="mt-5 space-y-3" aria-label="Elenco {{ $titles[$kind] }}">
    @forelse($records as $record)
        <li wire:key="{{ $kind }}-{{ $record->id }}" class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-5">
            <div class="min-w-0 flex-1"><h2 class="break-words font-semibold">{{ $record->name }}</h2><p class="mt-1 text-sm text-slate-600">@if(in_array($kind, ['obediences', 'rites'])){{ $record->code }}@else{{ $record->obedience->name }}@endif @if($kind === 'structures') · {{ $levels[$record->level] }} · {{ $record->parent?->name ?? 'Nessun genitore' }}@endif @if($kind === 'lodges') · N. {{ $record->number }} · {{ $record->rite->name }}@endif</p></div>
            <span class="rounded-full px-3 py-1 text-sm {{ $record->is_active ? 'bg-teal-50 text-teal-900' : 'bg-slate-100 text-slate-700' }}">{{ $record->is_active ? 'Attiva' : 'Inattiva' }}</span>
            <div class="flex flex-wrap gap-2"><button wire:click="edit({{ $record->id }})" class="min-h-12 rounded-xl border border-slate-300 px-4 text-sm font-semibold">Modifica<span class="sr-only"> {{ $record->name }}</span></button><button wire:click="setActive({{ $record->id }}, {{ $record->is_active ? 'false' : 'true' }})" wire:confirm="Confermi {{ $record->is_active ? 'la disattivazione' : 'la riattivazione' }}? I collegamenti e lo storico saranno conservati." class="min-h-12 rounded-xl px-4 text-sm font-semibold text-teal-900 underline">{{ $record->is_active ? 'Disattiva' : 'Riattiva' }}<span class="sr-only"> {{ $record->name }}</span></button></div>
        </li>
    @empty<li class="rounded-2xl border border-dashed border-slate-300 p-7 text-slate-600">Nessun risultato. Modifica i filtri oppure aggiungi una nuova voce.</li>@endforelse
    </ul><div class="mt-5">{{ $records->links() }}</div>
</div>
