{{--
    Small inline pill used for counts / labels inside panel headers
    and tables. Distinct from <x-status-badge>, which is specifically
    for job/inventory/invoice states. Use this for things like
    "30 drivers", "7 expiring", "Live".

        <x-dash.pill variant="amber">7 expiring</x-dash.pill>

    Semantic variants mirror the rest of the dash system:
      blue | green | amber | red | teal | indigo | purple | orange | slate
--}}
@props(['variant' => 'slate', 'size' => 'sm'])

@php
    $variants = [
        'blue'   => 'bg-blue-50 text-blue-700 border-blue-200',
        'green'  => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'amber'  => 'bg-amber-50 text-amber-800 border-amber-200',
        'red'    => 'bg-rose-50 text-rose-700 border-rose-200',
        'teal'   => 'bg-teal-50 text-teal-700 border-teal-200',
        'indigo' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
        'purple' => 'bg-purple-50 text-purple-700 border-purple-200',
        'orange' => 'bg-orange-50 text-orange-700 border-orange-200',
        'slate'  => 'bg-slate-100 text-slate-600 border-slate-200',
    ];
    $sizes = [
        'sm' => 'px-2 py-0.5 text-[11px]',
        'md' => 'px-2.5 py-1 text-xs',
    ];
    $variantClass = $variants[$variant] ?? $variants['slate'];
    $sizeClass = $sizes[$size] ?? $sizes['sm'];
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full border font-semibold tabular-nums', $variantClass, $sizeClass]) }}>
    {{ $slot }}
</span>
