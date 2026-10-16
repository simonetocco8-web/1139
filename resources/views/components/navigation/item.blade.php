@props(['href', 'icon' => 'grid'])
@php($active = request()->url() === $href)
<a href="{{ $href }}" @if($active) aria-current="page" @endif {{ $attributes->class(['flex min-h-11 items-center gap-3 rounded-xl px-3 py-2 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-teal-700', 'bg-teal-50 text-teal-950' => $active, 'text-slate-700 hover:bg-teal-50' => !$active]) }}><x-navigation.icon :name="$icon"/><span class="min-w-0 break-words">{{ $slot }}</span></a>
