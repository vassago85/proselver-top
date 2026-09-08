{{--
    Shared skeleton placeholder for lazy-loaded ops panels. Given
    `rows` and `tiles` it renders a neutral grid of pulse blocks so
    first paint is intentional rather than "blank until Livewire boots".
--}}
<div class="grid gap-3 {{ $tiles > 1 ? 'sm:grid-cols-'.min($tiles, 5) : '' }}">
    @for($r = 0; $r < $rows; $r++)
        @for($t = 0; $t < $tiles; $t++)
            <div class="ow-card px-4 py-4 animate-pulse">
                <div class="h-2.5 w-24 rounded bg-slate-200"></div>
                <div class="mt-3 h-6 w-14 rounded bg-slate-200"></div>
            </div>
        @endfor
    @endfor
</div>
