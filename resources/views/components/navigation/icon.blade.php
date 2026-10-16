@props(['name' => 'grid'])
@php($icons = [
'home' => ['M3 10 12 3l9 7','M5 9v12h5v-7h4v7h5V9'],
'users' => ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2','M16 4a4 4 0 0 1 0 8','M22 21v-2a4 4 0 0 0-3-3.87','M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0'],
'wallet' => ['M3 5h16v4H3z','M3 9v11h18V9','M16 13h5v4h-5z'],
'building' => ['M3 10h18L12 3 3 10Z','M5 10v10m7-10v10m7-10v10M2 21h20'],
'coins' => ['M4 7c0-4 16-4 16 0s-16 4-16 0Z','M4 7v5c0 4 16 4 16 0V7','M4 12v5c0 4 16 4 16 0v-5'],
'key' => ['M14 8a5 5 0 1 1-4 8L3 23l-2-2 7-7a5 5 0 0 1 6-6Z','M17 10h.01'],
'settings' => ['M12 3v3m0 12v3M3 12h3m12 0h3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1M5.6 18.4l2.1-2.1m8.6-8.6 2.1-2.1','M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0'],
'shield' => ['M12 3 3 7v5c0 5 9 9 9 9s9-4 9-9V7l-9-4Z','m8 12 3 3 5-6'],
'user' => ['M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0','M4 21v-2a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v2'],
'bell' => ['M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9','M10 21h4'],
'chart' => ['M3 3v18h18','M7 17v-6m5 6V6m5 11v-9'],
'clipboard' => ['M9 4H5v18h14V4h-4','M9 2h6v5H9z','M8 12h8m-8 5h8'],
'logout' => ['M9 3H3v18h6','M9 12h12m-4-4 4 4-4 4'],
'grid' => ['M3 3h7v7H3zm11 0h7v7h-7zM3 14h7v7H3zm11 0h7v7h-7z'],
'mail' => ['M3 5h18v14H3z','m3 5 9 7 9-7'],
'help' => ['M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0','M9 9a3 3 0 1 1 5 2c-2 1-2 1-2 3m0 3h.01'],
'chevron' => ['m6 9 6 6 6-6'],
'clock' => ['M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0','M12 7v5l3 2'],
])
<svg {{ $attributes->class(['size-5 shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">@foreach($icons[$name] ?? $icons['grid'] as $path)<path d="{{ $path }}"/>@endforeach</svg>
