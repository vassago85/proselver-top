@props(['active' => false])

<li>
    <a {{ $attributes->merge([
        'class' => ($active
            ? 'bg-blue-800 text-white'
            : 'text-blue-200 hover:bg-blue-800 hover:text-white')
            . ' group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6'
    ]) }}>
        @isset($icon)
            <span class="h-5 w-5 shrink-0 {{ $active ? 'text-white' : 'text-blue-300 group-hover:text-white' }}">{{ $icon }}</span>
        @endisset
        {{ $slot }}
    </a>
</li>
