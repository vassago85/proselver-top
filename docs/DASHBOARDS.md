# Trident Dashboard Design System

A small, opinionated component library that every dashboard in the app
should use. The goal is not to make every page identical — it's to make
every page feel like part of the same premium transport operations
platform. Same spacing. Same card language. Same filter system. Same
status colours. Page-specific content inside.

## Reference implementations

Two pages are refactored end-to-end and serve as the canonical pattern:

- `resources/views/pages/admin/dashboard.blade.php` — Executive Overview
- `resources/views/pages/admin/drivers/operations.blade.php` — Driver Ops

Read those first, then mirror the structure in whichever dashboard you
are migrating. **Never** introduce page-specific filter / card / KPI
styling outside of these components.

## The component family

All dashboard primitives live under `resources/views/components/dash/`
and resolve as `x-dash.*`.

| Component              | Purpose                                                      |
|-----------------------|--------------------------------------------------------------|
| `x-dash.filter-bar`   | White rounded wrapper for the filter row                     |
| `x-dash.filter-date`  | Date input + uppercase tracking label                        |
| `x-dash.filter-select`| Select + uppercase tracking label. `$slot` receives options  |
| `x-dash.filter-field` | Label wrapper around any custom input (search box, toggle…)  |
| `x-dash.filter-reset` | Standard reset button                                        |
| `x-dash.kpi`          | The single KPI tile used on every dashboard                  |
| `x-dash.panel`        | Section card — header / subtitle / actions slot / body / footer |
| `x-dash.pill`         | Small inline pill for counts / short labels                  |

Beyond those, continue to use the existing platform primitives:

- `x-page-header` — page title + eyebrow + actions
- `x-status-badge` — job / inventory state badges (already semantic)
- `x-button` — all primary / secondary buttons

## Page skeleton

```blade
<div class="space-y-6">

    <x-page-header
        eyebrow="Page category"
        title="Page title"
        subtitle="One sentence describing the operational lens.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="...">Secondary</x-button>
            <x-button variant="primary"   size="sm" :href="...">Primary</x-button>
        </x-slot:actions>
    </x-page-header>

    <x-dash.filter-bar>
        <x-dash.filter-date label="From" wire:model.live="dateFrom" />
        <x-dash.filter-date label="To"   wire:model.live="dateTo" />
        <x-dash.filter-select label="Something" wire:model.live="something">
            <option value="">All</option>
            @foreach($options as $o) <option value="{{ $o->id }}">{{ $o->name }}</option> @endforeach
        </x-dash.filter-select>
        <x-dash.filter-reset wire:click="resetFilters" />
    </x-dash.filter-bar>

    {{-- KPI row — 3 / 5 / 6 columns depending on density --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
        @foreach($kpis as $k)
            <x-dash.kpi :label="$k['label']" :value="$k['value']"
                        :color="$k['color']" :href="$k['href']"
                        :helper="$k['helper']" :trend="$k['trend']">
                <x-slot:icon><svg>...lucide paths...</svg></x-slot:icon>
            </x-dash.kpi>
        @endforeach
    </div>

    {{-- Main grid --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <x-dash.panel class="lg:col-span-2" title="Activity" subtitle="Last 30 days">
            ...chart...
        </x-dash.panel>

        <x-dash.panel title="Distribution">
            ...list...
        </x-dash.panel>
    </div>

    {{-- Tables — use :tight="true" so the table sits edge-to-edge --}}
    <x-dash.panel title="Operational table" :tight="true">
        <x-slot:actions>
            <x-dash.pill variant="blue">{{ $count }} rows</x-dash.pill>
        </x-slot:actions>
        <div class="overflow-x-auto"><table class="min-w-full text-sm">...</table></div>
    </x-dash.panel>

</div>
```

## Semantic colour rules

Colour is information, not decoration. Always map state → colour:

| Meaning                                  | Dash colour / Pill variant |
|------------------------------------------|----------------------------|
| active / assigned / in progress          | `blue`                     |
| completed / healthy / on-time / paid     | `green`                    |
| warning / at risk / pending review       | `amber`                    |
| critical / delayed / blocked / overdue   | `red`                      |
| throughput / distribution / neutral-pos. | `teal`                     |
| planning / queued                        | `indigo`                   |
| invoicing / finance                      | `purple`                   |
| receivables / attention                  | `orange`                   |
| inactive / archived / draft              | `slate`                    |

Do not use arbitrary Tailwind colour classes for status. Use `x-dash.pill`
or `x-status-badge`.

## Spacing rhythm

- Page root: `space-y-6`
- Grid sections: `gap-4`
- Panel body padding: handled by `x-dash.panel` (defaults to `p-5`)
- Tables: `:tight="true"` on the panel, then `overflow-x-auto` around the table
- KPI grids:
  - Executive density (6 KPIs): `grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4`
  - Ops density (5 KPIs): `grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4`
  - Lean (3 KPIs): `grid-cols-1 sm:grid-cols-3 gap-4`

## Migration checklist

When migrating a page to this system:

1. **Do not touch the PHP `with()` / actions / mount logic.** Route
   names, query shapes, wire:model bindings must stay identical.
2. Wrap the filter row in `<x-dash.filter-bar>`, swap every date /
   select input for `<x-dash.filter-date>` / `<x-dash.filter-select>`,
   replace the reset button with `<x-dash.filter-reset>`.
3. Swap every bespoke KPI card for `<x-dash.kpi>`. Move dynamic
   SVG data into the `icon` slot.
4. Wrap every `rounded-xl border bg-white shadow-sm` section in
   `<x-dash.panel>`. Move the inline `<div class="border-b px-6 py-4">`
   header into the `title` / `subtitle` / `actions` slot.
5. Replace status / count pills (`bg-x-100 text-x-700 border-x-200`)
   with `<x-dash.pill variant="x">`.
6. **Do not** remove `x-status-badge` — keep it for job / inventory
   state badges (it's already semantic).
7. After migration, run a Blade compile smoke test to prove nothing
   regressed:

   ```bash
   php artisan view:clear && php artisan view:cache
   ```

## Pages already migrated

- [x] `/admin/dashboard` — Executive Overview
- [x] `/admin/drivers/operations` — Driver Ops

## Pages pending migration

The component library is stable — these pages will move over in
subsequent iterations without any data / logic changes.

- [ ] `/admin/planning` — Dispatch queue
- [ ] `/admin/dispatch` — Live dispatch board
- [ ] `/admin/deliveries` — Delivery log
- [ ] `/admin/vehicles` — Yard / Stock
- [ ] `/admin/drivers` — Roster & compliance (HR-side, not ops)
- [ ] `/admin/damage` — Damage incidents
- [ ] `/admin/invoices` — Invoice surface
- [ ] `/customer/dashboard`
- [ ] `/dealer/dashboard`
- [ ] `/oem/dashboard`
- [ ] `/driver/dashboard`

## DO NOT

- Do not create dashboard-specific card / filter / KPI classes.
- Do not use arbitrary Tailwind colour families for status.
- Do not add decorative gradients, pastel tints, or marketing-style
  hero sections to operational pages.
- Do not duplicate `x-dash.*` components — extend them in place if
  a genuinely cross-cutting need appears.
