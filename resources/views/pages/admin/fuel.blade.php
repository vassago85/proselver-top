<?php

use App\Services\Tfn\Exceptions\TfnException;
use App\Services\Tfn\TfnClient;
use App\Services\Tfn\TfnDemoFixtures;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * TFN Fuel Operations — single-screen ops + diesel ordering.
 *
 * Everything an ops controller needs to run a shift against the TFN
 * network in one page:
 *   - Balance / credit / month-to-date litres headline
 *   - Live diesel product pricing
 *   - Place a pre-authorisation order for a specific vehicle
 *   - Recent transactions (from the pump)
 *   - Vehicles with their current virtual card numbers + expiries
 *   - Open pre-authorisation orders (with cancel)
 *
 * When TFN credentials are absent (or TFN_DEMO_MODE=true) the page reads
 * from `TfnDemoFixtures` so it's fully demonstrable to non-technical
 * stakeholders. The Blade template does NOT care which source produced
 * the arrays -- shapes match the TFN swagger exactly.
 */
new #[Layout('components.layouts.app')] class extends Component {

    // Form state for placing an order.  Kept as public properties (not
    // wire:model.live) so an accidental Enter mid-typing doesn't fire
    // the request -- the operator explicitly hits "Place Order".
    //
    // `orderRegistration` + `orderReference` are URL-bound so any
    // "Order fuel" deep-link (from the vehicles list, dispatch, trip
    // detail, etc.) lands here with the form pre-filled. Query keys
    // are shortened to `vehicle` and `ref` so the URL is human-
    // readable when it appears in email / Slack / bookmarks.
    #[Url(as: 'vehicle', except: '')] public string $orderRegistration = '';
    // Proselver's policy is 50ppm only, and `tfn.orderable_products`
    // currently contains just D0. Default matches -- if the config
    // grows we take whichever code is listed first.
    public string $orderProductCode  = 'D0';
    public string $orderLitres       = '';
    public string $orderDepotId      = '';
    public string $orderExpiresAt    = '';
    #[Url(as: 'ref', except: '')]     public string $orderReference    = '';

    // Filter state for the recent-transactions table.
    public string $txWindow = '24h'; // '24h' | '7d' | '30d'

    // Cached flash of the last connectivity check so the header pill
    // updates without a full re-render.
    public ?array $ping = null;

    public function mount(): void
    {
        // Only internal staff (ops controller, dispatcher, accounts,
        // owner, developer, super admin) should see fuel operations --
        // customers and dealers must never land here.
        if (!auth()->user()?->isInternal() && !auth()->user()?->isDeveloper()) {
            abort(403);
        }

        // Sensible default: order expires end of the next business day
        // in SAST. The operator can override before submit.
        $this->orderExpiresAt = now()->addDay()->endOfDay()->format('Y-m-d\TH:i');
    }

    /**
     * Whether the current viewer is allowed to see fuel FINANCE data --
     * running balance, credit limit, total spend, per-transaction and
     * per-order rand amounts.  Ops (controller / dispatcher) should be
     * able to place pre-authorisation orders without being able to see
     * the aggregate spend or the account balance -- that stays owner
     * territory.
     *
     * The per-order rand estimate on the place-order form is a
     * separate concern and stays visible to everyone: it's the cost
     * of the specific action the operator is about to authorise,
     * not an aggregate.
     */
    public function canSeeFinance(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        return $u->isOwner()
            || $u->isDeveloper()
            || $u->isSuperAdmin()
            || $u->isAccounts();
    }

    /**
     * Router for reads: hits TfnClient when live, falls back to
     * fixtures otherwise. Kept as one method so the view never has to
     * branch on "live vs demo".
     */
    private function source(): array
    {
        $client = app(TfnClient::class);
        $fixtures = app(TfnDemoFixtures::class);

        // Determine live vs demo without swallowing legitimate errors:
        // an outright configuration miss => demo silently; a real HTTP
        // failure => log + fall through to demo but flag the banner.
        $isLive = false;
        $flag = null;
        try {
            $isLive = $client->isLive();
        } catch (\Throwable $e) {
            $flag = $e->getMessage();
        }

        if (!$isLive) {
            return [
                'live'       => false,
                'banner'     => $flag ?? 'TFN not configured — showing demo data. Set TFN_ENABLED=true and populate TFN_USERNAME / TFN_PASSWORD / TFN_CUSTOMER_NUMBER in .env to switch to live QA.',
                'balance'    => $fixtures->balance(),
                'aggregate'  => $fixtures->aggregateLitres(),
                'pricing'    => $fixtures->pricing(),
                'depots'     => $fixtures->depots(),
                'vehicles'   => $fixtures->vehicles(),
                'cards'      => $fixtures->virtualCards(),
                'orders'     => $fixtures->orders(),
                'transactions' => $fixtures->transactions(),
            ];
        }

        // Live path. Wrap each read individually so one flaky endpoint
        // doesn't take the whole screen down -- the operator can still
        // see everything else and refresh.
        $safe = fn (callable $fn, mixed $fallback = []) => $this->safely($fn, $fallback);

        return [
            'live'         => true,
            'banner'       => null,
            'balance'      => $safe(fn () => $client->subAccountBalance(), $fixtures->balance()),
            'aggregate'    => $safe(fn () => $client->subAccountAggregateLitres(), $fixtures->aggregateLitres()),
            'pricing'      => $safe(fn () => $this->pricingBundle($client), $fixtures->pricing()),
            'depots'       => $safe(fn () => $client->depots(), $fixtures->depots()),
            'vehicles'     => $safe(fn () => $client->vehicles(), $fixtures->vehicles()),
            'cards'        => $safe(fn () => $this->cardsForVehicles($client), $fixtures->virtualCards()),
            'orders'       => $safe(fn () => $client->orders(), $fixtures->orders()),
            'transactions' => $safe(fn () => $client->transactions($this->txWindowStart()), $fixtures->transactions()),
        ];
    }

    /**
     * TFN's /api/Pricing accepts one product code per call and returns
     * per-depot pricing across the network (diesel is not a single
     * national price -- see https://tfn.co.za/our-network/).  We fan
     * out over the products we care about and flatten to a single
     * list of rows keyed by product + depot so the "Live pricing"
     * panel can show the whole spread.
     *
     * The endpoint's response shape varies: sometimes it comes back
     * as an object per row (with DepotID / DepotTitle / PricePerLitre)
     * and sometimes as a bare array. Normalise to one flat list.
     */
    private function pricingBundle(TfnClient $client): array
    {
        $out = [];
        foreach (config('tfn.orderable_products', []) as $code => $label) {
            try {
                $response = $client->pricing($code);
                // Tolerate both { rows: [...] }, [...] and { ...single }.
                $rows = match (true) {
                    isset($response[0])                          => $response,
                    isset($response['rows'])                     => $response['rows'],
                    isset($response['PricePerLitre'])            => [$response],
                    default                                      => [],
                };
                foreach ($rows as $row) {
                    $out[] = [
                        'ProductCode'   => $code,
                        'Label'         => $label,
                        'DepotID'       => $row['DepotID'] ?? null,
                        'DepotTitle'    => $row['DepotTitle'] ?? $row['SupplierName'] ?? '—',
                        'PricePerLitre' => (float) ($row['PricePerLitre'] ?? $row['Price'] ?? 0),
                        'AsOf'          => $row['AsOf'] ?? now()->toIso8601String(),
                    ];
                }
            } catch (TfnException $e) {
                // Skip this product -- others will still render.
            }
        }
        // Cheapest first: guides the planner to the best-priced depot
        // when they read the panel top-down.
        usort($out, fn ($a, $b) => $a['PricePerLitre'] <=> $b['PricePerLitre']);
        return $out;
    }

    private function cardsForVehicles(TfnClient $client): array
    {
        $vehicles = $client->vehicles();
        $out = [];
        foreach ($vehicles as $v) {
            // Anchor on VIN -- new-from-plant units don't have a
            // permanent registration yet. When TFN's lookup needs a
            // registration string (their v3 endpoint currently does)
            // we fall back to it, otherwise pass VIN.
            $vin = $v['VIN'] ?? null;
            $reg = $v['Registration'] ?? null;
            $key = $vin ?: $reg;
            if (!$key) continue;
            try {
                $out[$key] = $client->virtualCardNumber($reg ?: $vin);
            } catch (TfnException $e) {
                // Vehicle exists but no active card -- show a placeholder
                // so the operator knows to reissue.
                $out[$key] = null;
            }
        }
        return $out;
    }

    private function safely(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);
            return $fallback;
        }
    }

    private function txWindowStart(): \DateTimeImmutable
    {
        return match ($this->txWindow) {
            '7d'  => Carbon::now()->subDays(7)->toDateTimeImmutable(),
            '30d' => Carbon::now()->subDays(30)->toDateTimeImmutable(),
            default => Carbon::now()->subDay()->toDateTimeImmutable(),
        };
    }

    /**
     * "Refresh" button -- forgets only the TFN cache entries (never a
     * blanket flush, which would evict sessions since we share a Redis
     * DB) then re-runs Ping to bounce the connection pill. The list of
     * keys is kept in sync with `TfnClient::cacheKey()` -- when we add
     * a new cached endpoint there, add the suffix here too.
     */
    public function refresh(): void
    {
        $prefix = config('tfn.cache_prefix', 'tfn:');
        $customer = config('tfn.customer_number');
        foreach (['depots', 'vehicles', 'balance'] as $suffix) {
            \Illuminate\Support\Facades\Cache::forget("{$prefix}{$suffix}:{$customer}");
        }
        foreach (array_keys(config('tfn.orderable_products', [])) as $code) {
            \Illuminate\Support\Facades\Cache::forget("{$prefix}pricing:{$code}:{$customer}");
        }

        $client = app(TfnClient::class);
        if ($client->isLive()) {
            try {
                $this->ping = $client->ping();
            } catch (\Throwable $e) {
                $this->ping = ['status' => 'error', 'latency_ms' => 0, 'timestamp' => null];
            }
        }
        session()->flash('success', 'TFN data refreshed.');
    }

    /**
     * Clear the order form without touching connection state.
     */
    public function clearOrderForm(): void
    {
        $this->reset(['orderRegistration', 'orderLitres', 'orderReference', 'orderDepotId']);
        // Reset the product to the first orderable code (D0 today).
        $this->orderProductCode = (string) (array_key_first(config('tfn.orderable_products', ['D0' => ''])) ?: 'D0');
        $this->orderExpiresAt = now()->addDay()->endOfDay()->format('Y-m-d\TH:i');
    }

    public function testConnection(): void
    {
        $client = app(TfnClient::class);
        if (!$client->isLive()) {
            $this->ping = ['status' => 'demo', 'latency_ms' => 0, 'timestamp' => null];
            session()->flash('info', 'TFN is in demo mode -- no live connection is being made.');
            return;
        }
        try {
            $this->ping = $client->ping();
            session()->flash('success', 'Connected to TFN ('.$this->ping['latency_ms'].' ms).');
        } catch (TfnException $e) {
            $this->ping = ['status' => 'error', 'latency_ms' => 0, 'timestamp' => null];
            session()->flash('error', $e->getMessage());
        }
    }

    public function setTxWindow(string $w): void
    {
        $this->txWindow = in_array($w, ['24h', '7d', '30d'], true) ? $w : '24h';
    }

    public function selectVehicleForOrder(string $registration): void
    {
        $this->orderRegistration = $registration;
    }

    /**
     * Client-side validation only -- TFN does its own business rules
     * server-side and will 400 with a helpful Message field if the
     * order breaches the sub-account's limits.
     */
    public function placeOrder(): void
    {
        $litres = (float) $this->orderLitres;

        if (blank($this->orderRegistration)) {
            session()->flash('error', 'Pick a vehicle before placing an order.');
            return;
        }
        if (!isset(config('tfn.orderable_products')[$this->orderProductCode])) {
            session()->flash('error', 'Choose a valid diesel product.');
            return;
        }
        if ($litres <= 0 || $litres > 2000) {
            session()->flash('error', 'Litres must be between 1 and 2000.');
            return;
        }
        if (blank($this->orderExpiresAt) || Carbon::parse($this->orderExpiresAt)->isPast()) {
            session()->flash('error', 'Order expiry must be in the future.');
            return;
        }

        $payload = [
            'CustomerNumber'      => config('tfn.customer_number'),
            'VehicleRegistration' => $this->orderRegistration,
            'ProductCode'         => $this->orderProductCode,
            'Litres'              => $litres,
            'ExpiresAt'           => Carbon::parse($this->orderExpiresAt)->toIso8601String(),
            'DepotID'             => $this->orderDepotId ?: null,
            'Reference'           => $this->orderReference ?: null,
        ];

        $client = app(TfnClient::class);

        if (!$client->isLive()) {
            // Demo mode: pretend we posted successfully. The next
            // page render still reads from fixtures so the newly-
            // "placed" order won't appear in the Open Orders table --
            // that's fine, this is a walkthrough tool.
            session()->flash('success', sprintf(
                '(Demo) Order placed: %d L of %s against %s. Expires %s.',
                (int) $litres,
                $this->orderProductCode,
                $this->orderRegistration,
                Carbon::parse($this->orderExpiresAt)->format('D d M H:i'),
            ));
            $this->reset(['orderLitres', 'orderReference']);
            return;
        }

        try {
            $client->createOrder($payload);
            session()->flash('success', sprintf('Order placed: %d L of %s against %s.', (int) $litres, $this->orderProductCode, $this->orderRegistration));
            $this->reset(['orderLitres', 'orderReference']);
        } catch (TfnException $e) {
            session()->flash('error', 'TFN rejected the order: ' . $e->getMessage());
        }
    }

    public function cancelOrderEntry(string $entryNumber): void
    {
        $client = app(TfnClient::class);
        if (!$client->isLive()) {
            session()->flash('success', "(Demo) Order entry {$entryNumber} cancelled.");
            return;
        }
        try {
            $client->deleteOrderEntry($entryNumber);
            session()->flash('success', "Order entry {$entryNumber} cancelled.");
        } catch (TfnException $e) {
            session()->flash('error', 'Could not cancel order: ' . $e->getMessage());
        }
    }

    /**
     * Pre-render aggregations. We compute derived values here (rather
     * than in the Blade) so the template stays declarative.
     */
    public function with(): array
    {
        $data = $this->source();

        // Totals for the KPI strip.
        $litresMtd = collect($data['aggregate'])->sum('Litres');
        $spendToday = collect($data['transactions'])
            ->filter(fn ($t) => \Illuminate\Support\Carbon::parse($t['TransactionDate'] ?? $t['CapturedDate'] ?? now())->isToday())
            ->sum(fn ($t) => abs((float) ($t['Amount'] ?? 0)));

        // Product mix summary (litres by fuel grade this month).
        $productMix = collect($data['aggregate'])
            ->groupBy('ProductCode')
            ->map(fn ($rows) => $rows->sum('Litres'))
            ->toArray();

        // Merge vehicles with their card + last-txn to power a single
        // fleet table row per vehicle. VIN is the durable identifier
        // (registration is often null on new-from-plant units), so
        // we index every lookup by VIN.
        $lastTxByVehicle = collect($data['transactions'])->groupBy('VIN');

        $fleet = collect($data['vehicles'])->map(function ($v) use ($data, $lastTxByVehicle) {
            $vin = $v['VIN'] ?? '';
            $card = $data['cards'][$vin] ?? null;
            // optional() only guards ONE method call -- optional(null)->foo()
            // returns null, and calling ->bar() on that then blows up.
            // Vehicles with zero transactions in-window would hit exactly
            // that path.  Use PHP's null-safe operator so the whole chain
            // short-circuits to null cleanly.
            $lastTx = $lastTxByVehicle->get($vin)?->sortByDesc('CapturedDate')->first();
            return [
                'vin'          => $vin,
                'registration' => $v['Registration'] ?? null,
                'customer'     => $v['CustomerName'] ?? null,
                'brand'        => $v['Brand'] ?? null,
                'model'        => $v['Model'] ?? null,
                'job_number'   => $v['ExternalNumber'] ?? null,
                'tank_size'    => $v['TankSize'] ?? null,
                'status_code'  => $v['Status'] ?? null,
                'card_number'  => $card['VirtualCardNumber'] ?? null,
                'card_expires' => $card['ExpiryDate'] ?? null,
                'last_tx_at'   => $lastTx['CapturedDate'] ?? null,
                'last_tx_l'    => $lastTx['Litres'] ?? null,
                'last_tx_dep'  => $lastTx['SupplierName'] ?? null,
            ];
        })->all();

        $openOrders = collect($data['orders'])
            ->filter(fn ($o) => strcasecmp($o['Status'] ?? '', 'open') === 0)
            ->values()
            ->all();

        $utilisedOrders = collect($data['orders'])
            ->filter(fn ($o) => strcasecmp($o['Status'] ?? '', 'open') !== 0)
            ->values()
            ->all();

        return [
            'live'           => $data['live'],
            'banner'         => $data['banner'],
            'balance'        => $data['balance'],
            'litresMtd'      => $litresMtd,
            'spendToday'     => $spendToday,
            'productMix'     => $productMix,
            'pricing'        => $data['pricing'],
            'depots'         => $data['depots'],
            'vehicles'       => $data['vehicles'],
            'fleet'          => $fleet,
            'openOrders'     => $openOrders,
            'utilisedOrders' => $utilisedOrders,
            'transactions'   => $data['transactions'],
            'productLabels'  => config('tfn.product_labels', []),
            'orderableProducts' => config('tfn.orderable_products', []),
            'canSeeFinance'  => $this->canSeeFinance(),
        ];
    }
}; ?>

<div class="space-y-6">
    <x-slot:header>TFN Fuel Operations</x-slot:header>

    {{-- ────────── Header strip: environment + connection state ────────── --}}
    <div class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap items-center gap-3">
            @if($live)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Live · {{ parse_url(config('tfn.base_url'), PHP_URL_HOST) }}
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span> Demo mode
                </span>
            @endif

            <span class="text-xs text-slate-500">
                Customer <span class="font-semibold text-slate-700">{{ config('tfn.customer_number') ?: 'demo/10021' }}</span>
                · API v{{ config('tfn.api_version') }}
            </span>

            @if($ping)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-50 px-3 py-1 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-200">
                    @if($ping['status'] === 'ok')
                        <svg class="h-3 w-3 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                        Ping OK · {{ $ping['latency_ms'] }} ms
                    @elseif($ping['status'] === 'demo')
                        Demo — no ping
                    @else
                        <svg class="h-3 w-3 text-rose-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        Ping failed
                    @endif
                </span>
            @endif
        </div>

        <div class="flex items-center gap-2">
            <button wire:click="testConnection" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                Test connection
            </button>
            <button wire:click="refresh" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-slate-800">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/></svg>
                Refresh
            </button>
        </div>
    </div>

    @if($banner)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            <div class="flex items-start gap-2">
                <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                <div>{{ $banner }}</div>
            </div>
        </div>
    @endif

    @if(session('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if(session('info'))
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">{{ session('info') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    {{-- ────────── KPI strip ────────── --}}
    {{-- Litres month-to-date is operational (dispatch cares about
         throughput per sub-account) so it's visible to everyone.
         Balance / credit limit / rand spend are FINANCE data --
         hidden from ops / dispatcher, only owner + accounts + dev +
         super_admin see them. See `canSeeFinance()`. --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 {{ $canSeeFinance ? 'lg:grid-cols-4' : 'lg:grid-cols-2' }}">
        @if($canSeeFinance)
            <x-stat-card
                label="Sub-account balance"
                :value="'R ' . number_format((float) ($balance['Balance'] ?? 0), 2)"
                color="blue"
                :helper="'Available credit R ' . number_format((float) ($balance['AvailableCredit'] ?? 0), 2)"
                helperColor="slate"
            />
            <x-stat-card
                label="Credit limit"
                :value="'R ' . number_format((float) ($balance['CreditLimit'] ?? 0), 2)"
                color="indigo"
                :helper="'Set by TFN — request change via account manager'"
                helperColor="slate"
            />
        @endif
        <x-stat-card
            label="Litres · month-to-date"
            :value="number_format((float) $litresMtd) . ' L'"
            color="emerald"
            :helper="collect($productMix)->map(fn($l, $c) => $c.': '.number_format($l).' L')->implode(' · ')"
            helperColor="emerald"
        />
        @if($canSeeFinance)
            <x-stat-card
                label="Fuel spend today"
                :value="'R ' . number_format((float) $spendToday, 2)"
                color="amber"
                :helper="count($transactions) . ' transactions in last ' . $txWindow"
                helperColor="amber"
            />
        @else
            <x-stat-card
                label="Transactions · last {{ $txWindow }}"
                :value="(string) count($transactions)"
                color="amber"
                helper="Placed orders + pump activity across the fleet"
                helperColor="amber"
            />
        @endif
    </div>

    {{-- ────────── Live pricing + Place order ────────── --}}
    @php
        // Group per-product so we can print a range headline for
        // each diesel grade. Today Proselver runs D0 only, but the
        // structure supports more grades if that policy relaxes.
        $pricingByProduct = collect($pricing)->groupBy('ProductCode');
        // Per-depot lookup keyed by title so the order-form estimate
        // can grab the exact R/L when a depot is selected.
        $priceByDepotProduct = [];
        foreach ($pricing as $p) {
            $priceByDepotProduct[($p['DepotTitle'] ?? '').'|'.$p['ProductCode']] = (float) ($p['PricePerLitre'] ?? 0);
        }
    @endphp
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-1">
            <div class="border-b border-slate-100 p-4">
                <h2 class="text-sm font-semibold text-slate-900">Network pricing</h2>
                <p class="mt-0.5 text-xs text-slate-500">Diesel price varies by depot &mdash; cheapest first. Use this to route the driver to a better-priced stop when the trip window allows.</p>
            </div>

            {{-- Range headline per product code (usually just D0) --}}
            @foreach($pricingByProduct as $code => $rows)
                @php
                    $min = $rows->min('PricePerLitre');
                    $max = $rows->max('PricePerLitre');
                    $avg = $rows->avg('PricePerLitre');
                    $cheapest = $rows->sortBy('PricePerLitre')->first();
                    $priciest = $rows->sortByDesc('PricePerLitre')->first();
                @endphp
                <div class="border-b border-slate-100 bg-slate-50/60 px-4 py-3">
                    <div class="flex items-baseline justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $code }} · {{ $productLabels[$code] ?? '' }}</p>
                            <p class="mt-1 text-[11px] text-slate-500 tabular-nums">
                                Range <span class="font-semibold text-emerald-700">R {{ number_format($min, 2) }}</span>
                                &ndash; <span class="font-semibold text-rose-700">R {{ number_format($max, 2) }}</span>
                                <span class="text-slate-400">· avg R {{ number_format($avg, 2) }}</span>
                            </p>
                        </div>
                        <p class="text-[10px] uppercase tracking-widest text-slate-400">{{ count($rows) }} depots</p>
                    </div>
                    <p class="mt-1.5 text-[11px] text-slate-500">
                        Cheapest: <span class="font-medium text-slate-800">{{ $cheapest['DepotTitle'] }}</span>
                        · Priciest: <span class="font-medium text-slate-800">{{ $priciest['DepotTitle'] }}</span>
                        · Spread <span class="tabular-nums font-medium text-slate-800">R {{ number_format($max - $min, 2) }}/L</span>
                    </p>
                </div>
            @endforeach

            {{-- Per-depot list. Bounded height so the panel stays
                 compact but scrollable when the network grows. --}}
            <div class="max-h-96 overflow-y-auto divide-y divide-slate-100">
                @forelse($pricing as $row)
                    @php
                        $delta = isset($avg) ? ((float) $row['PricePerLitre']) - $avg : 0;
                        $deltaClass = $delta < 0 ? 'text-emerald-600' : ($delta > 0 ? 'text-rose-600' : 'text-slate-400');
                        $sign = $delta > 0 ? '+' : '';
                    @endphp
                    <div class="flex items-center justify-between px-4 py-2.5">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900">{{ $row['DepotTitle'] ?? '—' }}</p>
                            <p class="text-[11px] text-slate-500">{{ $row['ProductCode'] }} · <span class="{{ $deltaClass }} tabular-nums">{{ $sign }}{{ number_format($delta, 2) }}</span> vs avg</p>
                        </div>
                        <p class="shrink-0 text-sm font-semibold tabular-nums text-slate-900">R {{ number_format((float) ($row['PricePerLitre'] ?? 0), 2) }}</p>
                    </div>
                @empty
                    <div class="p-4 text-sm text-slate-500">No pricing available.</div>
                @endforelse
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
            <div class="border-b border-slate-100 p-4">
                <div class="flex items-center gap-2">
                    <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3h5l2 2v14a2 2 0 0 1-2 2h-5"/><path d="M9 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4"/><path d="M12 3v18"/><path d="M12 12l3-3"/><path d="M12 12l-3-3"/></svg>
                    <h2 class="text-sm font-semibold text-slate-900">Place a diesel order</h2>
                </div>
                <p class="mt-0.5 text-xs text-slate-500">Pre-authorises the pump for a specific vehicle. The order is burnt down by transactions when the driver fuels up.</p>
            </div>

            <form wire:submit="placeOrder" class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-700">Vehicle in transit</label>
                    {{-- Value is the VIN because most new-from-plant
                         units don't have a permanent plate yet. The
                         VIN is what TFN's card is bound to on their
                         side, so this doubles as the vehicle identifier
                         we pass to /api/Orders. --}}
                    <select wire:model="orderRegistration" class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        <option value="">— Select a vehicle —</option>
                        @foreach($vehicles as $v)
                            <option value="{{ $v['VIN'] ?: $v['Registration'] }}">
                                @if(!empty($v['CustomerName'])){{ $v['CustomerName'] }} · @endif{{ trim(($v['Brand'] ?? '').' '.($v['Model'] ?? '')) ?: 'Unknown model' }}
                                @if(!empty($v['VIN'])) · VIN {{ $v['VIN'] }} @endif
                                @if(!empty($v['Registration'])) · plate {{ $v['Registration'] }} @else · no plate @endif
                                @if(!empty($v['ExternalNumber'])) · {{ $v['ExternalNumber'] }} @endif
                                @if(!empty($v['TankSize'])) · {{ $v['TankSize'] }} L tank @endif
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-[10px] text-slate-500">Each customer trip has its own TFN virtual card &mdash; the order authorises fuel against that VIN only.</p>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-700">Product</label>
                    <select wire:model.live="orderProductCode" class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        @foreach($orderableProducts as $code => $label)
                            <option value="{{ $code }}">{{ $code }} — {{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-700">
                        Litres
                        @php
                            // Show a tank-size hint next to the input when
                            // we actually know it. Customer trucks off the
                            // plant often have no tank spec on the delivery
                            // note -- in that case we don't guess.
                            // $orderRegistration holds the VIN (see picker
                            // above); fall back to Registration for older
                            // rows that still key on plate.
                            $selectedVehicle = collect($vehicles)->firstWhere('VIN', $orderRegistration)
                                ?? collect($vehicles)->firstWhere('Registration', $orderRegistration);
                            $knownTank = $selectedVehicle['TankSize'] ?? null;
                        @endphp
                        @if($knownTank)
                            <span class="text-[10px] font-normal text-slate-400">· tank {{ $knownTank }} L</span>
                        @elseif($orderRegistration)
                            <span class="text-[10px] font-normal text-slate-400">· tank size unknown &mdash; confirm with the driver</span>
                        @endif
                    </label>
                    <input wire:model.live.debounce.300ms="orderLitres" type="number" min="1" max="2000" step="1" placeholder="e.g. 400" class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm tabular-nums focus:border-blue-500 focus:ring-1 focus:ring-blue-500" />
                    @php
                        // Estimate uses (in priority order):
                        //   1. price at the depot the operator has picked
                        //   2. network average across depots for this product
                        //   3. zero (no pricing available yet)
                        // Displaying "at depot X" vs "network avg" is
                        // important -- it tells the operator whether the
                        // total they're about to authorise is anchored to
                        // a specific pump or a rough network estimate.
                        $productPrices = collect($pricing)->where('ProductCode', $orderProductCode);
                        $selectedDepot = collect($depots)->firstWhere('DepotID', $orderDepotId);
                        $selectedDepotTitle = $selectedDepot['Title'] ?? null;
                        $depotPrice = $selectedDepotTitle
                            ? ($priceByDepotProduct[$selectedDepotTitle.'|'.$orderProductCode] ?? null)
                            : null;
                        $networkAvg = $productPrices->avg('PricePerLitre') ?? 0;
                        $selectedPrice = $depotPrice ?? $networkAvg;
                        $priceSource = $depotPrice ? 'at '.$selectedDepotTitle : 'network avg';
                        $estimated = ((float) $orderLitres) * (float) $selectedPrice;
                    @endphp
                    <p class="mt-1 text-xs text-slate-500">
                        At R {{ number_format((float) $selectedPrice, 2) }}/L <span class="text-slate-400">({{ $priceSource }})</span>, estimated total
                        <span class="font-semibold text-slate-800 tabular-nums">R {{ number_format($estimated, 2) }}</span>
                    </p>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-700">Preferred depot <span class="text-slate-400">(optional)</span></label>
                    <select wire:model="orderDepotId" class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        <option value="">Any TFN depot</option>
                        @foreach($depots as $d)
                            <option value="{{ $d['DepotID'] ?? $d['Number'] ?? '' }}">
                                {{ $d['Title'] ?? 'Depot' }} @if(!empty($d['Number'])) · #{{ $d['Number'] }} @endif
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-700">Expires at</label>
                    <input wire:model="orderExpiresAt" type="datetime-local" class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500" />
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-700">Trip / Job reference <span class="text-slate-400">(optional but recommended)</span></label>
                    <input wire:model="orderReference" type="text" placeholder="e.g. TRIP-JHB-DBN-0812 or job number" class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500" />
                    <p class="mt-1 text-xs text-slate-500">Stored as the order reference so the resulting transaction reconciles cleanly to a Trident trip.</p>
                </div>

                <div class="sm:col-span-2 flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" wire:click="clearOrderForm" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Clear</button>
                    <button type="submit" wire:loading.attr="disabled" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                        Place order
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ────────── Open pre-authorisation orders ────────── --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-100 p-4">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Open pre-authorisation orders</h2>
                <p class="mt-0.5 text-xs text-slate-500">Pre-approved fuel awaiting the driver at the pump. Cancel any order that's no longer valid.</p>
            </div>
            <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20">{{ count($openOrders) }} open</span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Order #</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Customer</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">VIN</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Reg</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Product</th>
                        <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Litres</th>
                        @if($canSeeFinance)
                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Amount</th>
                        @endif
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Depot</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Placed</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Expires</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Job #</th>
                        <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($openOrders as $o)
                        @php
                            // Substring match so full OEM names (e.g.
                            // "Isuzu Motors SA", "FAW South Africa")
                            // still colour-code correctly.
                            $customer = strtolower($o['CustomerName'] ?? '');
                            $customerChip = match(true) {
                                str_contains($customer, 'faw')       => 'bg-red-50 text-red-700 ring-red-600/20',
                                str_contains($customer, 'isuzu')     => 'bg-sky-50 text-sky-700 ring-sky-600/20',
                                str_contains($customer, 'powerstar') => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
                                default                              => 'bg-slate-100 text-slate-700 ring-slate-300',
                            };
                            // Short label so "FAW South Africa" fits
                            // the small chip; full name goes in the
                            // tooltip.
                            $customerShort = match(true) {
                                str_contains($customer, 'faw')       => 'FAW',
                                str_contains($customer, 'isuzu')     => 'Isuzu',
                                str_contains($customer, 'powerstar') => 'Powerstar',
                                default                              => $o['CustomerName'] ?? '',
                            };
                        @endphp
                        <tr class="hover:bg-slate-50/50">
                            <td class="px-4 py-3 text-sm font-mono text-slate-900">{{ $o['OrderNumber'] ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if(!empty($o['CustomerName']))
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 ring-inset {{ $customerChip }}" title="{{ $o['CustomerName'] }}">{{ $customerShort }}</span>
                                @else
                                    <span class="text-slate-400 text-xs">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-[11px] text-slate-600">{{ $o['VIN'] ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if(!empty($o['VehicleRegistration']))
                                    <span class="inline-flex rounded bg-yellow-100 border border-yellow-300 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-yellow-900">{{ $o['VehicleRegistration'] }}</span>
                                @else
                                    <span class="text-[11px] italic text-slate-400">no plate</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-700">{{ $o['ProductCode'] ?? '—' }} <span class="text-xs text-slate-400">· {{ $productLabels[$o['ProductCode'] ?? ''] ?? '' }}</span></td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-900">{{ number_format((float) ($o['Litres'] ?? 0)) }} L</td>
                            @if($canSeeFinance)
                                <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-900">R {{ number_format((float) ($o['Amount'] ?? 0), 2) }}</td>
                            @endif
                            <td class="px-4 py-3 text-sm text-slate-600">{{ $o['DepotTitle'] ?? 'Any' }}</td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ \Illuminate\Support\Carbon::parse($o['PlacedAt'] ?? now())->format('d M · H:i') }}</td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ \Illuminate\Support\Carbon::parse($o['ExpiresAt'] ?? now())->format('d M · H:i') }}</td>
                            <td class="px-4 py-3 text-xs font-mono text-slate-500">{{ $o['Reference'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <button wire:click="cancelOrderEntry('{{ $o['EntryNumber'] ?? '' }}')" wire:confirm="Cancel this pre-authorisation? The driver will not be able to fuel against it."
                                        class="rounded-md border border-rose-200 bg-white px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50">
                                    Cancel
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canSeeFinance ? 13 : 12 }}" class="px-4 py-8 text-center text-sm text-slate-500">No open pre-authorisations. Use the form above to place one.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ────────── Recent transactions ────────── --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Recent transactions</h2>
                <p class="mt-0.5 text-xs text-slate-500">Captured spend from the TFN network — auto-imported when we go live; sourced from webhook once configured.</p>
            </div>
            <div class="inline-flex rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
                @foreach(['24h' => 'Last 24h', '7d' => 'Last 7 days', '30d' => 'Last 30 days'] as $w => $lbl)
                    <button wire:click="setTxWindow('{{ $w }}')" class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors {{ $txWindow === $w ? 'bg-slate-900 text-white' : 'text-slate-600 hover:text-slate-900' }}">{{ $lbl }}</button>
                @endforeach
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Time</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Customer</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">VIN</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Reg</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Job #</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Depot</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Product</th>
                        <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Litres</th>
                        @if($canSeeFinance)
                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Amount</th>
                        @endif
                        <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Odometer</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Ref</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($transactions as $t)
                        @php
                            $customer = strtolower($t['CustomerName'] ?? '');
                            $customerChip = match(true) {
                                str_contains($customer, 'faw')       => 'bg-red-50 text-red-700 ring-red-600/20',
                                str_contains($customer, 'isuzu')     => 'bg-sky-50 text-sky-700 ring-sky-600/20',
                                str_contains($customer, 'powerstar') => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
                                default                              => 'bg-slate-100 text-slate-700 ring-slate-300',
                            };
                            $customerShort = match(true) {
                                str_contains($customer, 'faw')       => 'FAW',
                                str_contains($customer, 'isuzu')     => 'Isuzu',
                                str_contains($customer, 'powerstar') => 'Powerstar',
                                default                              => $t['CustomerName'] ?? '',
                            };
                        @endphp
                        <tr class="hover:bg-slate-50/50">
                            <td class="px-4 py-2.5 text-xs text-slate-500">{{ \Illuminate\Support\Carbon::parse($t['CapturedDate'] ?? $t['TransactionDate'] ?? now())->format('d M · H:i') }}</td>
                            <td class="px-4 py-2.5">
                                @if(!empty($t['CustomerName']))
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 ring-inset {{ $customerChip }}" title="{{ $t['CustomerName'] }}">{{ $customerShort }}</span>
                                @else
                                    <span class="text-slate-400 text-xs">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 font-mono text-[11px] text-slate-600">{{ $t['VIN'] ?? '—' }}</td>
                            <td class="px-4 py-2.5">
                                @if(!empty($t['VehicleRegistration']))
                                    <span class="inline-flex rounded bg-yellow-100 border border-yellow-300 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-yellow-900">{{ $t['VehicleRegistration'] }}</span>
                                @else
                                    <span class="text-[11px] italic text-slate-400">no plate</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 font-mono text-[11px] text-slate-500">{{ $t['VehicleFleetNumber'] ?: '—' }}</td>
                            <td class="px-4 py-2.5 text-sm text-slate-700">{{ $t['SupplierName'] ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-sm">
                                <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">{{ $t['ProductCode'] ?? '—' }}</span>
                                <span class="ml-1 text-xs text-slate-400">{{ $productLabels[$t['ProductCode'] ?? ''] ?? '' }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-right text-sm tabular-nums text-slate-900">{{ !empty($t['Litres']) ? number_format((float) $t['Litres']) . ' L' : '—' }}</td>
                            @if($canSeeFinance)
                                <td class="px-4 py-2.5 text-right text-sm tabular-nums text-slate-900">R {{ number_format(abs((float) ($t['Amount'] ?? 0)), 2) }}</td>
                            @endif
                            <td class="px-4 py-2.5 text-right text-xs tabular-nums text-slate-500">{{ !empty($t['Odometer']) ? number_format((float) $t['Odometer']) . ' km' : '—' }}</td>
                            <td class="px-4 py-2.5 text-xs font-mono text-slate-500">{{ $t['TransactionReference'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canSeeFinance ? 11 : 10 }}" class="px-4 py-8 text-center text-sm text-slate-500">No transactions in this window.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ────────── Fleet · vehicles + virtual cards ────────── --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-100 p-4">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">In-transit vehicles &middot; virtual cards</h2>
                <p class="mt-0.5 text-xs text-slate-500">One row per customer vehicle currently being moved drive-away. TFN issues a virtual card for the trip &mdash; card retires when the driver hands the keys over at the dealership.</p>
            </div>
            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">{{ count($fleet) }} in transit</span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Customer</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Vehicle</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">VIN</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Reg</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Job #</th>
                        <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Tank</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Card #</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Card expires</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Last transaction</th>
                        <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($fleet as $row)
                        @php
                            $expiresAt = $row['card_expires'] ? \Illuminate\Support\Carbon::parse($row['card_expires']) : null;
                            // diffInDays() returns a float in newer Carbon -- round to whole
                            // days so the column doesn't display "26.999998682014d".
                            $daysToExpiry = $expiresAt ? (int) round(now()->diffInDays($expiresAt, false)) : null;
                            $expiryClass = match(true) {
                                $daysToExpiry === null => 'text-slate-400',
                                $daysToExpiry < 0      => 'text-rose-600 font-semibold',
                                $daysToExpiry <= 1     => 'text-amber-600 font-semibold',
                                default                => 'text-slate-500',
                            };
                            $statusMap = [0 => 'WrittenOff', 1 => 'Dormant', 2 => 'Unused', 3 => 'Active', 4 => 'Stolen', 5 => 'Moved', 6 => 'Sold'];
                            $statusLabel = $statusMap[$row['status_code']] ?? '—';
                            $statusClass = $row['status_code'] === 3 ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-slate-100 text-slate-600 ring-slate-200';
                            // Customer chip colour so Isuzu / FAW / Powerstar are visually distinct at a glance.
                            $customer = strtolower($row['customer'] ?? '');
                            $customerChip = match(true) {
                                str_contains($customer, 'faw')       => 'bg-red-50 text-red-700 ring-red-600/20',
                                str_contains($customer, 'isuzu')     => 'bg-sky-50 text-sky-700 ring-sky-600/20',
                                str_contains($customer, 'powerstar') => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
                                default                              => 'bg-slate-100 text-slate-700 ring-slate-300',
                            };
                            $customerShort = match(true) {
                                str_contains($customer, 'faw')       => 'FAW',
                                str_contains($customer, 'isuzu')     => 'Isuzu',
                                str_contains($customer, 'powerstar') => 'Powerstar',
                                default                              => $row['customer'] ?? '',
                            };
                        @endphp
                        <tr class="hover:bg-slate-50/50">
                            <td class="px-4 py-3">
                                @if($row['customer'])
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 ring-inset {{ $customerChip }}" title="{{ $row['customer'] }}">{{ $customerShort }}</span>
                                @else
                                    <span class="text-slate-400 text-xs">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-700">
                                <p class="font-medium text-slate-900">{{ $row['brand'] }} {{ $row['model'] }}</p>
                                <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset {{ $statusClass }} mt-0.5">{{ $statusLabel }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-[11px] text-slate-600">{{ $row['vin'] ?: '—' }}</td>
                            <td class="px-4 py-3">
                                @if($row['registration'])
                                    <span class="inline-flex rounded bg-yellow-100 border border-yellow-300 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-yellow-900" title="Trade / dealer plate applied for the drive-away leg">{{ $row['registration'] }}</span>
                                @else
                                    <span class="text-[11px] italic text-slate-400" title="New-off-plant unit — dealer applies a plate after handover">no plate · new build</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-[11px] text-slate-500">{{ $row['job_number'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                @if($row['tank_size'])
                                    <span class="text-xs tabular-nums text-slate-600">{{ $row['tank_size'] }} L</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 ring-1 ring-inset ring-slate-200" title="Tank size not on the plant delivery note — the driver confirms at the pump.">unknown</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm font-mono text-slate-700">{{ $row['card_number'] ?: '— no active card —' }}</td>
                            <td class="px-4 py-3 text-xs {{ $expiryClass }}">
                                @if($expiresAt)
                                    {{ $expiresAt->format('d M Y') }}
                                    · {{ $daysToExpiry < 0 ? 'expired '.abs($daysToExpiry).'d ago' : 'in '.$daysToExpiry.'d' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500">
                                @if($row['last_tx_at'])
                                    {{ \Illuminate\Support\Carbon::parse($row['last_tx_at'])->diffForHumans() }}
                                    @if($row['last_tx_l']) · {{ number_format((float) $row['last_tx_l']) }} L @endif
                                    @if($row['last_tx_dep']) · {{ $row['last_tx_dep'] }} @endif
                                @else
                                    <span class="text-slate-400">no recent activity</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button wire:click="selectVehicleForOrder('{{ $row['registration'] }}')" class="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                    Order fuel
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ────────── Recently utilised / closed orders ────────── --}}
    @if(count($utilisedOrders))
        <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 p-4">
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Recently closed orders</h2>
                    <p class="mt-0.5 text-xs text-slate-500">Pre-authorisations that have been fulfilled at the pump or expired.</p>
                </div>
                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">{{ count($utilisedOrders) }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Order #</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Vehicle</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Product</th>
                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Litres</th>
                            @if($canSeeFinance)
                                <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Amount</th>
                            @endif
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Status</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Reference</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($utilisedOrders as $o)
                            <tr>
                                <td class="px-4 py-2.5 text-sm font-mono text-slate-700">{{ $o['OrderNumber'] ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-sm text-slate-700">{{ $o['VehicleRegistration'] ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-sm text-slate-700">{{ $o['ProductCode'] ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-right text-sm tabular-nums text-slate-700">{{ number_format((float) ($o['Litres'] ?? 0)) }} L</td>
                                @if($canSeeFinance)
                                    <td class="px-4 py-2.5 text-right text-sm tabular-nums text-slate-700">R {{ number_format((float) ($o['Amount'] ?? 0), 2) }}</td>
                                @endif
                                <td class="px-4 py-2.5 text-xs"><span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">{{ $o['Status'] ?? '—' }}</span></td>
                                <td class="px-4 py-2.5 text-xs font-mono text-slate-500">{{ $o['Reference'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <p class="pt-2 text-center text-[11px] text-slate-400">
        Data source: {{ $live ? 'TFN CustomerAPI v'.config('tfn.api_version').' at '.parse_url(config('tfn.base_url'), PHP_URL_HOST) : 'demo fixtures' }} · Refreshed {{ now()->format('d M Y · H:i') }} SAST
    </p>
</div>
