@props(['label', 'value', 'color' => 'blue', 'href' => null])

@php
    $colors = [
        'blue' => 'bg-blue-50 text-blue-700',
        'green' => 'bg-green-50 text-green-700',
        'red' => 'bg-red-50 text-red-700',
        'yellow' => 'bg-yellow-50 text-yellow-700',
        'gray' => 'bg-gray-50 text-gray-700',
    ];
    $colorClass = $colors[$color] ?? $colors['blue'];
@endphp

@if($href)
<a href="{{ $href }}" class="block rounded-xl border border-gray-200 bg-white p-6 shadow-sm hover:shadow-md transition-shadow">
@else
<div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
@endif
    <dt class="text-sm font-medium text-gray-500 truncate">{{ $label }}</dt>
    <dd class="mt-2 flex items-baseline gap-x-2">
        <span class="text-3xl font-bold tracking-tight {{ $colorClass }} px-2 py-0.5 rounded-lg">{{ $value }}</span>
    </dd>
@if($href)
</a>
@else
</div>
@endif
