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

    <button
        type="button"
        x-ref="trigger"
        :disabled="disabled"
        @click="toggle()"
        @keydown.down.prevent="open ? moveActive(1) : openAndFocus()"
        @keydown.up.prevent="open ? moveActive(-1) : openAndFocus()"
        @keydown.enter.prevent="open && active >= 0 ? selectActive() : openAndFocus()"
        :class="[
            'group flex w-full items-center justify-between rounded-lg border bg-white px-3 py-2.5 text-left text-sm transition',
            disabled
                ? 'cursor-not-allowed border-gray-200 bg-gray-50 text-gray-400'
                : 'border-gray-300 hover:border-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500',
        ]"
        :aria-expanded="open ? 'true' : 'false'"
        aria-haspopup="listbox"
    >
        <span x-show="value !== ''" class="truncate text-gray-900" x-text="label"></span>
        <span x-show="value === ''" class="truncate text-gray-400" x-text="placeholder"></span>
        <span class="ml-2 flex shrink-0 items-center gap-1.5">
            <button
                type="button"
                x-show="allowClear && value !== '' && !disabled"
                @click.stop="clear()"
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
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        class="absolute z-50 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg"
    >
        <div class="border-b border-gray-100 p-2">
            <div class="relative">
                <svg class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 17a6 6 0 100-12 6 6 0 000 12z" />
                </svg>
                <input
                    type="text"
                    x-ref="search"
                    x-model="query"
                    @keydown.down.prevent="moveActive(1)"
                    @keydown.up.prevent="moveActive(-1)"
                    @keydown.enter.prevent="selectActive()"
                    @keydown.escape.stop.prevent="close()"
                    placeholder="{{ $searchPlaceholder }}"
                    class="w-full rounded-md border border-gray-200 bg-white py-1.5 pl-8 pr-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
            </div>
        </div>

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

@once
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('searchableSelect', (config) => ({
                groups: config.groups || [],
                value: '',
                label: '',
                query: '',
                open: false,
                active: -1,
                placeholder: config.placeholder || 'Select…',
                emptyText: config.emptyText || 'No matches.',
                allowClear: !!config.allowClear,
                disabled: !!config.disabled,

                hydrate() {
                    const hidden = this.$refs.hiddenInput;
                    if (!hidden) return;

                    const initial = hidden.value ?? '';
                    if (initial) {
                        this.applyValue(initial, false);
                    }

                    /*
                     * Track outside changes (Livewire validation, parent
                     * setting the property, etc.) so the visible label
                     * stays consistent.
                     *
                     * Guard against the hidden input being removed from
                     * the DOM (e.g. Livewire re-render), otherwise the
                     * observer callback throws and breaks other JS on
                     * the page (Alpine, wire:click handlers, etc.).
                     */
                    const observer = new MutationObserver(() => {
                        const ref = this.$refs.hiddenInput;
                        if (!ref) return;
                        const v = ref.value;
                        if (v !== this.value) {
                            this.applyValue(v, false);
                        }
                    });
                    observer.observe(hidden, { attributes: true, attributeFilter: ['value'] });
                    this._observer = observer;

                    /*
                     * If the parent/Livewire writes via `.value =` the
                     * MutationObserver above won't fire because that
                     * doesn't change the attribute. Hook the input
                     * event too as a safety net.
                     */
                    hidden.addEventListener('input', () => {
                        const ref = this.$refs.hiddenInput;
                        if (!ref) return;
                        const v = ref.value;
                        if (v !== this.value) {
                            this.applyValue(v, false);
                        }
                    });
                },

                destroy() {
                    this._observer?.disconnect();
                    this._observer = null;
                },

                get flatOptions() {
                    return this.groups.flatMap(g => g.options);
                },

                get filteredGroups() {
                    const q = this.query.trim().toLowerCase();
                    if (!q) return this.groups;
                    return this.groups
                        .map(g => ({
                            label: g.label,
                            options: g.options.filter(o => o.label.toLowerCase().includes(q)),
                        }))
                        .filter(g => g.options.length > 0);
                },

                get totalFiltered() {
                    return this.filteredGroups.reduce((n, g) => n + g.options.length, 0);
                },

                get flatFilteredOptions() {
                    return this.filteredGroups.flatMap(g => g.options);
                },

                flatIndexFor(value) {
                    return this.flatFilteredOptions.findIndex(o => o.value === value);
                },

                applyValue(v, propagate = true) {
                    const match = this.flatOptions.find(o => o.value === String(v));
                    this.value = match ? match.value : (v ?? '');
                    this.label = match ? match.label : '';

                    if (propagate) {
                        this.$refs.hiddenInput.value = this.value;
                        this.$refs.hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
                        this.$refs.hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                },

                toggle() {
                    if (this.disabled) return;
                    this.open ? this.close() : this.openAndFocus();
                },

                openAndFocus() {
                    if (this.disabled) return;
                    this.open = true;
                    this.active = this.flatIndexFor(this.value);
                    this.$nextTick(() => this.$refs.search?.focus());
                },

                close() {
                    this.open = false;
                    this.query = '';
                    this.active = -1;
                },

                moveActive(delta) {
                    const n = this.flatFilteredOptions.length;
                    if (n === 0) { this.active = -1; return; }
                    if (this.active < 0) {
                        this.active = delta > 0 ? 0 : n - 1;
                    } else {
                        this.active = (this.active + delta + n) % n;
                    }
                },

                selectActive() {
                    if (this.active < 0 || this.active >= this.flatFilteredOptions.length) return;
                    this.select(this.flatFilteredOptions[this.active].value);
                },

                select(v) {
                    this.applyValue(v, true);
                    this.close();
                    this.$refs.trigger?.focus();
                },

                clear() {
                    this.applyValue('', true);
                },
            }));
        });
    </script>
@endonce
