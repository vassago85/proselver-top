import './bootstrap';

/*
 * Alpine.data registrations. These MUST live in the JS bundle (not
 * inside a Blade @once <script> tag) because Livewire's wire:navigate
 * morphs new page HTML into the DOM via innerHTML-style mutation, and
 * the browser refuses to execute <script> tags introduced that way.
 * Anything registered from a Blade @once would only run on a true
 * full-page reload — every subsequent SPA navigation would leave the
 * factory undefined, and any x-data="myFactory(...)" on that page
 * would crash with "myFactory is not defined", taking the rest of the
 * component's Alpine bindings down with it.
 *
 * Keep this list small and only add genuinely shared components here.
 * One-off page-local Alpine state should still use inline x-data={...}.
 */
document.addEventListener('alpine:init', () => {
    // <x-searchable-select> — typeahead dropdown used across location,
    // brand, vehicle-class, driver, customer, zone pickers.  Source of
    // truth for behaviour; the Blade component only renders the markup.
    window.Alpine.data('searchableSelect', (config) => ({
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

            // Track outside changes (Livewire validation, parent setting
            // the property, etc.) so the visible label stays consistent.
            // Guard against the hidden input being removed from the DOM
            // (Livewire re-render) otherwise the observer callback throws
            // and breaks other JS on the page.
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

            // If the parent / Livewire writes via `.value =` the
            // MutationObserver above won't fire (that doesn't change
            // the attribute). Hook the input event too as a safety net.
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

/*
 * Service worker policy for the main app:
 *
 *   - We DO NOT register a service worker on the admin / dispatcher / customer
 *     side. The previous /sw.js used a cache-first strategy for every non-HTML
 *     response, which permanently pinned the Vite build bundle and any
 *     vendor JS (incl. Livewire). After a deploy, browsers kept serving
 *     stale JS that crashed on shape changes ("Cannot read properties of
 *     undefined (reading 'forEach')" inside Livewire's success handler).
 *     The same SW was also flagged in docs/SECURITY_AUDIT_2026-04-22.md (H-1)
 *     for caching authenticated HTML across users on shared devices.
 *
 *   - The driver PWA continues to register its own scoped service worker
 *     at /driver-sw.js from the driver layout. That one is safe by design:
 *     it never caches authenticated HTML or /livewire/* and is versioned.
 *
 * We also self-heal any browser that still has the old /sw.js installed by
 * unregistering it on next page load and clearing its caches. After every
 * driver / admin re-opens the site once, the leftover SW disappears.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', async () => {
        try {
            const regs = await navigator.serviceWorker.getRegistrations();
            for (const reg of regs) {
                const scriptUrl = reg.active?.scriptURL || reg.installing?.scriptURL || reg.waiting?.scriptURL || '';
                if (scriptUrl.endsWith('/sw.js')) {
                    await reg.unregister();
                }
            }

            if (window.caches) {
                const keys = await caches.keys();
                await Promise.all(
                    keys
                        .filter((k) => k.startsWith('tcdc-'))
                        .map((k) => caches.delete(k))
                );
            }
        } catch (e) { /* noop — best effort */ }
    });
}
