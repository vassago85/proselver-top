<?php
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

/*
 * Dealer-facing help / orientation page.
 *
 * Purely informational -- no Livewire state, no actions. The audience is
 * legacy dealer-tier users (dealer_principal, sales_manager_*, sales_person_*,
 * stock_controller) who landed on /dealer/dashboard and need to understand
 * the post-executor-rollout feature set: own-driver / 3rd-party / self-collect
 * bookings, internal drivers CRUD, trip planner, body-builder stock view,
 * archiving, and the deliveries report.
 *
 * Kept as a single long-form Volt page with anchor sections so dealers can
 * deep-link a colleague to a specific topic without us building a separate
 * docs site.
 */
new #[Layout('components.layouts.app')] class extends Component {};
?>

<div class="max-w-4xl mx-auto space-y-8">
    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Help &amp; Information</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Everything you need to know about running movements, drivers and reports from the dealer portal.</p>
    </div>

    {{-- What's new banner --}}
    <div class="rounded-xl border border-blue-200 bg-blue-50 p-6 dark:border-blue-900/40 dark:bg-blue-950/30">
        <div class="flex items-start gap-3">
            <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-600 text-white text-xs font-bold">!</span>
            <div class="space-y-2 text-sm">
                <h2 class="text-base font-semibold text-blue-900 dark:text-blue-100">New in this release: you can run your own movements</h2>
                <p class="text-blue-900/90 dark:text-blue-100/90">
                    Until recently every booking was a ProSelver-executed move. You can now book a movement and pick <strong>who actually does the move</strong>: ProSelver, one of <strong>your own drivers</strong>, a <strong>3rd-party courier</strong>, or as a <strong>self-collect</strong> by the customer. Your drivers live under <a class="underline" href="{{ route('customer.drivers.index') }}">My Drivers</a>, and you can chain a driver's day across multiple stops in the <a class="underline" href="{{ route('customer.trips.index') }}">Trip Planner</a>. The classic ProSelver-only booking is still one click away as <em>"ProSelver Booking (legacy)"</em>.
                </p>
            </div>
        </div>
    </div>

    {{-- Where do I find X? --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Where do I find&hellip;?</h2>
        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-2 text-sm">
            <p><a class="text-blue-600 hover:underline dark:text-blue-400" href="{{ route('customer.orders.create') }}">New Movement</a> <span class="text-zinc-500">&mdash; sidebar Movements &gt; New Movement</span></p>
            <p><a class="text-blue-600 hover:underline dark:text-blue-400" href="{{ route('dealer.bookings.create') }}">ProSelver Booking (legacy)</a> <span class="text-zinc-500">&mdash; classic ProSelver-only form</span></p>
            <p><a class="text-blue-600 hover:underline dark:text-blue-400" href="{{ route('dealer.bookings.index') }}">Bookings list</a> <span class="text-zinc-500">&mdash; every order for your dealership</span></p>
            <p><a class="text-blue-600 hover:underline dark:text-blue-400" href="{{ route('customer.stock.at-body-builder') }}">Stock In Transit</a> <span class="text-zinc-500">&mdash; vehicles at body builder, yard, or on the road</span></p>
            <p><a class="text-blue-600 hover:underline dark:text-blue-400" href="{{ route('customer.trips.index') }}">Trip Planner</a> <span class="text-zinc-500">&mdash; chain stops for one driver</span></p>
            <p><a class="text-blue-600 hover:underline dark:text-blue-400" href="{{ route('customer.trips.my-day') }}">My Day</a> <span class="text-zinc-500">&mdash; mobile driver view</span></p>
            <p><a class="text-blue-600 hover:underline dark:text-blue-400" href="{{ route('customer.drivers.index') }}">My Drivers</a> <span class="text-zinc-500">&mdash; CRUD for your driver pool</span></p>
            <p><a class="text-blue-600 hover:underline dark:text-blue-400" href="{{ route('customer.reports.deliveries') }}">Deliveries Report</a> <span class="text-zinc-500">&mdash; KPIs + CSV export</span></p>
            <p><a class="text-blue-600 hover:underline dark:text-blue-400" href="#delivery-note">Delivery Note PDF</a> <span class="text-zinc-500">&mdash; on each order page once a driver is assigned</span></p>
            <p><a class="text-blue-600 hover:underline dark:text-blue-400" href="{{ route('dealer.locations.index') }}">Address Book</a> <span class="text-zinc-500">&mdash; locations / depots</span></p>
            <p><a class="text-blue-600 hover:underline dark:text-blue-400" href="{{ route('dealer.team.index') }}">Team</a> <span class="text-zinc-500">&mdash; users in your account</span></p>
        </div>
    </div>

    {{-- Table of Contents --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Contents</h2>
        <nav class="mt-3 columns-1 sm:columns-2 gap-x-8">
            <ul class="space-y-2 text-sm">
                <li><a href="#executors" class="text-blue-600 hover:underline dark:text-blue-400">Who does the move? (executor types)</a></li>
                <li><a href="#new-movement" class="text-blue-600 hover:underline dark:text-blue-400">Booking a movement</a></li>
                <li><a href="#bulk-upload" class="text-blue-600 hover:underline dark:text-blue-400">Bulk upload</a></li>
                <li><a href="#my-drivers" class="text-blue-600 hover:underline dark:text-blue-400">My Drivers</a></li>
                <li><a href="#trips" class="text-blue-600 hover:underline dark:text-blue-400">Trip Planner &amp; My Day</a></li>
                <li><a href="#body-builder" class="text-blue-600 hover:underline dark:text-blue-400">Body builder stock</a></li>
                <li><a href="#archive" class="text-blue-600 hover:underline dark:text-blue-400">Archiving deliveries</a></li>
                <li><a href="#change-executor" class="text-blue-600 hover:underline dark:text-blue-400">Changing the executor or driver</a></li>
                <li><a href="#deliveries" class="text-blue-600 hover:underline dark:text-blue-400">Deliveries report</a></li>
                <li><a href="#driver-pwa" class="text-blue-600 hover:underline dark:text-blue-400">Driver PWA</a></li>
                <li><a href="#delivery-note" class="text-blue-600 hover:underline dark:text-blue-400">Delivery paperwork (PDF)</a></li>
                <li><a href="#locations" class="text-blue-600 hover:underline dark:text-blue-400">Managing locations</a></li>
                <li><a href="#cutoff" class="text-blue-600 hover:underline dark:text-blue-400">Collection date &amp; cutoff</a></li>
                <li><a href="#roles" class="text-blue-600 hover:underline dark:text-blue-400">Roles &amp; permissions</a></li>
                <li><a href="#troubleshooting" class="text-blue-600 hover:underline dark:text-blue-400">Troubleshooting</a></li>
            </ul>
        </nav>
    </div>

    {{-- Executor types --}}
    <section id="executors" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Who does the move? (executor types)</h2>
        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Every movement now records <em>who is actually moving the vehicle</em>. You pick this at booking time and can change it later until the vehicle is collected.</p>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="rounded-lg border border-blue-200 bg-blue-50/40 p-4 dark:border-blue-900/40 dark:bg-blue-950/20">
                <h3 class="text-sm font-semibold text-blue-900 dark:text-blue-100">ProSelver</h3>
                <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">We do the move with our own driver, our own tracker, and ProSelver pricing kicks in. This is the default and matches every legacy booking you've ever raised.</p>
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50/40 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/20">
                <h3 class="text-sm font-semibold text-emerald-900 dark:text-emerald-100">My Driver (internal)</h3>
                <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">One of your own drivers (from <a class="underline" href="{{ route('customer.drivers.index') }}">My Drivers</a>) is doing the move. No ProSelver pricing &mdash; you're running this yourself.</p>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50/40 p-4 dark:border-amber-900/40 dark:bg-amber-950/20">
                <h3 class="text-sm font-semibold text-amber-900 dark:text-amber-100">3rd-Party Courier</h3>
                <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">A courier company (DSV, Aramex, etc.) is moving it. You capture the courier name + waybill so it's traceable on the order and the Deliveries Report.</p>
            </div>
            <div class="rounded-lg border border-violet-200 bg-violet-50/40 p-4 dark:border-violet-900/40 dark:bg-violet-950/20">
                <h3 class="text-sm font-semibold text-violet-900 dark:text-violet-100">Self-Collect</h3>
                <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">The end customer is collecting the vehicle themselves. You capture the collector's name, phone and ID so you have a record of who took it.</p>
            </div>
        </div>

        <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">Pricing note: ProSelver-executed moves use zone-rate pricing as before. The other three executor types skip pricing entirely &mdash; the dealer / courier / end customer isn't being billed by us &mdash; but the route distance is still calculated so it shows in your reports.</p>
    </section>

    {{-- Booking a movement --}}
    <section id="new-movement" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Booking a movement</h2>
        <div class="mt-3 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>Go to <a class="underline" href="{{ route('customer.orders.create') }}"><strong>Movements &gt; New Movement</strong></a>. The form has the same flow as before with one extra step up top:</p>
            <ol class="list-decimal list-inside space-y-2 pl-2">
                <li><strong>Pick the executor</strong> &mdash; click one of the four radio cards (ProSelver / My Driver / 3rd-Party / Self-Collect). The form below adjusts to show only the fields relevant to that choice.</li>
                <li><strong>Pickup &amp; delivery locations</strong> &mdash; from your address book, or add a new one inline.</li>
                <li><strong>Destination type</strong> &mdash; one of four:
                    <ul class="list-disc list-inside ml-4 mt-1 space-y-0.5 text-xs">
                        <li><strong>Delivery</strong> &mdash; final hand-over to another dealer or to a customer. Only these orders can be archived once complete.</li>
                        <li><strong>Body Builder or Fitment</strong> &mdash; vehicle goes for any third-party work (full body builder, radio fitment, canopy on an LCV, accessories, etc.); stays on <a class="underline" href="#body-builder">Stock In Transit</a> until you book the return.</li>
                        <li><strong>Round Trip</strong> &mdash; COF, weighbridge, pre-delivery inspection &mdash; driver waits and brings the vehicle straight back. Route distance is doubled automatically.</li>
                        <li><strong>Other Storage Facility</strong> &mdash; any off-site holding location (not body builder, not final). Stays on Stock In Transit until booked out.</li>
                    </ul>
                </li>
                <li><strong>Vehicle</strong> &mdash; VIN is required, brand / model / class / registration as needed.</li>
                <li><strong>Collection date &amp; time</strong> &mdash; respects the same cutoff rules as before.</li>
                <li><strong>Executor-specific details</strong> &mdash; pick the driver from your pool (My Driver), enter the courier name + waybill (3rd-Party), or the collector's contact details (Self-Collect). ProSelver has no extra fields.</li>
                <li><strong>Submit</strong> &mdash; the order lands in your bookings list immediately.</li>
            </ol>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Tip: the classic ProSelver-only form lives at <em>"ProSelver Booking (legacy)"</em> in the sidebar &mdash; use it if you're 100% sure the move is ProSelver and don't want the executor picker to appear.</p>

            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800 dark:border-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200">
                <p class="font-semibold">No PO needed when you're using your own driver</p>
                <p class="mt-1">If the executor is <em>My Driver</em> or <em>Self-Collect</em>, the form hides the PO Number / PO Amount / PO Document fields entirely &mdash; there's no third party to raise a PO against. PO fields only appear for <em>ProSelver</em> (you're paying us) and <em>3rd-Party Courier</em> (you're paying them).</p>
            </div>
        </div>
    </section>

    {{-- Bulk upload --}}
    <section id="bulk-upload" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Bulk upload</h2>
        <div class="mt-3 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>Bulk-uploading from CSV / XLSX now understands executor columns. None of them are required &mdash; if you leave them out, every row defaults to ProSelver (the same behaviour as before).</p>
            <ul class="list-disc list-inside space-y-1 pl-2">
                <li><code>executor_type</code> &mdash; <code>proselver</code>, <code>internal</code>, <code>3rd_party</code>, or <code>self_collect</code>. Free-form aliases like "inhouse", "courier", "self collect" are also accepted.</li>
                <li><code>driver_name</code> &mdash; matched against your internal driver pool (case-insensitive). Unmatched names are flagged on the preview screen so you can fix them before commit.</li>
                <li><code>courier_name</code>, <code>waybill</code> &mdash; for 3rd-party rows.</li>
                <li><code>collector_name</code>, <code>collector_phone</code> &mdash; for self-collect rows.</li>
            </ul>
            <p>On the mapping step you can also set a <strong>default executor</strong> &mdash; rows that leave the executor column blank pick that up. The preview table shows the resolved executor with a colour-coded badge so you can scan for surprises before clicking Import.</p>
        </div>
    </section>

    {{-- My Drivers --}}
    <section id="my-drivers" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">My Drivers</h2>
        <div class="mt-3 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>The <a class="underline" href="{{ route('customer.drivers.index') }}"><strong>My Drivers</strong></a> page is where you maintain your own driver pool &mdash; the people you can pick from when booking an "internal" executor movement.</p>
            <ul class="list-disc list-inside space-y-1 pl-2">
                <li><strong>Add a driver</strong> &mdash; name, contact, ID / passport, licence code + expiry, optional PrDP expiry. A username + password is created at the same time so they can log in.</li>
                <li><strong>Edit / deactivate</strong> &mdash; full CRUD, with deactivation as a soft-disable rather than a hard delete so audit history stays intact.</li>
                <li><strong>Driver login</strong> &mdash; once added, the driver can sign into the <a class="underline" href="{{ route('driver.dashboard') }}">Driver PWA</a> with the credentials you set and see any jobs you've assigned them.</li>
            </ul>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Only the dealer principal or someone with admin-grade role can add or edit drivers. Sales staff can pick from the existing pool when booking but can't add new drivers.</p>
        </div>
    </section>

    {{-- Trip Planner & My Day --}}
    <section id="trips" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Trip Planner &amp; My Day</h2>
        <div class="mt-3 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>A <strong>trip</strong> is one driver's day plan, chained together from multiple jobs plus optional non-job stops (COF check, weighbridge, fuel, positioning legs between job locations). It replaces the old "one driver, one job at a time" mental model so you can plan a full day's run in one place.</p>

            <h3 class="font-semibold text-zinc-900 dark:text-white">Building a trip (dispatcher view)</h3>
            <ol class="list-decimal list-inside space-y-1 pl-2">
                <li>Go to <a class="underline" href="{{ route('customer.trips.index') }}">Trip Planner</a>, click <em>New Trip</em>, pick a driver and a date.</li>
                <li>On the trip's page, drag in jobs from the <em>"Unassigned jobs"</em> side panel &mdash; each one adds a pickup stop and a dropoff stop in the right order.</li>
                <li>Use <em>"Insert waypoint"</em> to add COF, weighbridge, fuel or positioning legs between jobs.</li>
                <li>Reorder stops with the up / down arrows; the planner enforces that a job's pickup always sits before its dropoff.</li>
                <li>When you're happy, click <em>Start trip</em> &mdash; the driver sees it on their PWA immediately.</li>
            </ol>

            <h3 class="mt-3 font-semibold text-zinc-900 dark:text-white">My Day (driver view)</h3>
            <p>Drivers open <a class="underline" href="{{ route('customer.trips.my-day') }}">My Day</a> on their phone to see today's stops in sequence. They tap <em>Arrived</em> and <em>Departed</em> on each stop; for job-linked stops that automatically transitions the job status (pickup-departure &rarr; <code>COLLECTED</code>, dropoff-departure &rarr; <code>DELIVERED</code>) so you don't have to touch the order to keep status fresh.</p>

            <p class="text-xs text-zinc-500 dark:text-zinc-400">A driver can only have one active trip per date &mdash; if you need to re-plan, cancel the existing trip first or soft-delete it from the trip's actions menu.</p>
        </div>
    </section>

    {{-- Stock in transit --}}
    <section id="body-builder" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Stock in transit</h2>
        <div class="mt-3 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>If a vehicle isn't sitting at your dealership right now, it still belongs to you &mdash; and you need a single place to see where it actually is. That's the <a class="underline" href="{{ route('customer.stock.at-body-builder') }}">Stock In Transit</a> page. It rolls up three buckets:</p>
            <ul class="list-disc list-inside space-y-1 pl-2">
                <li><strong>At body builder / fitment</strong> &mdash; you delivered the vehicle for body-builder work, radio / canopy / accessory fitment, or any other third-party work; it's waiting for the work to finish and a return movement.</li>
                <li><strong>At yard / holding</strong> &mdash; you delivered to a yard or other holding location (transit stop); the vehicle hasn't reached its final dealer yet.</li>
                <li><strong>In transit</strong> &mdash; an active movement that's been collected or is on the road right now (any executor type).</li>
            </ul>
            <p>Each row shows the current location, the driver (for in-transit), and a one-click <em>Book Return / Next Move</em> button on the parked rows that pre-fills the create-order form with pickup = current location, VIN preserved.</p>
            <p>A row drops off automatically once the next movement is delivered (destination = <strong>Delivery</strong>), or when the order is archived.</p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Body builder / round trip / other-storage rows are kept out of the <em>Archive</em> flow on purpose &mdash; archiving a vehicle that hasn't actually left your stock would hide it while it's still your problem.</p>
        </div>
    </section>

    {{-- Archive --}}
    <section id="archive" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Archiving deliveries</h2>
        <div class="mt-3 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>When a final <strong>Delivery</strong> is done and dusted &mdash; the vehicle has left your books for good &mdash; you can <strong>archive</strong> it. Archiving hides the order from your active Bookings list so the day-to-day view stays clean, but the row stays in the database and still shows up on the <a class="underline" href="{{ route('customer.reports.deliveries') }}">Deliveries Report</a>.</p>
            <ul class="list-disc list-inside space-y-1 pl-2">
                <li>Open the order, click <em>Archive</em> &mdash; available once the job is in a <code>DELIVERED</code> / <code>COMPLETED</code> state <strong>and</strong> the destination type is <em>Delivery</em>. Body Builder / Round Trip / Other Storage Facility orders can't be archived because the vehicle hasn't really left your stock yet.</li>
                <li>To bring it back, your Bookings list has a <em>"Show archived"</em> toggle &mdash; flip it on, open the order, click <em>Unarchive</em>.</li>
                <li>Only dealer admin / owner can archive or unarchive; sales staff can see the action but not perform it.</li>
            </ul>
        </div>
    </section>

    {{-- Change executor / driver --}}
    <section id="change-executor" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Changing the executor or driver after booking</h2>
        <div class="mt-3 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>Plans change. On any order that hasn't been collected yet you have two safety valves:</p>
            <ul class="list-disc list-inside space-y-1 pl-2">
                <li><strong>Change Executor</strong> &mdash; flip an order from (say) "My Driver" to "ProSelver" if a driver calls in sick and you'd rather ProSelver run it. The form re-prompts you for the new executor's specific fields.</li>
                <li><strong>Assign / Reassign Driver</strong> &mdash; on internal-executor jobs, pick a different driver from your pool. ProSelver-executed jobs draw from ProSelver's driver pool and only ops can reassign those.</li>
            </ul>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Once the vehicle is in flight (collected / in transit) both actions are locked &mdash; if you genuinely need to change executor mid-trip, contact ops.</p>
        </div>
    </section>

    {{-- Deliveries Report --}}
    <section id="deliveries" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Deliveries Report</h2>
        <div class="mt-3 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>The <a class="underline" href="{{ route('customer.reports.deliveries') }}"><strong>Deliveries Report</strong></a> is your single view of every vehicle that has actually been delivered for your dealership, regardless of executor or destination type.</p>
            <ul class="list-disc list-inside space-y-1 pl-2">
                <li><strong>KPIs</strong> at the top &mdash; total delivered, distinct customers, distinct models, executor-mix breakdown for the selected window.</li>
                <li><strong>Filters</strong> &mdash; date range, brand, vehicle class, executor type, destination type, trip, and an "include archived" toggle.</li>
                <li><strong>Top customers breakdown</strong> &mdash; who's receiving the most stock, with share-of-volume bars.</li>
                <li><strong>Detail table</strong> &mdash; one row per delivered VIN with collection / delivery dates, executor, destination, driver / courier / collector name where relevant.</li>
                <li><strong>CSV export</strong> &mdash; streamed so you can run multi-thousand-row exports without timing out. Includes executor + archived + trip columns.</li>
            </ul>
        </div>
    </section>

    {{-- Driver PWA --}}
    <section id="driver-pwa" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Driver PWA</h2>
        <div class="mt-3 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>Every driver you add to your pool gets full access to the same Driver PWA that ProSelver's drivers use. They sign in with the credentials you set on the <a class="underline" href="{{ route('customer.drivers.index') }}">My Drivers</a> page, then get:</p>
            <ul class="list-disc list-inside space-y-1 pl-2">
                <li><strong>Today's jobs</strong> &mdash; everything assigned to that driver, in sequence.</li>
                <li><strong>Offline support</strong> &mdash; the PWA installs to home screen, queues photo uploads and event timestamps in IndexedDB, and syncs when connectivity returns.</li>
                <li><strong>Photo capture</strong> &mdash; collection / damage / delivery photos with automatic compression.</li>
                <li><strong>Documents</strong> &mdash; collection notes and damage reports auto-generated as PDFs and emailed / downloadable from the order page.</li>
                <li><strong>My Day</strong> &mdash; the trip-planner view when you've grouped multiple stops onto the driver.</li>
            </ul>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">There's no separate dealer-driver app &mdash; same software, same features, just scoped to whatever jobs you've assigned them.</p>
        </div>
    </section>

    {{-- Delivery paperwork --}}
    <section id="delivery-note" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Delivery paperwork</h2>
        <div class="mt-3 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>Whenever a driver is assigned to a movement that <strong>your dealership is running</strong> (My Driver / 3rd-Party / Self-Collect), the system can generate a 5-page <strong>Delivery Note</strong> PDF for the driver / courier / collector to take with them. It contains:</p>
            <ol class="list-decimal list-inside space-y-1 pl-2">
                <li><strong>Delivery Note</strong> &mdash; movement reference, driver details, vehicle (VIN / reg / model), pickup &amp; delivery sites, special instructions, "Released By" signature block + dispatcher's stamp box, "Received By (Driver)" signature block, and a QR code linking to the verification page.</li>
                <li><strong>Manual Inspection Report</strong> &mdash; full Motorvia-style exterior / interior / accessories checklist + a damage diagram. Drivers use this only if the PWA isn't available; otherwise the photos in the app are the inspection record.</li>
                <li><strong>Proof of Delivery (Customer Copy)</strong> &mdash; signed at delivery, leaves with the receiving site.</li>
                <li>Blank back page so the Customer Copy walks away clean if you print double-sided.</li>
                <li><strong>Proof of Delivery (Office Copy)</strong> &mdash; signed at delivery, stays with you.</li>
            </ol>
            <p>The masthead, "Carrier" rows and footer all carry <strong>your dealership's name</strong> (not ProSelver's) on My-Driver / 3rd-Party / Self-Collect movements &mdash; so the paperwork is unambiguously yours when it lands at the customer.</p>
            <p>You'll see a <em>Delivery Note PDF</em> button on the order page (next to the executor badge) the moment a driver is assigned. The same PDF is downloadable from the Driver PWA so the driver can pull it themselves if you didn't print it for them.</p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">For ProSelver-executed jobs the button still reads <em>"Collection Note PDF"</em> and is restricted to ProSelver ops &mdash; ProSelver issues that paperwork, not your dealership.</p>
        </div>
    </section>

    {{-- Locations --}}
    <section id="locations" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Managing locations</h2>
        <div class="mt-3 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>The <a class="underline" href="{{ route('dealer.locations.index') }}"><strong>Address Book</strong></a> is your private pool of pickup / delivery / depot locations.</p>
            <ul class="list-disc list-inside space-y-1 pl-2">
                <li>Add new locations with a company name and full physical address; the system geocodes them so route distance calculations work on every booking.</li>
                <li>Tag a location as a depot, body builder, customer site, etc. so it sorts cleanly in the location pickers.</li>
                <li>Deactivate locations you no longer use &mdash; they stop appearing in booking forms but historical orders that reference them stay intact.</li>
            </ul>
            <p>Locations are exclusive to your dealership &mdash; other companies on the platform can't see them.</p>
        </div>
    </section>

    {{-- Cutoff --}}
    <section id="cutoff" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Collection date &amp; cutoff</h2>
        <div class="mt-3 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <p>Collection date and time can be edited freely <strong>until the daily cutoff</strong>. After cutoff, ops takes over scheduling:</p>
            <ul class="list-disc list-inside space-y-1 pl-2">
                <li>For ProSelver-executed jobs, use the <em>Request Date Change</em> button to send a request to ops &mdash; you'll get an email when it's accepted or declined.</li>
                <li>For internal / 3rd-party / self-collect jobs you can keep editing the date yourself since the move is yours to run &mdash; the cutoff is only enforced when ProSelver is the executor.</li>
            </ul>
        </div>
    </section>

    {{-- Roles --}}
    <section id="roles" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Roles &amp; permissions</h2>
        <div class="mt-4 space-y-4 text-sm text-zinc-700 dark:text-zinc-300">
            <div>
                <h3 class="font-medium text-zinc-900 dark:text-white">Dealer Principal</h3>
                <p>Top-level. Manages team, drivers, address book, roles &amp; permissions, all bookings, can archive / unarchive deliveries and change executors. Full sight of the Deliveries Report.</p>
            </div>
            <div>
                <h3 class="font-medium text-zinc-900 dark:text-white">Stock Controller</h3>
                <p>Admin-grade like the principal for movement / driver / body-builder workflows &mdash; manages the driver pool, can plan trips, archive deliveries, and run the Deliveries Report. Doesn't manage roles &amp; permissions.</p>
            </div>
            <div>
                <h3 class="font-medium text-zinc-900 dark:text-white">Sales Manager (New / Used)</h3>
                <p>Books movements (any executor), assigns drivers on internal jobs, can plan trips and run the Deliveries Report. Oversees the sales team's bookings.</p>
            </div>
            <div>
                <h3 class="font-medium text-zinc-900 dark:text-white">Sales Person (New / Used)</h3>
                <p>Books movements and views their own. Can pick a driver from the existing pool on internal jobs but can't add new drivers or change executors after booking.</p>
            </div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">The <a class="underline" href="{{ route('dealer.settings.roles') }}">Roles &amp; Permissions</a> page is where the dealer principal tunes exactly which permissions each role holds. Purchase Order and FAW-style customer-confirmation permissions are intentionally hidden &mdash; POs come from your accounting system, and customer-confirmation is an OEM workflow.</p>
        </div>
    </section>

    {{-- Troubleshooting --}}
    <section id="troubleshooting" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Troubleshooting</h2>
        <div class="mt-3 space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
            <div>
                <h3 class="font-medium text-zinc-900 dark:text-white">My driver doesn't appear in the picker</h3>
                <p>Check <a class="underline" href="{{ route('customer.drivers.index') }}">My Drivers</a> &mdash; the driver needs to be <em>active</em> AND attached to your dealership. Reactivate them or re-attach via the edit form.</p>
            </div>
            <div>
                <h3 class="font-medium text-zinc-900 dark:text-white">My driver can't log into the PWA</h3>
                <p>The PWA only lets in users with the <em>driver</em> role. If you added them from My Drivers, that role is assigned automatically &mdash; double-check the username / password are exactly what you set. If the password was forgotten, edit them and set a new one.</p>
            </div>
            <div>
                <h3 class="font-medium text-zinc-900 dark:text-white">Bulk upload flagged a row as "driver unmatched"</h3>
                <p>The spreadsheet had a driver name that doesn't match anyone in your pool. Either correct the spelling in the file and re-upload, or use the per-row dropdown on the preview screen to pick the right driver before clicking Import.</p>
            </div>
            <div>
                <h3 class="font-medium text-zinc-900 dark:text-white">A vehicle is still showing on Stock In Transit after it came back</h3>
                <p>The return movement needs to be marked <code>DELIVERED</code> &mdash; once that happens, the row drops off the body-builder / yard list automatically. If the return move was completed but doesn't drop off, ping ops.</p>
            </div>
            <div>
                <h3 class="font-medium text-zinc-900 dark:text-white">Can't find a delivered order in my Bookings list</h3>
                <p>It's probably archived. Flip the <em>"Show archived"</em> toggle on the Bookings page; once visible, open it and click <em>Unarchive</em> if you need it back in the active list. The <a class="underline" href="{{ route('customer.reports.deliveries') }}">Deliveries Report</a> always shows archived rows.</p>
            </div>
            <div>
                <h3 class="font-medium text-zinc-900 dark:text-white">Still stuck?</h3>
                <p>Contact ops via your usual channel &mdash; or shoot us an email and we'll loop the right person in.</p>
            </div>
        </div>
    </section>
</div>
