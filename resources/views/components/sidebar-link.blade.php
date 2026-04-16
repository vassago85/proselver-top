@props(['active' => false])

<li class="relative">
    <a {{ $attributes->merge([
        'class' => ($active
            ? 'bg-blue-50 text-blue-700'
            : 'text-slate-700 hover:bg-slate-50 hover:text-slate-900')
            . ' group relative flex items-center gap-x-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors'
    ]) }}>
        @if($active)
            <span class="absolute left-0 top-1/2 -translate-y-1/2 h-5 w-0.5 rounded-r-full bg-blue-600"></span>
        @endif
        @isset($icon)
            <span class="{{ $active ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }} transition-colors shrink-0">{{ $icon }}</span>
        @endisset
        <span class="truncate">{{ $slot }}</span>
    </a>
</li>
