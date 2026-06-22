<?php

use App\Models\Company;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * In-app guide for dealer-portal users.  Renders a static, fully
 * self-contained Tailwind page with a sticky table of contents and
 * anchored sections.  No queries, no permissions to check beyond the
 * usual customer-tier middleware -- everyone in the portal can read
 * the help page.
 *
 * Content is tailored to dealer tenants; OEM / BB users land here too
 * if they click "Help" but the headers make it clear which sections
 * apply to whom (dealer-only items are labelled).
 */
new #[Layout('components.layouts.app')] class extends Component {
    public bool $isDealer = false;
    public bool $isOem = false;
    public bool $isBb = false;

    public function mount(): void
    {
        $type = optional(auth()->user()?->company())->type;
        $this->isDealer = $type === Company::TYPE_DEALER;
        $this->isOem    = $type === Company::TYPE_OEM;
        $this->isBb     = $type === Company::TYPE_BODY_BUILDER;
    }
};

?>

<div>
    <x-slot:header>Help &amp; Guide</x-slot:header>

    <div class="mb-6 rounded-2xl bg-gradient-to-br from-slate-900 via-slate-800 to-blue-900 text-white shadow-lg overflow-hidden">
        <div class="px-6 py-6 sm:px-8 sm:py-7">
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-blue-200/80">User Guide</p>
            <h2 class="mt-1 text-xl sm:text-2xl font-semibold">
                @if($isDealer) Dealer Portal — how it works
                @elseif($isOem) OEM Portal — how it works
                @elseif($isBb)  Body Builder Portal — how it works
                @else           ProSelver Portal — how it works
                @endif
            </h2>
            <p class="mt-2 max-w-3xl text-sm text-slate-200">
                @if($isDealer)
                    A quick reference to the screens you use every day: tracking stock, booking transport,
                    working with body builders, and managing your team.
                @elseif($isOem)
                    A quick reference to booking ProSelver movements, tracking jobs, and managing your team.
                    Stock and dealer-side commercial states (Reserve, Mark sold, Archive) don't apply here &mdash;
                    OEM movements are pure logistics from factory or port to dealer.
                @else
                    A quick reference to the screens you use every day. Use the menu on the left to jump to a section.
                @endif
            </p>
        </div>
    </div>

    @php
        // Section list is gated by tenant type.  Dealer-only sections
        // (stock ledger, vehicle card, reserve flow, fitment chain,
        // dealer journey) are hidden from OEM / BB users so they don't
        // try to follow a workflow that doesn't apply to them.
        $toc = array_values(array_filter([
            $isDealer ? ['journey', 'The dealer journey']
                : ($isOem ? ['journey', 'How OEM movements work'] : null),
            ['nav', 'Finding your way around'],
            ['roles', 'Roles &amp; what they can do'],
            $isDealer ? ['dashboard', 'Dashboard'] : null,
            $isDealer ? ['stock', 'Stock — three views'] : null,
            $isDealer ? ['vehicle', 'Vehicle card &amp; lifecycle'] : null,
            $isDealer ? ['reserve', 'Reserve workflow'] : null,
            $isDealer ? ['fitment', 'Fitment chain'] : null,
            ['orders', 'Movements &amp; bookings'],
            $isDealer ? ['bb', 'Body builders — two flows'] : null,
            ['trips', 'Trips &amp; drivers'],
            ['reports', 'Reports &amp; resources'],
            ['account', 'Account settings'],
            ['quickref', 'Quick reference'],
            ['glossary', 'Glossary'],
            ['support', 'Getting help'],
        ]));
    @endphp

    {{-- Mobile / tablet TOC: a single collapsed <details> so the menu
         doesn't eat half the screen on small viewports.  Hidden on
         lg+ where the sticky aside takes over. --}}
    <details class="group lg:hidden mb-4 rounded-lg border border-slate-200 bg-white">
        <summary class="cursor-pointer list-none px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 select-none flex items-center justify-between">
            <span>Jump to section</span>
            <svg class="h-3.5 w-3.5 text-slate-400 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <ul class="grid grid-cols-2 gap-x-2 gap-y-0.5 border-t border-slate-100 px-2 py-2 text-xs">
            @foreach($toc as [$id, $label])
                <li><a href="#{{ $id }}" class="block rounded px-2 py-1 text-slate-700 hover:bg-slate-50 hover:text-slate-900">{!! $label !!}</a></li>
            @endforeach
        </ul>
    </details>

    <div class="grid grid-cols-1 lg:grid-cols-[200px,1fr] gap-6">
        {{-- Sticky TOC (desktop): compact list, no extra "Stuck?" card
             -- support copy already lives in section 13 (Getting help). --}}
        <aside class="hidden lg:block lg:sticky lg:top-4 lg:self-start">
            <nav class="rounded-lg border border-slate-200 bg-white p-2 text-xs" aria-label="Help table of contents">
                <p class="px-2 pb-1.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">On this page</p>
                <ul class="space-y-px">
                    @foreach($toc as [$id, $label])
                        <li>
                            <a href="#{{ $id }}" class="block rounded px-2 py-0.5 text-slate-700 hover:bg-slate-50 hover:text-slate-900">{!! $label !!}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </aside>

        {{-- Content --}}
        <article class="space-y-10 text-sm leading-6 text-slate-800">

            {{-- 0. Journey overview --------------------------------------------- --}}
            {{-- Dealer-only.  The Reserve → Sold → Delivered → Archive
                 sequence is the dealer commercial funnel; OEM moves
                 are pure logistics with no sold/reserved state, so
                 this section is hidden from OEM and BB tenants. --}}
            @if($isDealer)
            <section id="journey" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">The dealer journey</h3>
                <p class="mt-2">The portal is organised around one job, in this order:</p>
                <ol class="mt-3 space-y-2 list-decimal pl-5">
                    <li><strong>Track your vehicles</strong> — see where every VIN is (premises, BB, storage, transit, on demo).</li>
                    <li><strong>Reserve</strong> the vehicle when a customer commits — capture salesperson + buyer.</li>
                    <li><strong>Book a delivery</strong> with ProSelver (or your own driver) to move the chassis where it needs to go.</li>
                    <li><strong>Mark sold</strong> when the paperwork is done — the reserve carries forward automatically.</li>
                    <li><strong>Mark as delivered</strong> when the buyer takes the keys — the row leaves the active board but stays in your <em>Recently delivered</em> history.</li>
                </ol>
                <p class="mt-3 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-900">
                    Every step is visible on the <strong>vehicle card</strong> as a single lifecycle timeline, plus a separate panel for the active transport job. Open any row in <em>All stock</em> to see both at once.
                </p>
                <p class="mt-2 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-900">
                    <strong>Archive is not part of the journey.</strong> Use it only for mistakes, test vehicles, or duplicate rows that should never have been on the books — archived rows are hidden from history. For a normal handover, always use <em>Mark as delivered</em>.
                </p>
            </section>
            @elseif($isOem)
            {{-- OEM equivalent: pure logistics, no commercial state. --}}
            <section id="journey" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">How OEM movements work</h3>
                <p class="mt-2">The portal is built around moving stock from factory or port to a dealer:</p>
                <ol class="mt-3 space-y-2 list-decimal pl-5">
                    <li><strong>Book a movement</strong> — one VIN or many via bulk upload.</li>
                    <li><strong>Confirm</strong> a job we send back to you for verification (if your tenant requires confirmation).</li>
                    <li><strong>Track</strong> the movement from pickup to delivery.</li>
                    <li><strong>Reconcile</strong> deliveries via the deliveries report.</li>
                </ol>
                <p class="mt-3 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-900">
                    There is no &ldquo;mark sold&rdquo; or &ldquo;reserve&rdquo; step on the OEM side. Once a vehicle is delivered to a dealer it becomes their stock and their sales process; ProSelver's job is purely the move.
                </p>
            </section>
            @endif

            {{-- 1. Navigation ---------------------------------------------------- --}}
            <section id="nav" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Finding your way around</h3>
                <p class="mt-2">The sidebar shows <strong>Dealer Portal</strong> when your company is a dealer. The main sections you will use:</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Section</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">What it is for</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr><td class="px-3 py-2 font-medium">Movements</td><td class="px-3 py-2">Dashboard, <em>Book a delivery</em>, bulk upload, <em>My movements</em></td></tr>
                            <tr><td class="px-3 py-2 font-medium">Stock</td><td class="px-3 py-2">Full stock ledger and the off-site / in-transit view</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Trips</td><td class="px-3 py-2">Plan trips for your own drivers; drivers use <em>My Day</em></td></tr>
                            <tr><td class="px-3 py-2 font-medium">Reports</td><td class="px-3 py-2">Deliveries report and the live wallboard</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Resources</td><td class="px-3 py-2">Documents and the address book</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Body Builders</td><td class="px-3 py-2">Movement requests, linked BBs, request a new BB</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Account</td><td class="px-3 py-2">Team, branding, drivers, petty cash</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-slate-600">
                    OEM and body-builder tenants see slightly different labels — the section above describes the dealer sidebar.
                </p>

                <h4 class="mt-5 text-sm font-semibold text-slate-900">Sidebar badges to watch for</h4>
                <ul class="mt-2 list-disc pl-5 space-y-1">
                    <li><strong>Movement Requests</strong> — amber number = pending body-builder requests waiting for your decision.</li>
                    <li><strong>My Orders</strong> — amber number = <em>direct orders</em> where a body builder booked ProSelver against your VIN and needs your owner approval.</li>
                </ul>

                <p class="mt-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                    If you can't see a menu item, your role probably doesn't include that permission. The portal hides what you can't use.
                </p>
            </section>

            {{-- 2. Roles --------------------------------------------------------- --}}
            <section id="roles" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Roles &amp; what they can do</h3>
                <p class="mt-2">Roles map to permission groups. The most common dealer roles:</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 bg-white p-3">
                        <p class="text-sm font-semibold text-slate-900">Dealer Owner / Principal</p>
                        <p class="mt-1 text-xs text-slate-600">Full access: stock, orders, team, BB links, bulk upload, drivers.</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3">
                        <p class="text-sm font-semibold text-slate-900">Sales Manager</p>
                        <p class="mt-1 text-xs text-slate-600">Similar to owner minus a few admin extras.</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3">
                        <p class="text-sm font-semibold text-slate-900">Stock Controller</p>
                        <p class="mt-1 text-xs text-slate-600">Stock ledger, movements, POs; usually handles BB owner approvals.</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3">
                        <p class="text-sm font-semibold text-slate-900">Sales Person</p>
                        <p class="mt-1 text-xs text-slate-600">Own orders, view stock, address book. No team/BB admin/bulk upload.</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3 sm:col-span-2">
                        <p class="text-sm font-semibold text-slate-900">Dispatcher</p>
                        <p class="mt-1 text-xs text-slate-600">Confirms orders and BB movement approvals; can be limited to one branch.</p>
                    </div>
                </div>
                <p class="mt-3 text-xs text-slate-600">
                    Group / franchise principals see a <strong>dealership chip strip</strong> on stock, orders, and movement requests to filter by branch.
                </p>
            </section>

            {{-- 3. Dashboard ----------------------------------------------------- --}}
            {{-- Sections 3-7 below (Dashboard, Stock, Vehicle card, Reserve,
                 Fitment chain) document dealer-only screens.  OEM and BB
                 tenants get a different dashboard and no stock ledger,
                 so this whole block is dealer-gated. --}}
            @if($isDealer)
            <section id="dashboard" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Dashboard</h3>
                <p class="mt-2">
                    <a href="{{ route('customer.dashboard') }}" class="font-semibold text-blue-600 hover:text-blue-800">Open the dashboard</a> —
                    eight cards in a tablet-friendly grid, showing where every vehicle on your books is right now.
                    Tap a card to jump straight into the matching filter on <em>All stock</em>; tap it again to clear.
                </p>
                <p class="mt-2 text-xs text-slate-600">
                    The top row mirrors the commercial funnel (Available → Reserved → Body builder → Scheduled).
                    The bottom row tracks the physical journey (In transit → Storage → On demo → Recently sold).
                </p>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Card</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Meaning</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr><td class="px-3 py-2 font-medium">At premises</td><td class="px-3 py-2">On your dealership floor</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Reserved</td><td class="px-3 py-2">Held for a customer (salesperson + contact captured)</td></tr>
                            <tr><td class="px-3 py-2 font-medium">At body builder / fitment</td><td class="px-3 py-2">Parked at a linked BB</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Scheduled for movement</td><td class="px-3 py-2">Transport booked; collection not started</td></tr>
                            <tr><td class="px-3 py-2 font-medium">In transit</td><td class="px-3 py-2">On the road with an active job</td></tr>
                            <tr><td class="px-3 py-2 font-medium">At another storage</td><td class="px-3 py-2">Parked at another yard</td></tr>
                            <tr><td class="px-3 py-2 font-medium">On demo with customer</td><td class="px-3 py-2">Out on demo</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Recently sold</td><td class="px-3 py-2">Sold but still on your books — Mark as delivered when the buyer takes the keys</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Recently delivered</td><td class="px-3 py-2">Handed over in the last 30 days — archived from the active board, kept here for your records</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- 4. Stock --------------------------------------------------------- --}}
            <section id="stock" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Stock — three views</h3>
                <p class="mt-2">There are three stock screens because they answer slightly different questions.</p>

                <div class="mt-3 space-y-3">
                    <div class="rounded-lg border border-slate-200 bg-white p-4">
                        <p class="text-sm font-semibold text-slate-900">1. All stock (canonical ledger)</p>
                        <p class="mt-1 text-xs text-slate-600">
                            <a href="{{ route('customer.stock.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Open All stock</a>.
                            The main &ldquo;where is my stock?&rdquo; table. Filter by bucket, body builder, or salesperson. Click a row to open the vehicle card.
                        </p>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-4">
                        <p class="text-sm font-semibold text-slate-900">2. Off-site &amp; in transit (job-based)</p>
                        <p class="mt-1 text-xs text-slate-600">
                            <a href="{{ route('customer.stock.at-body-builder') }}" class="font-semibold text-blue-600 hover:text-blue-800">Open Off-site &amp; in transit</a>.
                            Built from transport jobs. Use <em>Book return</em> from here to pre-fill a new order.
                        </p>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-4">
                        <p class="text-sm font-semibold text-slate-900">3. Dashboard cards</p>
                        <p class="mt-1 text-xs text-slate-600">Same buckets as the ledger, optimised for quick counts. Each card deep-links into <em>All stock</em>.</p>
                    </div>
                </div>

                <h4 class="mt-5 text-sm font-semibold text-slate-900">Bucket labels</h4>
                <ul class="mt-2 list-disc pl-5 space-y-1">
                    <li><strong>At premises / Body builder / Other storage / In transit / On demo</strong> — physical location.</li>
                    <li><strong>Scheduled for movement</strong> — a job is booked but collection has not started yet.</li>
                    <li><strong>Recently sold</strong> — sold in the last 30 days and <em>still on your books</em>. Hit <em>Mark as delivered</em> on the vehicle card the moment the buyer takes the keys.</li>
                    <li><strong>Recently delivered</strong> — delivered in the last 30 days. The row has been archived from the active board but stays here as your delivery history.</li>
                </ul>
                <p class="mt-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-900">
                    <strong>Sold &ne; Delivered.</strong> A vehicle stays in <em>Recently sold</em> while the paperwork is finalising; <em>Mark as delivered</em> closes the row out the moment the keys change hands. <em>Archive</em> is a separate action reserved for mistakes and test vehicles.
                </p>

                @if($isDealer)
                <h4 class="mt-5 text-sm font-semibold text-slate-900">Adding stock</h4>
                <p class="mt-1 text-xs text-slate-600">
                    Two paths, both gated by <em>Manage Dealer Stock</em>:
                </p>
                <ul class="mt-2 ml-4 list-disc text-xs text-slate-600 space-y-1">
                    <li>
                        <strong><a href="{{ route('customer.stock.create') }}" class="text-blue-600 hover:text-blue-800">+ Add vehicle</a></strong>
                        &mdash; one vehicle at a time, with a starting-location picker. Use this when a unit was shipped factory-direct to one of your body builders, or arrived at a different yard than your main premises.
                    </li>
                    <li>
                        <strong><a href="{{ route('customer.stock.import') }}" class="text-blue-600 hover:text-blue-800">Import stock</a></strong>
                        &mdash; bulk upload (.xlsx / .xls / .csv) from your DMS (Kerridge, Pinnacle, Autoline, Automate, ...). Auto-detects VIN / Chassis Number, Suffix, Variant, Description, Engine number, Colour, Registration, Make / Brand, Model, Model year. You can override any mapping before commit. Re-uploads are safe &mdash; existing rows match on VIN, attributes refresh, location and sale state stay put.
                    </li>
                </ul>
                <p class="mt-2 text-xs text-slate-600">
                    Both paths let you pick the <strong>starting location</strong>: your premises, a linked body builder yard, or one of your own non-primary yards. Got a whole batch the OEM dropped at a fitter? Use Import stock and set the default location to that BB &mdash; the entire upload lands there in one pass.
                </p>
                <p class="mt-2 text-xs text-slate-600">
                    The import page also has a <em>Download sample CSV template</em> link if you need a starting layout.
                </p>
                <p class="mt-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                    <strong>Duplicate VIN handling.</strong>
                    The importer blocks a VIN that appears <em>twice in the same spreadsheet</em> with a hard error (preview row turns red, the row is skipped at commit). A VIN that's <em>already on your books</em> is detected as well &mdash; you'll see an amber <em>Already on file</em> warning in the preview, and the commit refreshes the vehicle's identity columns (make / model / colour / etc.) without touching its location or sale state.
                    <br><br>
                    <strong>One caveat:</strong> the importer doesn't yet flag VINs that are on file at <em>another</em> dealership on the platform. If you've just bought a unit from another dealer, ask them to <em>Mark as delivered</em> on their side so their record closes out cleanly &mdash; otherwise you'll both have the same VIN on your active boards until they do.
                </p>
                @endif
            </section>

            {{-- 5. Vehicle card ------------------------------------------------- --}}
            <section id="vehicle" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Vehicle card &amp; lifecycle</h3>
                <p class="mt-2">From <em>All stock</em>, click any row to open the vehicle card. The card has five parts:</p>
                <ol class="mt-3 space-y-1.5 list-decimal pl-5 text-sm">
                    <li><strong>Vehicle details</strong> — VIN, make, model, registration, colour, dealership.</li>
                    <li><strong>Where</strong> — the bucket and (if known) the specific location name.</li>
                    <li><strong>Lifecycle timeline</strong> — Available → Reserved → Sold → Delivered, with the date and person at each step.</li>
                    <li><strong>Transport movement</strong> — the active ProSelver job (number, status, pickup → delivery) or "No active transport job".</li>
                    <li><strong>Fitment chain</strong> — the ordered list of body builders / fitment stops (dropside, crane, fridge body, fridge unit, paint, ...). Each step has its own notes and per-step sharing toggle.</li>
                </ol>
                <p class="mt-3 text-xs text-slate-600">
                    The lifecycle (commercial), transport movement (physical) and fitment chain (build) run independently — a vehicle can be sold while still in transit, or have legs planned at three BBs before transport is even booked. The card shows them all at once. <em>Mark as delivered</em> is the happy-path exit from the active board; <em>Archive</em> is the escape hatch for mistakes / test vehicles only.
                </p>

                <h4 class="mt-5 text-sm font-semibold text-slate-900">Actions available</h4>
                <p class="mt-1 text-xs text-slate-600">What you can do depends on your <em>Manage Dealer Stock</em> permission and the current state of the vehicle.</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Action</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">What it does</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr><td class="px-3 py-2 font-medium">Book delivery</td><td class="px-3 py-2">Start a transport order pre-filled with this VIN, pickup, brand, model</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Reserve</td><td class="px-3 py-2">Hold for a buyer — assign salesperson + customer; edit or clear at any time</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Clear reserve</td><td class="px-3 py-2">Release the hold; the vehicle returns to <em>Available</em></td></tr>
                            <tr><td class="px-3 py-2 font-medium">Mark sold (from reserve)</td><td class="px-3 py-2">Reserved customer carries forward; stamps <em>sold_at</em></td></tr>
                            <tr><td class="px-3 py-2 font-medium">Mark as sold</td><td class="px-3 py-2">If not previously reserved — capture salesperson + customer</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Send out on demo</td><td class="px-3 py-2">Captures customer + due-back date, swings to <em>On demo</em></td></tr>
                            <tr><td class="px-3 py-2 font-medium">Return from demo</td><td class="px-3 py-2">Brings the unit back from demo</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Reverse sale</td><td class="px-3 py-2">Undo a sale while the row is still on the active ledger</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Mark as delivered</td><td class="px-3 py-2"><strong>Happy-path close.</strong> Stamps <em>delivered_at</em>, archives the row, lands it in <em>Recently delivered</em>. Only available once the row is <em>Sold</em>.</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Archive (mistake / test)</td><td class="px-3 py-2"><strong>Escape hatch.</strong> Use only for mistakes, test vehicles, or duplicates. Archived rows are hidden from delivery history. For a normal handover use <em>Mark as delivered</em> instead.</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Fitment chain — add step</td><td class="px-3 py-2">Plan one or more body-builder stops for this VIN (dropside, crane, fridge, paint, ...). Each step has its own notes and sharing.</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Fitment chain — share with BB (per step)</td><td class="px-3 py-2">Choose <em>per step</em> whether the BB sees the salesperson + end customer. Useful when one fitter needs the context and another doesn't.</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Print delivery note</td><td class="px-3 py-2">PDF for the buyer (available on any live unit)</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- 5b. Reserve workflow -------------------------------------------- --}}
            <section id="reserve" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Reserve workflow</h3>
                <p class="mt-2">
                    <strong>Reserve</strong> is the step between &ldquo;on the floor&rdquo; and &ldquo;sold&rdquo;. It captures
                    <em>who is buying</em> and <em>who is selling it to them</em> before the deal closes — so the unit is held off the available list and any salesperson on the team can see it's spoken for.
                </p>

                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <p class="text-sm font-semibold text-amber-900">When to reserve</p>
                        <ul class="mt-2 list-disc pl-5 space-y-1 text-xs text-amber-900">
                            <li>A customer has paid a deposit or signed.</li>
                            <li>You're holding a specific chassis for a fitment that's already been scoped.</li>
                            <li>The vehicle is allocated to a deal even though paperwork isn't final yet.</li>
                        </ul>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-semibold text-slate-900">What gets captured</p>
                        <ul class="mt-2 list-disc pl-5 space-y-1 text-xs text-slate-700">
                            <li><strong>Salesperson</strong> (optional but recommended)</li>
                            <li><strong>Customer name</strong> (required)</li>
                            <li>Customer <strong>phone</strong> and <strong>email</strong> (optional)</li>
                            <li>Date stamp (<em>reserved_at</em>) — survives through to sold for the timeline</li>
                        </ul>
                    </div>
                </div>

                <h4 class="mt-5 text-sm font-semibold text-slate-900">Reserve → Sold flow</h4>
                <ol class="mt-2 list-decimal pl-5 space-y-1.5">
                    <li>On the vehicle card click <strong>Reserve</strong>, enter salesperson + customer, save.</li>
                    <li>The card shows a <em>Reserved</em> panel with the customer details and timestamp.</li>
                    <li>When the deal closes, click <strong>Mark sold (from reserve)</strong> — the form is already pre-filled, just confirm.</li>
                    <li>If anything changes before the close, use <strong>Edit reserve</strong> or <strong>Clear reserve</strong>.</li>
                </ol>

                <h4 class="mt-5 text-sm font-semibold text-slate-900">Finding reserved units</h4>
                <ul class="mt-2 list-disc pl-5 space-y-1">
                    <li>Dashboard <strong>Reserved</strong> card — tap to filter <em>All stock</em>.</li>
                    <li>All stock → <strong>Reserved only</strong> button next to the status dropdown.</li>
                    <li>Salesperson filter pills — find every reservation by a specific sales rep.</li>
                </ul>
            </section>

            {{-- 5c. Fitment chain ------------------------------------------------ --}}
            <section id="fitment" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Fitment chain</h3>
                <p class="mt-2">
                    A single chassis often passes through <strong>several body builders</strong> in sequence — e.g. <em>dropside body</em> → <em>crane mount</em>, or <em>fridge body</em> → <em>fridge unit</em>. The fitment chain panel on the vehicle card tracks each stop separately, so each fitter has their own notes, their own internal job number, and their own sharing toggle.
                </p>

                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-semibold text-slate-900">Per-step fields</p>
                        <ul class="mt-2 list-disc pl-5 space-y-1 text-xs text-slate-700">
                            <li><strong>Body builder</strong> — the fitter for this step (must be linked to your dealership)</li>
                            <li><strong>Fitment type</strong> — short label (Dropside body, Crane mount, Fridge unit, ...)</li>
                            <li><strong>Notes</strong> — full spec for this leg (size, colour, accessories, dates, ...)</li>
                            <li><strong>Share with BB</strong> — independent toggle: ON means this fitter sees the shared details below</li>
                            <li><strong>Shared salesperson + end customer</strong> — only sent through if the toggle is ON</li>
                            <li><strong>Internal job number</strong> — written by the BB on their yard tablet, kept per leg</li>
                        </ul>
                    </div>
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                        <p class="text-sm font-semibold text-emerald-900">Step states</p>
                        <ul class="mt-2 list-disc pl-5 space-y-1 text-xs text-emerald-900">
                            <li><strong>Planned</strong> — queued; nothing has happened yet. Editable + deletable.</li>
                            <li><strong>In progress</strong> — vehicle is currently with this fitter (stamps <em>started_at</em>).</li>
                            <li><strong>Completed</strong> — fitter is done (stamps <em>completed_at</em>). Visible but not editable.</li>
                            <li><strong>Cancelled</strong> — the step won't happen; stays on the timeline for the audit trail.</li>
                        </ul>
                    </div>
                </div>

                <h4 class="mt-5 text-sm font-semibold text-slate-900">Adding a chain</h4>
                <ol class="mt-2 list-decimal pl-5 space-y-1.5">
                    <li>Open the vehicle card → <strong>+ Add fitment step</strong>.</li>
                    <li>Pick the body builder, type a fitment label, fill in the build notes.</li>
                    <li>Decide whether to <strong>Share these details with this body builder</strong>. If ON, also fill in the salesperson + end customer you want them to see.</li>
                    <li>Save — the step lands as <strong>Planned</strong> at the end of the chain.</li>
                    <li>Repeat for the next fitter (a second BB, etc.). Each leg is independent.</li>
                </ol>

                <h4 class="mt-5 text-sm font-semibold text-slate-900">Working the chain</h4>
                <ul class="mt-2 list-disc pl-5 space-y-1">
                    <li><strong>Start</strong> a step when the vehicle physically arrives at that BB. If another leg is still active, it auto-completes — only one leg is ever &ldquo;in progress&rdquo;.</li>
                    <li><strong>Complete</strong> a step when the BB is done. The next planned step becomes the obvious next click.</li>
                    <li><strong>Edit</strong> notes / sharing on a planned or in-progress step. Completed and cancelled steps are read-only.</li>
                    <li><strong>Delete</strong> only works on planned steps; completed legs are preserved for the audit trail (use Cancel instead).</li>
                </ul>

                <h4 class="mt-5 text-sm font-semibold text-slate-900">What the BB sees</h4>
                <p class="mt-1">
                    The BB's yard portal reads <em>only their own active leg</em>. If sharing is OFF for that leg, they see the chassis + their internal job number but nothing else. If sharing is ON, they see the fitment type, salesperson and end customer you chose. Other BBs on the chain never see another BB's notes.
                </p>
            </section>
            @endif {{-- $isDealer (sections 3-7: Dashboard / Stock / Vehicle / Reserve / Fitment) --}}

            {{-- 6. Movements ---------------------------------------------------- --}}
            <section id="orders" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Movements &amp; bookings</h3>

                <h4 class="mt-3 text-sm font-semibold text-slate-900">Book a delivery</h4>
                <p class="mt-1">
                    <a href="{{ route('customer.orders.create') }}" class="font-semibold text-blue-600 hover:text-blue-800">Open Book a delivery</a> —
                    choose pickup, delivery, vehicle, and an <strong>executor</strong>: ProSelver, your own driver, a 3rd-party transporter, or self-collect. Most dealers use ProSelver for long-distance work.
                </p>
                <p class="mt-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-900">
                    <strong>Tip:</strong> The fastest way to book a delivery is from the vehicle itself — open the stock row (or click <em>Book</em> on the row) and the VIN, pickup location, brand and model are pre-filled.
                </p>

                <h4 class="mt-4 text-sm font-semibold text-slate-900">My movements list</h4>
                <p class="mt-1">
                    <a href="{{ route('customer.orders.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Open My movements</a> —
                    lists every movement booked by your company.
                    @if($isDealer) It also includes movements where you are only the <strong>vehicle owner</strong> (body-builder direct orders) and need to approve, not pay. @endif
                </p>

                @if($isDealer)
                <p class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    An amber banner means there are direct orders from a body builder waiting for your owner approval. Use <a href="{{ route('customer.orders.index', ['owner_pending' => 1]) }}" class="font-semibold underline">Show only these</a> to focus.
                </p>
                <p class="mt-3 text-xs text-slate-600">When you are only the vehicle owner, <strong>pricing is hidden</strong> — you are approving the move, not paying for it.</p>
                <p class="mt-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                    <strong>When ProSelver moves a vehicle for you,</strong> you won't see any <em>Driver advance &amp; tolls</em> breakdown (advance total, tolls, food, taxi, the driver's cellphone for cash allocation) on the movement page. That's ProSelver's internal operational data — you paid a quoted line haul, so there's nothing for you to do there. The same block <strong>does</strong> appear when you run a movement with your <em>own</em> driver — it's your driver's cash plan to approve.
                </p>
                @endif

                <h4 class="mt-4 text-sm font-semibold text-slate-900">Bulk upload</h4>
                <p class="mt-1 text-xs text-slate-600">
                    Principals and similar roles can use <a href="{{ route('customer.orders.bulk-upload') }}" class="font-semibold text-blue-600 hover:text-blue-800">Bulk Upload</a> to submit many movements from a spreadsheet.
                </p>

                @if($isOem)
                <p class="mt-3 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-900">
                    OEM movements are pure logistics. There is no &ldquo;mark sold&rdquo;, &ldquo;reserve&rdquo; or commercial state on a movement &mdash; you book the move and we deliver. Once delivered, the vehicle is the dealer's stock and the dealer runs the sales lifecycle.
                </p>
                @endif
            </section>

            {{-- 7. Body builders ------------------------------------------------ --}}
            {{-- Dealer-only.  OEMs ship factory → dealer; they don't run a
                 body-builder relationship.  BBs themselves see this on
                 their own portal, not in the customer help guide. --}}
            @if($isDealer)
            <section id="bb" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Body builders — two flows</h3>
                <p class="mt-2">This is the most common source of confusion. There are <strong>two</strong> different ways a body builder can move your stock.</p>

                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4">
                        <p class="text-sm font-semibold text-blue-900">Movement request</p>
                        <p class="mt-1 text-xs text-blue-900">BB asks <strong>you</strong> to arrange the move. You book transport (ProSelver or your own driver) after approving.</p>
                        <p class="mt-2 text-xs text-blue-900">
                            <a href="{{ route('customer.movement-requests.index') }}" class="font-semibold underline">Open Movement Requests</a>
                        </p>
                    </div>
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <p class="text-sm font-semibold text-amber-900">Direct order</p>
                        <p class="mt-1 text-xs text-amber-900">BB books ProSelver themselves; you only approve the move as <strong>vehicle owner</strong>.</p>
                        <p class="mt-2 text-xs text-amber-900">
                            <a href="{{ route('customer.orders.index', ['owner_pending' => 1]) }}" class="font-semibold underline">Open owner approvals</a>
                        </p>
                    </div>
                </div>

                <h4 class="mt-4 text-sm font-semibold text-slate-900">Linking body builders</h4>
                <p class="mt-1 text-xs text-slate-700">
                    Dealer owners link authorised body builders so they can raise requests or direct orders against your stock.
                </p>
                <ul class="mt-2 list-disc pl-5 space-y-1 text-xs text-slate-700">
                    <li><a href="{{ route('customer.body-builders.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Linked Body Builders</a> — manage existing links.</li>
                    <li><a href="{{ route('customer.body-builders.requests.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Request a BB</a> — ask ProSelver to onboard a builder that isn't on the platform yet.</li>
                </ul>
            </section>
            @endif {{-- $isDealer (section 7: Body builders) --}}

            {{-- 8. Trips -------------------------------------------------------- --}}
            <section id="trips" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Trips &amp; drivers</h3>
                <p class="mt-2">
                    If your dealership has internal drivers, planners build trips on the <a href="{{ route('customer.trips.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Trip Planner</a>.
                    Drivers see their day under <a href="{{ route('customer.trips.my-day') }}" class="font-semibold text-blue-600 hover:text-blue-800">My Day</a>.
                </p>
                <p class="mt-2 text-xs text-slate-600">
                    Jobs that still need owner approval cannot be attached to a trip until approved.
                </p>
            </section>

            {{-- 9. Reports ------------------------------------------------------ --}}
            <section id="reports" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Reports &amp; resources</h3>
                <ul class="mt-2 list-disc pl-5 space-y-1">
                    <li><a href="{{ route('customer.reports.deliveries') }}" class="font-semibold text-blue-600 hover:text-blue-800">Deliveries report</a> — history and metrics.</li>
                    <li><a href="{{ route('customer.display') }}" class="font-semibold text-blue-600 hover:text-blue-800">Live wallboard</a> — opens in a new tab.</li>
                    <li><a href="{{ route('customer.documents') }}" class="font-semibold text-blue-600 hover:text-blue-800">Documents</a> — POs and files across orders.</li>
                    <li><a href="{{ route('customer.locations.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Address book</a> — pickup/delivery locations for bookings.</li>
                </ul>
            </section>

            {{-- 10. Account ----------------------------------------------------- --}}
            <section id="account" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Account settings</h3>
                <ul class="mt-2 list-disc pl-5 space-y-1">
                    @if(auth()->user()?->canManageCompanyData())
                        <li><a href="{{ route('customer.team.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Team</a> — add users and assign roles.</li>
                        <li><a href="{{ route('customer.settings.branding') }}" class="font-semibold text-blue-600 hover:text-blue-800">Branding</a> — logo and letterhead printed on your sale delivery notes.</li>
                        @if($isDealer)
                            <li><a href="{{ route('customer.drivers.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Drivers</a> — internal driver pool.</li>
                            <li><a href="{{ route('customer.petty-cash.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Petty cash</a> — own-driver expense tracking.</li>
                        @endif
                    @else
                        <li class="text-xs text-slate-600">Account settings (Team, Branding, Drivers, Petty Cash) are available to dealer owners and similar roles.</li>
                    @endif
                    <li><a href="{{ route('profile.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Your profile</a> — name, password, personal settings.</li>
                </ul>
            </section>

            {{-- 11. Quick reference --------------------------------------------- --}}
            <section id="quickref" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Quick reference — common tasks</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">I want to…</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Go to…</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @if($isDealer)
                                <tr><td class="px-3 py-2">See everything on my books</td><td class="px-3 py-2"><a href="{{ route('customer.stock.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Stock → All stock</a></td></tr>
                                <tr><td class="px-3 py-2">Reserve a vehicle for a customer</td><td class="px-3 py-2">Open the vehicle card → <em>Reserve</em></td></tr>
                                <tr><td class="px-3 py-2">See only reserved units</td><td class="px-3 py-2"><a href="{{ route('customer.stock.index', ['status' => \App\Models\DealerStock::STATUS_RESERVED]) }}" class="font-semibold text-blue-600 hover:text-blue-800">All stock → Reserved only</a></td></tr>
                                <tr><td class="px-3 py-2">See vehicles sold in the last 30 days</td><td class="px-3 py-2"><a href="{{ route('customer.stock.index', ['bucket' => 'recently_sold']) }}" class="font-semibold text-blue-600 hover:text-blue-800">All stock → Recently sold</a></td></tr>
                                <tr><td class="px-3 py-2">See only vehicles at a body builder</td><td class="px-3 py-2"><a href="{{ route('customer.stock.index', ['bucket' => 'body_builder']) }}" class="font-semibold text-blue-600 hover:text-blue-800">All stock → Body builder</a></td></tr>
                                <tr><td class="px-3 py-2">See what is on the road right now</td><td class="px-3 py-2"><a href="{{ route('customer.stock.index', ['bucket' => 'in_transit']) }}" class="font-semibold text-blue-600 hover:text-blue-800">In transit bucket</a></td></tr>
                                <tr><td class="px-3 py-2">Book a delivery for a specific VIN</td><td class="px-3 py-2">All stock row → <em>Book</em>, or vehicle card → <em>Book delivery</em></td></tr>
                            @endif
                            <tr><td class="px-3 py-2">Book ProSelver to move a vehicle</td><td class="px-3 py-2"><a href="{{ route('customer.orders.create') }}" class="font-semibold text-blue-600 hover:text-blue-800">Movements → Book a delivery</a></td></tr>
                            <tr><td class="px-3 py-2">Submit many movements from a spreadsheet</td><td class="px-3 py-2"><a href="{{ route('customer.orders.bulk-upload') }}" class="font-semibold text-blue-600 hover:text-blue-800">Movements → Bulk upload movements</a></td></tr>
                            <tr><td class="px-3 py-2">See my open and recent movements</td><td class="px-3 py-2"><a href="{{ route('customer.orders.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Movements → My movements</a></td></tr>
                            @if($isDealer)
                                <tr><td class="px-3 py-2">Approve a BB's &ldquo;please move this&rdquo; request</td><td class="px-3 py-2"><a href="{{ route('customer.movement-requests.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Movement Requests</a></td></tr>
                                <tr><td class="px-3 py-2">Approve a BB's ProSelver booking on my VIN</td><td class="px-3 py-2"><a href="{{ route('customer.orders.index', ['owner_pending' => 1]) }}" class="font-semibold text-blue-600 hover:text-blue-800">My Orders → owner approvals</a></td></tr>
                                <tr><td class="px-3 py-2">Mark a vehicle sold</td><td class="px-3 py-2">All stock → open the row → <em>Mark as sold</em></td></tr>
                                <tr><td class="px-3 py-2">Close a sale once the buyer has the keys</td><td class="px-3 py-2">Vehicle card → <em>Mark as delivered</em> (archives the row but keeps it in <em>Recently delivered</em>)</td></tr>
                                <tr><td class="px-3 py-2">Remove a mistake / test row</td><td class="px-3 py-2">Vehicle card → <em>Archive (mistake / test)</em> — hidden from delivery history</td></tr>
                                <tr><td class="px-3 py-2">Add one vehicle to stock (e.g. factory-direct to BB)</td><td class="px-3 py-2"><a href="{{ route('customer.stock.create') }}" class="font-semibold text-blue-600 hover:text-blue-800">Stock → + Add vehicle</a></td></tr>
                                <tr><td class="px-3 py-2">Import new stock in bulk</td><td class="px-3 py-2"><a href="{{ route('customer.stock.import') }}" class="font-semibold text-blue-600 hover:text-blue-800">Stock → Import stock</a></td></tr>
                            @endif
                            <tr><td class="px-3 py-2">Add a delivery address</td><td class="px-3 py-2"><a href="{{ route('customer.locations.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Resources → Address Book</a></td></tr>
                            <tr><td class="px-3 py-2">Upload a PO</td><td class="px-3 py-2">Open the order → documents section</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- 12. Glossary ---------------------------------------------------- --}}
            <section id="glossary" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Glossary</h3>
                <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div><dt class="text-sm font-semibold text-slate-900">Stock ledger</dt><dd class="text-xs text-slate-700">Your register of vehicles on the books.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Bucket</dt><dd class="text-xs text-slate-700">Where the system thinks the vehicle is (premises, BB, transit, etc.).</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Status</dt><dd class="text-xs text-slate-700">Commercial state: available, reserved, sold, demo, archived.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Reserve</dt><dd class="text-xs text-slate-700">Hold for a customer — assigns salesperson + buyer before sale.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Movement / order / job</dt><dd class="text-xs text-slate-700">A transport booking in ProSelver.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Recently sold</dt><dd class="text-xs text-slate-700">Sold and still on your books — handover not yet captured.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Mark as delivered</dt><dd class="text-xs text-slate-700">Happy-path close: stamps <em>delivered_at</em>, archives the row, lands it in <em>Recently delivered</em>.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Recently delivered</dt><dd class="text-xs text-slate-700">Delivered in the last 30 days. Archived from the active board, kept for your records.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Archive</dt><dd class="text-xs text-slate-700">Escape hatch for mistakes / test vehicles. Hides the row from delivery history.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Movement request</dt><dd class="text-xs text-slate-700">BB asks the dealer to arrange transport.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Direct order</dt><dd class="text-xs text-slate-700">BB books ProSelver; dealer approves as owner.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Owner approval</dt><dd class="text-xs text-slate-700">Dealer OK for someone else's booking against their VIN.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Group view</dt><dd class="text-xs text-slate-700">One login sees multiple sibling dealerships.</dd></div>
                </dl>
            </section>

            {{-- 13. Support ----------------------------------------------------- --}}
            <section id="support" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Getting help</h3>
                <ul class="mt-2 list-disc pl-5 space-y-1">
                    <li><strong>Access or permissions</strong> — contact your <strong>Dealer Owner</strong> or ProSelver operations.</li>
                    <li><strong>Wrong location on stock</strong> — check the related transport job; stock location follows job status. Stock controllers can correct details on the vehicle card.</li>
                    <li><strong>Missing body builder</strong> — use <em>Request a BB</em>, or ask ProSelver to onboard them.</li>
                    <li><strong>Technical issues</strong> — contact ProSelver support with your dealership name, VIN or order number, and a screenshot if possible.</li>
                </ul>

                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('customer.dashboard') }}" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-blue-500 transition">Back to Dashboard</a>
                    <a href="#nav" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">Top of guide</a>
                </div>
            </section>
        </article>
    </div>
</div>
