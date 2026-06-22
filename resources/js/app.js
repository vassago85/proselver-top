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
        label: config.initialLabel || '',
        query: '',
        open: false,
        active: -1,
        placeholder: config.placeholder || 'Select…',
        emptyText: config.emptyText || 'No matches.',
        allowClear: !!config.allowClear,
        disabled: !!config.disabled,

        /**
         * Tracks whether we've ever successfully read the bound value
         * from Livewire.  Until then we treat "wire returned empty" as
         * "wire isn't ready yet", not "user cleared the field" -- so a
         * race between Alpine x-init and Livewire boot can't silently
         * wipe the selected label (the bug where ?companyId=1 filtered
         * the page but the dropdown showed "Pick a customer" placeholder
         * with no way to reset it because the X button is gated on
         * value !== '').
         */
        _wireReady: false,

        hydrate() {
            const hidden = this.$refs.hiddenInput;
            if (!hidden) return;

            // Find the bound Livewire property name from any wire:model*
            // attribute on the hidden input.  We need this because
            // Livewire 3 does NOT serialize a value="…" attribute into
            // the initial HTML for hidden inputs bound via wire:model —
            // it only sets element.value client-side via JS, which races
            // against our Alpine x-init=hydrate().  Pulling the value
            // straight from $wire.get(prop) sidesteps that race so a
            // URL-driven filter (?executorType=proselver, ?brandId=42)
            // shows the right label on first paint instead of looking
            // empty until the user clicks.
            let wireProp = null;
            for (const attr of hidden.getAttributeNames()) {
                if (attr === 'wire:model' || attr.startsWith('wire:model.')) {
                    wireProp = hidden.getAttribute(attr);
                    break;
                }
            }

            // Read-and-apply pass we re-run at several lifecycle moments.
            // Always non-propagating (passes false) — we're MIRRORING the
            // Livewire state visually, not changing it, so this never
            // triggers a roundtrip back to the server.
            //
            // CRITICAL: don't blow away a previously-set label just
            // because we momentarily can't read the bound value.  An
            // empty hidden.value is ambiguous on Livewire 3 -- it can
            // mean "user cleared" OR "wire hasn't pushed the URL value
            // into the DOM yet".  Treat "wire ready" as ground truth
            // and ignore hidden.value when it's empty AND wire isn't
            // confirmed -- otherwise ?companyId=1 deep-links flicker
            // back to the placeholder + lose the clear button.
            const readAndApply = () => {
                let fromWire;
                if (wireProp && this.$wire) {
                    try { fromWire = this.$wire.get(wireProp); } catch (e) { /* livewire not ready */ }
                }

                if (fromWire !== undefined) {
                    this._wireReady = true;
                    const v = fromWire == null ? '' : String(fromWire);
                    if (v !== String(this.value ?? '')) {
                        this.applyValue(v, false);
                    }
                    return;
                }

                // Wire not ready.  Only trust hidden.value when it's
                // non-empty -- an empty hidden input doesn't tell us
                // anything we can act on without risking wiping the
                // server-rendered initialLabel.
                if (hidden.value) {
                    const v = String(hidden.value);
                    if (v !== String(this.value ?? '')) {
                        this.applyValue(v, false);
                    }
                }
            };

            // Pass 1 — synchronous, catches the wire:navigate case where
            // Livewire is already alive when this component mounts.
            readAndApply();

            // Pass 2 — next animation frame, catches the case where
            // Livewire's JS-side state is set *after* the synchronous
            // x-init pass but inside the same paint (often the case on
            // SPA navigations with Volt-heavy pages).
            requestAnimationFrame(readAndApply);

            // Pass 3 — first full page load.  Alpine's x-init fires
            // BEFORE the global `livewire:initialized` event on a fresh
            // page; until that event fires, $wire.get() returns the
            // schema default rather than the URL-decoded value
            // (?executorType=proselver shows "All executors" until the
            // user clicks the dropdown).  Re-reading here paints the
            // right label on first frame after Livewire boots.  The
            // listener is harmless on wire:navigate because the event
            // already fired earlier — addEventListener silently no-ops
            // and the synchronous pass above already did the work.
            document.addEventListener('livewire:initialized', readAndApply, { once: true });

            // Pass 3b — safety net for the race where
            // `livewire:initialized` has already fired before we attach
            // the listener.  Runs once 50ms after mount; by then
            // Livewire is universally booted and $wire.get() returns
            // the URL-decoded value.  Cheap enough to skip on every
            // mount; without it, ?companyId=1 deep-links land with an
            // empty-looking dropdown and no clear button.
            setTimeout(readAndApply, 50);

            // Pass 4 — every subsequent wire:navigate landing.  Without
            // this, navigating away and back to the same page with a
            // different URL filter leaves the visible label stuck on
            // the previous value (Alpine re-uses the component instance,
            // $wire points at a fresh Livewire backing component, but
            // no event tells us to re-read).  We track the listener so
            // destroy() can detach it cleanly.
            this._onNavigated = () => readAndApply();
            document.addEventListener('livewire:navigated', this._onNavigated);

            // Reactive sync: any time Livewire's property changes (URL
            // param updates, server-side reset, a sibling component
            // touching the same prop, wire:click="$set(...)"), the
            // visible label follows.  Covers both wire:model and
            // wire:model.live — we only care about value changes.
            if (wireProp && this.$wire?.$watch) {
                this.$wire.$watch(wireProp, (v) => {
                    if (String(v ?? '') !== String(this.value ?? '')) {
                        this.applyValue(v ?? '', false);
                    }
                });
            }

            // Legacy DOM-level sync — still useful when the component is
            // bound to a plain hidden input with no wire:model (form
            // helper / inline JS scenarios) or when $wire isn't in scope.
            // Guard against the hidden input being removed from the DOM
            // (Livewire re-render) so the observer callback can't throw
            // and break other JS on the page.
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
            if (this._onNavigated) {
                document.removeEventListener('livewire:navigated', this._onNavigated);
                this._onNavigated = null;
            }
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
            const newValue = (v === null || v === undefined) ? '' : String(v);
            const match = this.flatOptions.find(o => o.value === newValue);
            this.value = newValue;

            // Update the visible label only when we have new ground
            // truth: a matching option, or an explicit clear.  Without
            // this guard, a Livewire re-render that arrives BEFORE the
            // refreshed options list is wired into Alpine's groups (a
            // race we hit on the reports page filter chain) would wipe
            // the label to '' while value stayed set -- the dropdown
            // visually reverted to its placeholder ("All customers")
            // even though the backend filter was correctly applied.
            // Keeping the previous label keeps the visual stable until
            // the next reactive pass either confirms it or replaces it.
            if (match) {
                this.label = match.label;
            } else if (newValue === '') {
                this.label = '';
            }
            // else: keep this.label as-is; subsequent passes will
            // overwrite when the option becomes findable.

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
