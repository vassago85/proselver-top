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
            <h2 class="mt-1 text-xl sm:text-2xl font-semibold">Dealer Portal — how it works</h2>
            <p class="mt-2 max-w-3xl text-sm text-slate-200">
                A quick reference to the screens you use every day: tracking stock, booking transport,
                working with body builders, and managing your team. Use the menu on the left to jump to a section.
            </p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[240px,1fr] gap-6">
        {{-- Sticky TOC --}}
        <aside class="lg:sticky lg:top-4 lg:self-start">
            <nav class="rounded-xl border border-slate-200 bg-white p-3 text-sm" aria-label="Help table of contents">
                <p class="px-2 pb-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">On this page</p>
                <ul class="space-y-0.5">
                    @php
                        $toc = [
                            ['nav', 'Finding your way around'],
                            ['roles', 'Roles &amp; what they can do'],
                            ['dashboard', 'Dashboard'],
                            ['stock', 'Stock — three views'],
                            ['vehicle', 'Vehicle card actions'],
                            ['orders', 'Orders &amp; movements'],
                            ['bb', 'Body builders — two flows'],
                            ['trips', 'Trips &amp; drivers'],
                            ['reports', 'Reports &amp; resources'],
                            ['account', 'Account settings'],
                            ['quickref', 'Quick reference'],
                            ['glossary', 'Glossary'],
                            ['support', 'Getting help'],
                        ];
                    @endphp
                    @foreach($toc as [$id, $label])
                        <li>
                            <a href="#{{ $id }}" class="block rounded-md px-2 py-1.5 text-slate-700 hover:bg-slate-50 hover:text-slate-900">{!! $label !!}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <div class="mt-4 rounded-xl border border-blue-200 bg-blue-50 p-4 text-xs text-blue-900">
                <p class="font-semibold">Stuck?</p>
                <p class="mt-1">Ask your <strong>Dealer Owner</strong> for an access change, or contact ProSelver support with your dealership name and a VIN or order number.</p>
            </div>
        </aside>

        {{-- Content --}}
        <article class="space-y-10 text-sm leading-6 text-slate-800">

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
                            <tr><td class="px-3 py-2 font-medium">Orders</td><td class="px-3 py-2">Dashboard, new orders, bulk upload, order list</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Stock</td><td class="px-3 py-2">Full stock ledger and the off-site / in-transit view</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Trips</td><td class="px-3 py-2">Plan trips for your own drivers; drivers use <em>My Day</em></td></tr>
                            <tr><td class="px-3 py-2 font-medium">Reports</td><td class="px-3 py-2">Deliveries report and the live wallboard</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Resources</td><td class="px-3 py-2">Documents and the address book</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Body Builders</td><td class="px-3 py-2">Movement requests, linked BBs, request a new BB</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Account</td><td class="px-3 py-2">Team, branding, drivers, petty cash</td></tr>
                        </tbody>
                    </table>
                </div>

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
            <section id="dashboard" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Dashboard</h3>
                <p class="mt-2">
                    <a href="{{ route('customer.dashboard') }}" class="font-semibold text-blue-600 hover:text-blue-800">Open the dashboard</a> —
                    seven cards showing where every vehicle on your books is. Tap a card to jump straight into the matching filter on <em>All stock</em>.
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
                            <tr><td class="px-3 py-2 font-medium">At body builder / fitment</td><td class="px-3 py-2">Parked at a linked BB</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Scheduled for movement</td><td class="px-3 py-2">Transport booked; collection not started</td></tr>
                            <tr><td class="px-3 py-2 font-medium">In transit</td><td class="px-3 py-2">On the road with an active job</td></tr>
                            <tr><td class="px-3 py-2 font-medium">At another storage</td><td class="px-3 py-2">Parked at another yard</td></tr>
                            <tr><td class="px-3 py-2 font-medium">On demo with customer</td><td class="px-3 py-2">Out on demo</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Recently sold</td><td class="px-3 py-2">Marked sold in the last 30 days (may still be in transit)</td></tr>
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
                    <li><strong>Recently sold</strong> — sold in the last 30 days (may still be in transit).</li>
                    <li><strong>Handed over</strong> — the customer-delivery step has been recorded on the ledger.</li>
                </ul>
                <p class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    <strong>Watch:</strong> &ldquo;Recently sold&rdquo; and &ldquo;Handed over&rdquo; are not the same. A unit can be sold but still in transit.
                </p>

                @if($isDealer)
                <h4 class="mt-5 text-sm font-semibold text-slate-900">Importing stock</h4>
                <p class="mt-1 text-xs text-slate-600">
                    If you have <em>Manage Dealer Stock</em>, use <a href="{{ route('customer.stock.import') }}" class="font-semibold text-blue-600 hover:text-blue-800">Import stock</a> to upload a CSV of vehicles onto the ledger.
                </p>
                @endif
            </section>

            {{-- 5. Vehicle card ------------------------------------------------- --}}
            <section id="vehicle" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Vehicle card actions</h3>
                <p class="mt-2">From <em>All stock</em>, click any row to open the vehicle card. What you can do depends on your permissions.</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Action</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">What it does</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr><td class="px-3 py-2 font-medium">Mark as sold</td><td class="px-3 py-2">Records salesperson + customer, sets status to sold</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Send out on demo</td><td class="px-3 py-2">Captures customer + due-back date, swings to <em>On demo</em></td></tr>
                            <tr><td class="px-3 py-2 font-medium">Return from demo</td><td class="px-3 py-2">Brings the unit back from demo</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Mark handed over</td><td class="px-3 py-2">Customer handover complete</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Reverse sale</td><td class="px-3 py-2">Undoes a sale while still allowed</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Archive</td><td class="px-3 py-2">Removes from active dashboards (soft archive)</td></tr>
                            <tr><td class="px-3 py-2 font-medium">Body builder details</td><td class="px-3 py-2">Optional fields the BB sees when the vehicle is on their premises</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- 6. Orders ------------------------------------------------------- --}}
            <section id="orders" class="scroll-mt-6">
                <h3 class="text-lg font-semibold text-slate-900">Orders &amp; movements</h3>

                <h4 class="mt-3 text-sm font-semibold text-slate-900">Creating an order</h4>
                <p class="mt-1">
                    <a href="{{ route('customer.orders.create') }}" class="font-semibold text-blue-600 hover:text-blue-800">New order</a> —
                    choose pickup, delivery, vehicle, and an <strong>executor</strong>: ProSelver, your own driver, courier, or self-collect. Most dealers use ProSelver for long-distance work.
                </p>

                <h4 class="mt-4 text-sm font-semibold text-slate-900">My Orders list</h4>
                <p class="mt-1">
                    <a href="{{ route('customer.orders.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Open My Orders</a> —
                    lists movements where your dealership is the booking customer <em>and</em> movements where you are only the <strong>vehicle owner</strong>.
                </p>
                <p class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    The amber banner means there are direct orders from a body builder waiting for your owner approval. Use <a href="{{ route('customer.orders.index', ['owner_pending' => 1]) }}" class="font-semibold underline">Show only these</a> to focus.
                </p>

                <p class="mt-3 text-xs text-slate-600">When you are only the vehicle owner, <strong>pricing is hidden</strong> — you are approving the move, not paying for it.</p>

                @if($isDealer)
                <h4 class="mt-4 text-sm font-semibold text-slate-900">Bulk upload</h4>
                <p class="mt-1 text-xs text-slate-600">
                    Principals and similar roles can use <a href="{{ route('customer.orders.bulk-upload') }}" class="font-semibold text-blue-600 hover:text-blue-800">Bulk Upload</a> to submit many movements from a spreadsheet.
                </p>
                @endif
            </section>

            {{-- 7. Body builders ------------------------------------------------ --}}
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
                    <li><a href="{{ route('profile.edit') }}" class="font-semibold text-blue-600 hover:text-blue-800">Your profile</a> — name, password, personal settings.</li>
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
                            <tr><td class="px-3 py-2">See everything on my books</td><td class="px-3 py-2"><a href="{{ route('customer.stock.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Stock → All stock</a></td></tr>
                            <tr><td class="px-3 py-2">See only vehicles at a body builder</td><td class="px-3 py-2"><a href="{{ route('customer.stock.index', ['bucket' => 'body_builder']) }}" class="font-semibold text-blue-600 hover:text-blue-800">All stock → Body builder</a></td></tr>
                            <tr><td class="px-3 py-2">See what is on the road right now</td><td class="px-3 py-2"><a href="{{ route('customer.stock.index', ['bucket' => 'in_transit']) }}" class="font-semibold text-blue-600 hover:text-blue-800">In transit bucket</a></td></tr>
                            <tr><td class="px-3 py-2">Book ProSelver to move a vehicle</td><td class="px-3 py-2"><a href="{{ route('customer.orders.create') }}" class="font-semibold text-blue-600 hover:text-blue-800">Orders → New Order</a></td></tr>
                            <tr><td class="px-3 py-2">Approve a BB's &ldquo;please move this&rdquo; request</td><td class="px-3 py-2"><a href="{{ route('customer.movement-requests.index') }}" class="font-semibold text-blue-600 hover:text-blue-800">Movement Requests</a></td></tr>
                            <tr><td class="px-3 py-2">Approve a BB's ProSelver booking on my VIN</td><td class="px-3 py-2"><a href="{{ route('customer.orders.index', ['owner_pending' => 1]) }}" class="font-semibold text-blue-600 hover:text-blue-800">My Orders → owner approvals</a></td></tr>
                            <tr><td class="px-3 py-2">Mark a vehicle sold</td><td class="px-3 py-2">All stock → open the row → <em>Mark as sold</em></td></tr>
                            <tr><td class="px-3 py-2">Record customer handover</td><td class="px-3 py-2">Vehicle card → <em>Mark handed over</em></td></tr>
                            @if($isDealer)
                                <tr><td class="px-3 py-2">Import new stock</td><td class="px-3 py-2"><a href="{{ route('customer.stock.import') }}" class="font-semibold text-blue-600 hover:text-blue-800">All stock → Import stock</a></td></tr>
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
                    <div><dt class="text-sm font-semibold text-slate-900">Bucket</dt><dd class="text-xs text-slate-700">Where the system thinks the vehicle is.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Movement / order / job</dt><dd class="text-xs text-slate-700">A transport booking in ProSelver.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Handed over</dt><dd class="text-xs text-slate-700">Customer-delivery step recorded on the ledger.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Recently sold</dt><dd class="text-xs text-slate-700">Sold in the last 30 days.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Movement request</dt><dd class="text-xs text-slate-700">BB asks the dealer to arrange transport.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Direct order</dt><dd class="text-xs text-slate-700">BB books ProSelver; dealer approves as owner.</dd></div>
                    <div><dt class="text-sm font-semibold text-slate-900">Owner approval</dt><dd class="text-xs text-slate-700">Dealer OK for someone else's booking against their VIN.</dd></div>
                    <div class="sm:col-span-2"><dt class="text-sm font-semibold text-slate-900">Group view</dt><dd class="text-xs text-slate-700">One login sees multiple sibling dealerships.</dd></div>
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
