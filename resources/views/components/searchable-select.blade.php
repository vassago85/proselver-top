@props([
    /*
     * Options accepts two shapes:
     *
     *   FLAT:    [['value' => 1, 'label' => 'Foo'], ...]
     *
     *   GROUPED: [['label' => 'My Locations', 'options' => [['value' => 1, 'label' => 'Foo']]], ...]
     *
     * Plain associative arrays (['1' => 'Foo']) and Eloquent
     * collections of ['id', 'name'] objects also work via the
     * normaliser below.
     */
    'options' => [],
    'placeholder' => 'Select...',
    'searchPlaceholder' => 'Type to search…',
    'emptyText' => 'No matches.',
    'allowClear' => true,
    'disabled' => false,
    'name' => null,
    'required' => false,
    'id' => null,
])

@php
    /*
     * Normalise the options into a single shape Alpine can iterate.
     * We always end up with a list of groups, each containing options.
     * Flat input becomes one anonymous group.
     */
    $normalise = function ($items) {
        $out = [];
        foreach ($items as $key => $item) {
            if (is_array($item) && array_key_exists('value', $item) && array_key_exists('label', $item)) {
                $out[] = ['value' => (string) $item['value'], 'label' => (string) $item['label']];
                continue;
            }
            if (is_object($item)) {
                $out[] = [
                    'value' => (string) ($item->id ?? $item->value ?? $key),
                    'label' => (string) ($item->name ?? $item->label ?? $item->title ?? $key),
                ];
                continue;
            }
            $out[] = ['value' => (string) $key, 'label' => (string) $item];
        }
        return $out;
    };

    $groups = [];
    if (is_array($options) || $options instanceof \Traversable) {
        $first = null;
        foreach ($options as $maybeFirst) {
            $first = $maybeFirst;
            break;
        }

        $isGrouped = is_array($first) && array_key_exists('options', $first);

        if ($isGrouped) {
            foreach ($options as $group) {
                $groups[] = [
                    'label' => (string) ($group['label'] ?? ''),
                    'options' => $normalise($group['options'] ?? []),
                ];
            }
        } else {
            $groups[] = ['label' => '', 'options' => $normalise($options)];
        }
    }

    $componentId = $id ?? 'searchable-select-' . \Illuminate\Support\Str::random(8);

    /*
     * Pull any wire:model* attribute off the outer element so we can
     * forward it onto the hidden <input>. That lets parent code write
     *   <x-searchable-select wire:model="pickupLocationId" ... />
     * and have changes flow into Livewire normally.
     */
    $wireAttrs = collect($attributes->getAttributes())
        ->filter(fn ($_, $key) => str_starts_with($key, 'wire:model'))
        ->all();
    $wireAttrString = '';
    foreach ($wireAttrs as $k => $v) {
        $wireAttrString .= ' ' . $k . '="' . e((string) $v) . '"';
    }
    $outer = $attributes->except(array_keys($wireAttrs));
@endphp

<div
    {{ $outer->merge(['class' => 'relative w-full']) }}
    x-data="searchableSelect({
        groups: @js($groups),
        initial: $refs.hiddenInput?.value ?? '',
        placeholder: @js($placeholder),
        emptyText: @js($emptyText),
        allowClear: @js((bool) $allowClear),
        disabled: @js((bool) $disabled),
    })"
    x-init="hydrate()"
    @keydown.escape.stop="close()"
    @click.outside="close()"
>
    <input
        type="hidden"
        x-ref="hiddenInput"
        @if($name) name="{{ $name }}" @endif
        @if($required) required @endif
        {!! $wireAttrString !!}
    />

    {{-- Combined trigger + search input.
         When closed and a value is picked, the input shows the selected
         label as its visible text. When focused/open, the user can just
         start typing to filter; results drop below.
         A single block — no separate search panel. --}}
    <div
        x-ref="trigger"
        @click="!disabled && (open ? $refs.search.focus() : openAndFocus())"
        :class="[
            'group flex w-full items-center justify-between gap-2 rounded-lg border bg-white px-3 py-2 text-sm transition cursor-text',
            disabled
                ? 'cursor-not-allowed border-gray-200 bg-gray-50 text-gray-400'
                : (open
                    ? 'border-blue-500 ring-1 ring-blue-500'
                    : 'border-gray-300 hover:border-gray-400'),
        ]"
        :aria-expanded="open ? 'true' : 'false'"
        aria-haspopup="listbox"
        role="combobox"
    >
        <input
            x-ref="search"
            type="text"
            autocomplete="off"
            :disabled="disabled"
            :placeholder="open ? (value ? label : placeholder) : (value ? label : placeholder)"
            :value="open ? query : (value ? label : '')"
            @input="query = $event.target.value; openAndFocus()"
            @focus="openAndFocus()"
            @keydown.down.prevent="open ? moveActive(1) : openAndFocus()"
            @keydown.up.prevent="open ? moveActive(-1) : openAndFocus()"
            @keydown.enter.prevent="open && active >= 0 ? selectActive() : openAndFocus()"
            @keydown.escape.stop.prevent="close()"
            class="min-w-0 flex-1 bg-transparent text-gray-900 placeholder:text-gray-400 focus:outline-none disabled:cursor-not-allowed disabled:text-gray-400"
        />
        <span class="flex shrink-0 items-center gap-1.5">
            <button
                type="button"
                x-show="allowClear && value !== '' && !disabled"
                @click.stop="clear(); $refs.search.focus()"
                class="flex h-4 w-4 items-center justify-center rounded text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                title="Clear"
            >
                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <svg class="h-4 w-4 text-gray-400 transition" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </span>
    </div>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        class="absolute z-50 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg"
    >
        <ul class="max-h-64 overflow-y-auto py-1" role="listbox">
            <template x-for="(group, gi) in filteredGroups" :key="'g' + gi">
                <li>
                    <div
                        x-show="group.label"
                        class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400"
                        x-text="group.label"
                    ></div>
                    <template x-for="opt in group.options" :key="opt.value">
                        <button
                            type="button"
                            @click="select(opt.value)"
                            @mouseenter="active = flatIndexFor(opt.value)"
                            :class="[
                                'flex w-full items-center justify-between px-3 py-1.5 text-left text-sm',
                                value === opt.value ? 'bg-blue-50 font-medium text-blue-700' : 'text-gray-800',
                                flatIndexFor(opt.value) === active ? 'bg-gray-100' : '',
                            ]"
                            role="option"
                        >
                            <span x-text="opt.label" class="truncate"></span>
                            <svg
                                x-show="value === opt.value"
                                class="ml-2 h-4 w-4 shrink-0 text-blue-600"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </button>
                    </template>
                </li>
            </template>

            <li
                x-show="totalFiltered === 0"
                class="px-3 py-3 text-center text-sm text-gray-500"
                x-text="emptyText"
            ></li>
        </ul>
    </div>
</div>

{{--
    The `searchableSelect` Alpine.data factory is registered in
    resources/js/app.js, NOT here. Inline <script> tags inside Blade
    components don't execute when Livewire morphs the component into
    the DOM (wire:navigate, subsequent renders, etc.), which silently
    leaves the factory undefined and crashes every binding on the
    component. The JS bundle always loads, so registration is reliable.
--}}
