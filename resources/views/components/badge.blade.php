@props(['color' => 'gray'])

@php
    $colors = [
        'gray' => 'bg-gray-100 text-gray-700',
        'blue' => 'bg-blue-100 text-blue-700',
        'green' => 'bg-green-100 text-green-700',
        'red' => 'bg-red-100 text-red-700',
        'yellow' => 'bg-yellow-100 text-yellow-700',
        'orange' => 'bg-orange-100 text-orange-700',
        'purple' => 'bg-purple-100 text-purple-700',
        'amber' => 'bg-amber-100 text-amber-700',
        'indigo' => 'bg-indigo-100 text-indigo-700',
        'cyan' => 'bg-cyan-100 text-cyan-700',
        'teal' => 'bg-teal-100 text-teal-700',
        'emerald' => 'bg-emerald-100 text-emerald-700',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ' . ($colors[$color] ?? $colors['gray'])]) }}>
    {{ $slot }}
</span>
