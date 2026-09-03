<?php

use App\Models\TfnFuelOrderPlacement;
use App\Services\Tfn\Exceptions\TfnException;
use App\Services\Tfn\TfnClient;
use App\Services\Tfn\TfnDemoFixtures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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

    // Per-request memoization for source().  Volt calls this from
    // with() (the render path) and again from any Livewire action
    // that inspects the current vehicle list (placeOrder,
    // autoPopulateOrderReference, ...).  Without a cache each of
    // those repeated calls would trigger a fresh sweep of upstream
    // TFN reads -- on a 1000+ vehicle account that means dozens of
    // extra sequential HTTP round trips per page render.
    private ?array $sourceCache = null;

    public function mount(): void
    {
        // Only internal staff (ops controller, dispatcher, accounts,
        // owner, developer, super admin) should see fuel operations --
        // customers and dealers must never land here.
        if (!auth()->user()?->isInternal() && !auth()->user()?->isDeveloper()) {
            abort(403);
        }

        // Sensible default: order expires end of the fourth day (SAST).
        // Matches the standard ProSelver ops window Lize uses today for
        // Lize-style drive-away trips (Beaufort West -> Cape Town leg,
        // FAW HO -> dealer, etc. -- every real ORD/01/2951/* order on
        // the account is a four-day window).  Operator can override
        // before submit.
        $this->orderExpiresAt = now()->addDays(4)->endOfDay()->format('Y-m-d\TH:i');
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
     *
     * Memoized per Livewire request via $this->sourceCache -- see the
     * property declaration for the rationale.
     */
    private function source(): array
    {
        if ($this->sourceCache !== null) {
            return $this->sourceCache;
        }
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
            return $this->sourceCache = [
                'live'       => false,
                'banner'     => $flag ?? 'TFN not configured — showing demo data. Set TFN_ENABLED=true and populate TFN_USERNAME / TFN_PASSWORD / TFN_CUSTOMER_NUMBER in .env to switch to live QA.',
                'balance'    => $fixtures->balance(),
                'aggregate'  => $fixtures->aggregateLitres(),
                'pricing'    => $this->normalisePricingRows($fixtures->pricing(), 'D0'),
                'depots'     => $fixtures->depots(),
                'vehicles'   => $fixtures->vehicles(),
                'cards'      => $fixtures->virtualCards(),
                // Flatten orders in demo mode so the Volt template
                // consumes a single per-entry shape regardless of
                // whether the source is TFN's nested API response or
                // the fixtures.  See flattenOrders() below.
                'orders'     => $fixtures->ordersFlattened(),
                'transactions' => $fixtures->transactions(),
            ];
        }

        // Live path. Wrap each read individually so one flaky endpoint
        // doesn't take the whole screen down -- the operator can still
        // see everything else and refresh.
        $safe = fn (callable $fn, mixed $fallback = []) => $this->safely($fn, $fallback);

        // Fetch orders FIRST so we know which vehicles are actually in
        // flight; card lookups are then scoped to that set (typically
        // <20 vehicles) instead of walking every vehicle on the account.
        // TFN's /api/Orders returns a list of Orders with nested
        // Entries[]; the template renders one row per entry, so we
        // flatten at the boundary.
        //
        // IMPORTANT: on a live account, never fall back to demo-fixture
        // orders.  Those carry synthetic VINs (ACVWR75…, AK1522…) that
        // look like real open pre-auths and confuse ops searching the
        // fleet.  Empty list + the per-endpoint error log is honest.
        $orders = $safe(fn () => $this->flattenOrders($client->orders()), []);

        $openOrderRegs = collect($orders)
            ->filter(fn ($o) => strcasecmp($o['Status'] ?? '', 'open') === 0)
            ->pluck('VehicleRegistration')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $this->sourceCache = [
            'live'         => true,
            'banner'       => null,
            'balance'      => $safe(fn () => $client->subAccountBalance(), $fixtures->balance()),
            'aggregate'    => $safe(fn () => $client->subAccountAggregateLitres(), $fixtures->aggregateLitres()),
            'pricing'      => $safe(fn () => $this->pricingBundle($client), $fixtures->pricing()),
            'depots'       => $safe(fn () => $client->depots(), $fixtures->depots()),
            'vehicles'     => $safe(fn () => $client->vehicles(), $fixtures->vehicles()),
            'cards'        => $safe(fn () => $this->cardsForRegistrations($client, $openOrderRegs), []),
            'orders'       => $orders,
            'transactions' => $safe(fn () => $client->transactions($this->txWindowStart()), $fixtures->transactions()),
        ];
    }

    /**
     * Turn TFN's nested Order + Entries[] payload into a flat
     * per-entry list.  Preserves the legacy field aliases the current
     * Volt template reads (Litres, PlacedAt, ExpiresAt, Status, etc.)
     * alongside the real TFN keys so the transition is invisible to
     * downstream code.
     *
     * Mirror of TfnDemoFixtures::ordersFlattened() -- both must agree
     * on the row shape or the fixture and live paths diverge.
     */
    private function flattenOrders(array $orders): array
    {
        $out = [];
        foreach ($orders as $order) {
            foreach ($order['Entries'] ?? [] as $entry) {
                $status = $order['StatusTitle'] ?? '';
                $out[] = [
                    'OrderNumber'               => $order['OrderNumber'] ?? null,
                    'CustomerNumber'            => $order['CustomerNumber'] ?? null,
                    'CustomerReference'         => $order['CustomerReference'] ?? null,
                    'CustomerName'              => $order['CustomerName'] ?? '',
                    'StatusTitle'               => $status,
                    'VIN'                       => $order['VIN'] ?? '',
                    'Position'                  => $entry['Position'] ?? null,
                    'ProductCode'               => $entry['ProductCode'] ?? null,
                    'VehicleRegistration'       => $entry['VehicleRegistration'] ?? null,
                    'CurrentVirtualCardNumber'  => $entry['CurrentVirtualCardNumber'] ?? null,
                    'MaxAllocation'             => $entry['MaxAllocation'] ?? null,
                    'ValidDateStart'            => $entry['ValidDateStart'] ?? null,
                    'ValidDateEnd'              => $entry['ValidDateEnd'] ?? null,
                    'LinkedTransactions'        => $entry['LinkedTransactions'] ?? [],
                    // Aliases the existing Volt template reads.
                    'EntryNumber'               => isset($entry['Position']) ? (string) $entry['Position'] : null,
                    'Litres'                    => $entry['MaxAllocation'] ?? null,
                    'PlacedAt'                  => $entry['ValidDateStart'] ?? null,
                    'ExpiresAt'                 => $entry['ValidDateEnd'] ?? null,
                    'Status'                    => str_starts_with($status, 'Active') ? 'Open' : $status,
                    'Reference'                 => $order['CustomerReference'] ?? null,
                    'PlacedBy'                  => null,
                    'DepotTitle'                => null,
                ];
            }
        }
        return $out;
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
                    isset($response['PricePerLitre']),
                    isset($response['PriceIncludingGrid'])       => [$response],
                    default                                      => [],
                };
                foreach ($this->normalisePricingRows($rows, $code, $label) as $row) {
                    $out[] = $row;
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

    /**
     * Flatten TFN pricing rows to the SAME shape whether they came
     * from real v3 (SupplierName / PriceIncludingGrid / Price / ...)
     * or from a legacy demo fixture (DepotTitle / PricePerLitre / ...).
     * The view template only cares about `ProductCode`, `DepotTitle`
     * and `PricePerLitre`, so we resolve those from whichever key set
     * is present.
     *
     * `PriceIncludingGrid` is the DRIVER-PAID R/L and wins over `Price`
     * (which is the ex-grid product sub-total, not what shows on the
     * receipt).  Deliberately no fallback to `Price` UNLESS
     * `PriceIncludingGrid` is missing entirely -- otherwise a stale
     * fixture with only `Price` set would silently under-price on
     * screen.
     */
    private function normalisePricingRows(array $rows, string $productCode, ?string $label = null): array
    {
        $productLabels = config('tfn.product_labels', []);
        $label = $label ?? ($productLabels[$productCode] ?? $productCode);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $pricePerLitre = (float) (
                $row['PriceIncludingGrid']
                ?? $row['PricePerLitre']
                ?? $row['Price']
                ?? 0
            );
            $out[] = [
                'ProductCode'   => $row['ProductCode'] ?? $productCode,
                'Label'         => $row['Label'] ?? $label,
                'DepotID'       => $row['DepotID'] ?? null,
                'DepotTitle'    => $row['DepotTitle'] ?? $row['SupplierName'] ?? '—',
                'PricePerLitre' => $pricePerLitre,
                'AsOf'          => $row['AsOf'] ?? now()->toIso8601String(),
            ];
        }
        return $out;
    }

    /**
     * Look up virtual card numbers for a specific set of registrations
     * (typically vehicles with an open order right now).
     *
     * Historically this walked every vehicle on the account.  On a real
     * customer account with 1000+ vehicles that turned into 1000+
     * sequential /api/VirtualCardNumber calls per page load (~2 minutes
     * of upstream work, no cache, on every render).  We only ever need
     * card numbers for the handful of vehicles the operator is actually
     * moving, so scope the lookup to that set.
     *
     * Keyed on the normalised registration so downstream lookup by
     * either the raw or normalised form finds a match.
     */
    private function cardsForRegistrations(TfnClient $client, array $registrations): array
    {
        $out = [];
        foreach (array_unique(array_filter($registrations)) as $reg) {
            $key = strtoupper(preg_replace('/\s+/', '', (string) $reg));
            if ($key === '') continue;
            try {
                $out[$key] = $client->virtualCardNumber($reg);
            } catch (TfnException $e) {
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
        // Match mount()'s four-day default.
        $this->orderExpiresAt = now()->addDays(4)->endOfDay()->format('Y-m-d\TH:i');
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
        $this->autoPopulateOrderReference();
    }

    /**
     * Fires when the operator changes the vehicle in the picker.  We
     * only use it to auto-populate the Reference field so the form
     * matches the ProSelver ops convention seen on every real order
     * against `01/2951` -- see also autoPopulateOrderReference() below.
     */
    public function updatedOrderRegistration(): void
    {
        $this->autoPopulateOrderReference();
    }

    /**
     * Fill in `orderReference` with the "{delivery-or-origin} {VIN}"
     * pattern ProSelver ops (Lize) uses on every real TFN order today:
     *
     *   ISUZU HO ACVNRR75LTN218468
     *   BIDVEST ISUZU PTA EAST ACVFRR90LTN212673
     *   FAW HO AAK3534FDTB051552
     *   WILLIAM HUNT MIDRAND ACVBRRAR0T4209229
     *
     * That reference is what feeds ProSelver's month-end reconciliation,
     * so TRIDENT-placed orders MUST land in the same list looking like
     * Lize's manual orders or accounts will have two shapes to chase.
     *
     * The rule:
     *   - Never overwrite an existing reference (operator override wins).
     *   - Prefer a matching in-transit Job's delivery company + VIN.
     *   - Fall back to fixture data (CustomerName + VIN) so demo mode
     *     shows a realistic reference for stakeholder walk-throughs.
     */
    private function autoPopulateOrderReference(): void
    {
        if (!blank($this->orderReference)) {
            return;
        }
        if (blank($this->orderRegistration)) {
            return;
        }

        $ref = $this->deriveReferenceFromJob($this->orderRegistration)
            ?? $this->deriveReferenceFromVehicleFixture($this->orderRegistration);

        if (!blank($ref)) {
            $this->orderReference = $ref;
        }
    }

    /**
     * Look up a live trip in TRIDENT by whichever identifier the
     * picker holds (VIN, permanent plate, or driver trade plate) and
     * emit the ProSelver "{DELIVERY COMPANY} {VIN}" reference for it.
     */
    private function deriveReferenceFromJob(string $key): ?string
    {
        $canonicalPlate = \App\Models\DriverProfile::normalisePlate($key);

        $job = \App\Models\Job::query()
            ->with('deliveryLocation:id,company_name')
            ->where(function ($q) use ($key, $canonicalPlate) {
                $q->where('vin', $key)
                  ->orWhere('registration', $key);
                if (!blank($canonicalPlate)) {
                    $q->orWhereHas('driver.driverProfile', fn ($qq) => $qq->where('trade_plate', $canonicalPlate));
                }
            })
            // Prefer in-flight trips -- historical ones are noise for
            // the "place an order" workflow.
            ->whereIn('status', [
                \App\Models\Job::STATUS_CONFIRMED,
                \App\Models\Job::STATUS_PLANNED,
                \App\Models\Job::STATUS_DRIVER_ASSIGNED,
                \App\Models\Job::STATUS_READY_FOR_COLLECTION,
                \App\Models\Job::STATUS_COLLECTED,
                \App\Models\Job::STATUS_IN_TRANSIT,
            ])
            ->orderByDesc('id')
            ->first();

        if (!$job) {
            return null;
        }

        $company = trim((string) ($job->deliveryLocation?->company_name ?? ''));
        $vin     = trim((string) ($job->vin ?? ''));

        return $this->formatReference($company, $vin);
    }

    /**
     * Fallback used when there's no matching Job yet (demo mode, or a
     * TFN vehicle that arrived on the picker before its Job was
     * captured in TRIDENT).  Reads from whatever source() already put
     * in memory -- fixture in demo, TfnClient::vehicles() in live.
     */
    private function deriveReferenceFromVehicleFixture(string $key): ?string
    {
        $vehicles = $this->source()['vehicles'] ?? [];
        $veh = collect($vehicles)->firstWhere('VIN', $key)
            ?? collect($vehicles)->firstWhere('Registration', $key);
        if (!$veh) {
            return null;
        }
        $company = trim((string) ($veh['CustomerName'] ?? ''));
        $vin     = trim((string) ($veh['VIN'] ?? ''));

        return $this->formatReference($company, $vin);
    }

    private function formatReference(string $company, string $vin): ?string
    {
        $ref = trim(($company !== '' ? $company . ' ' : '') . $vin);
        return $ref !== '' ? strtoupper($ref) : null;
    }

    /**
     * Look up the POS registration for the selected vehicle.  This is
     * the string TFN puts on every transaction and rejects when blank
     * or non-alphanumeric -- so we must never send the VIN (which is
     * what the picker's option value used to hold).
     *
     * Rule per TFN + Sikelela (2026-08-28):
     *   1. If the vehicle has a permanent plate, use it.
     *   2. Else use the assigned driver's trade plate for this trip.
     *   3. Else there is no valid registration -- refuse to submit.
     *
     * The picker binds `orderRegistration` to a *vehicle key* (VIN
     * where present, permanent plate otherwise) rather than the POS
     * registration directly, because a human can identify the vehicle
     * by its VIN even when the plate belongs to a driver they don't
     * remember off the top of their head.
     */
    private function posRegistrationFor(string $vehicleKey, array $vehicles): ?string
    {
        if ($vehicleKey === '') {
            return null;
        }
        $veh = collect($vehicles)->firstWhere('VIN', $vehicleKey)
            ?? collect($vehicles)->firstWhere('Registration', $vehicleKey)
            ?? collect($vehicles)->firstWhere('PosRegistration', $vehicleKey);
        if (!$veh) {
            return null;
        }
        // Prefer the vehicle's own permanent plate; fall back to the
        // driver's trade plate.  Both are already normalised (upper /
        // no spaces) by their models on save, but strip defensively in
        // case the API surface hands us a raw string from elsewhere.
        $reg = $veh['Registration'] ?? null;
        if (!blank($reg)) {
            return \App\Models\DriverProfile::normalisePlate($reg);
        }
        $trade = $veh['DriverTradePlate'] ?? null;
        return blank($trade) ? null : \App\Models\DriverProfile::normalisePlate($trade);
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

        // Resolve the string TFN expects on VehicleRegistration.  VINs
        // are not accepted (Sikelela 2026-08-28) -- so this must be a
        // permanent plate or the assigned driver's trade plate.
        $vehicles = $this->source()['vehicles'] ?? [];
        $posRegistration = $this->posRegistrationFor($this->orderRegistration, $vehicles);

        if (blank($posRegistration)) {
            session()->flash(
                'error',
                'This vehicle has no permanent plate and no driver trade plate on record. '
                . 'Assign a driver with a trade plate before placing an order — TFN needs a '
                . 'registration string on every transaction.'
            );
            return;
        }

        // Payload matches TFN v3 POST /api/Orders exactly (confirmed
        // 2026-08-31 against QA sandbox with a real 200 OK response).
        // The empty top-level `OrderNumber`, empty `Entry.Position` /
        // `CurrentVirtualCardNumber`, and the null-UUID
        // `LinkedTransactions[0].TransactionID` are Swashbuckle-style
        // placeholders -- TFN's model binder ignores them on write and
        // the server fills them in on the response.
        $validStart = Carbon::now();
        $validEnd   = Carbon::parse($this->orderExpiresAt);
        $reference  = $this->orderReference ?: '';
        $payload = [
            'IsDeleted'                     => false,
            'Planned'                       => false,
            'PlannedReasons'                => '',
            'OrderNumber'                   => '',
            'CustomerNumber'                => config('tfn.customer_number'),
            'SubContractorCustomerNumber'   => '',
            'CustomerReference'             => $reference,
            'EntriesCompleteAfterFirstUse'  => true,
            'MaxAllocation'                 => $litres,
            'SubContractorAccepted'         => false,
            'SubContractorDeclined'         => false,
            'StatusTitle'                   => '',
            'SkipSMS'                       => false,
            'Entries' => [[
                'IsDeleted'                => false,
                'Position'                 => 0,
                'SupplierNumber'           => 0,
                'ProductCode'              => strtoupper($this->orderProductCode),
                'VehicleRegistration'      => $posRegistration,
                'CardNumber'               => '',
                'DriverCellNumber'         => '',
                'CurrentVirtualCardNumber' => '',
                'MaxAllocation'            => $litres,
                // TFN expects local (SAST/CAT) datetimes with fractional
                // seconds but no offset; Carbon->format leaves the tz
                // out unless we ask.  Matches Masupha's working sample.
                'ValidDateStart'           => $validStart->format('Y-m-d\TH:i:s.u'),
                'ValidDateEnd'             => $validEnd->format('Y-m-d\TH:i:s.u'),
                'CustomerReference'        => $reference,
                'LinkedTransactions'       => [[
                    'TransactionID' => '00000000-0000-0000-0000-000000000000',
                ]],
            ]],
        ];

        $client = app(TfnClient::class);

        if (!$client->isLive()) {
            // Demo mode: pretend we posted successfully. The next
            // page render still reads from fixtures so the newly-
            // "placed" order won't appear in the Open Orders table --
            // that's fine, this is a walkthrough tool.
            $demoOrderNumber = 'DEMO/' . now()->format('YmdHis');
            $this->recordOrderPlacement(
                orderNumber: $demoOrderNumber,
                registration: $posRegistration,
                productCode: $this->orderProductCode,
                litres: $litres,
                customerReference: $reference,
            );
            session()->flash('success', sprintf(
                '(Demo) Order placed: %d L of %s against %s. Expires %s.',
                (int) $litres,
                $this->orderProductCode,
                $posRegistration,
                $validEnd->format('D d M H:i'),
            ));
            $this->reset(['orderLitres', 'orderReference']);
            return;
        }

        try {
            // Client generates the newRecordIdentifier idempotency UUID
            // per call.  createOrder unwraps the ValidationResult /
            // Order / Message envelope and returns just the Order.
            $order = $client->createOrder($payload);
            $orderNumber = $order['OrderNumber'] ?? '';
            if ($orderNumber !== '') {
                $this->recordOrderPlacement(
                    orderNumber: $orderNumber,
                    registration: $posRegistration,
                    productCode: $this->orderProductCode,
                    litres: $litres,
                    customerReference: $reference,
                );
            }
            $suffix = $orderNumber !== '' ? sprintf(' (%s)', $orderNumber) : '';
            session()->flash('success', sprintf(
                'Order placed%s: %d L of %s against %s.',
                $suffix,
                (int) $litres,
                $this->orderProductCode,
                $posRegistration,
            ));
            $this->reset(['orderLitres', 'orderReference']);
        } catch (TfnException $e) {
            // createOrder now surfaces TFN's own Message string when the
            // server returns a non-Successful ValidationResult, so we
            // don't need to double-prefix here -- just show what TFN said.
            session()->flash('error', $e->getMessage());
        }
    }

    /**
     * Persist who placed a TFN pre-auth so the open/closed orders
     * tables can show "Placed by".  TFN itself does not return a
     * placer — CustomerReference is the job ref — so this is local.
     */
    private function recordOrderPlacement(
        string $orderNumber,
        string $registration,
        string $productCode,
        float $litres,
        string $customerReference,
    ): void {
        $user = auth()->user();
        $name = $user?->name ?: ($user?->username ?: 'Unknown');

        TfnFuelOrderPlacement::query()->create([
            'order_number'         => $orderNumber,
            'vehicle_registration' => $registration,
            'product_code'         => strtoupper($productCode),
            'litres'               => $litres,
            'customer_reference'   => $customerReference !== '' ? $customerReference : null,
            'user_id'              => $user?->id,
            'placed_by_name'       => $name,
            'placed_at'            => now(),
        ]);

        Log::info('TFN fuel order placed', [
            'order_number'         => $orderNumber,
            'vehicle_registration' => $registration,
            'product_code'         => strtoupper($productCode),
            'litres'               => $litres,
            'customer_reference'   => $customerReference,
            'user_id'              => $user?->id,
            'placed_by'            => $name,
        ]);
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
     * TFN puts account top-ups / credits on the same /api/Transactions
     * feed as pump spend. Detect those so the table can label them
     * "Payment" and so spend KPIs don't treat a R200k credit as diesel.
     *
     * Signals (any one is enough):
     *   - TransactionTypeCode CC / CD / CX (credit, debit, correction)
     *   - ProductCode EW (eWallet allocation)
     *   - Positive Amount with no litres and no pump product / supplier
     *     (the usual shape of a SubAccountPayment landing as a tx)
     */
    public function isAccountPayment(array $t): bool
    {
        $type = strtoupper(trim((string) ($t['TransactionTypeCode'] ?? '')));
        if (in_array($type, ['CC', 'CD', 'CX'], true)) {
            return true;
        }

        $product = strtoupper(trim((string) ($t['ProductCode'] ?? '')));
        if ($product === 'EW') {
            return true;
        }

        $amount = (float) ($t['Amount'] ?? 0);
        $litres = $this->rowLitres($t);
        $supplier = trim((string) ($t['SupplierName'] ?? ''));

        // Pump / site spend always carries a product or a supplier.
        // A bare rand movement with no litres is an account payment.
        if ($litres <= 0 && $product === '' && $supplier === '' && abs($amount) > 0) {
            return true;
        }

        // Credit onto the account: Amount > 0 in TFN's convention
        // (purchases are negative), still no pump footprint.
        $pumpCodes = array_keys(config('tfn.product_labels', []));
        if ($amount > 0 && $litres <= 0 && ($product === '' || !in_array($product, $pumpCodes, true))) {
            return true;
        }

        return false;
    }

    /**
     * Litres on a TFN row. Aggregate and transaction payloads have used
     * Litres / Quantity / TotalLitres across versions — read whichever
     * is present so the MTD tile and tx table don't go blank.
     */
    public function rowLitres(array $row): float
    {
        return (float) ($row['Litres']
            ?? $row['Quantity']
            ?? $row['TotalLitres']
            ?? $row['Volume']
            ?? 0);
    }

    /**
     * Group network pricing: South African provinces first (ops almost
     * never leave SA), then out-of-country. Within each group, cheapest
     * depot first. TFN's /api/Pricing has no province field — we join
     * to /api/Depots GPS + title heuristics.
     *
     * @return list<array{key:string,label:string,domestic:bool,rows:list<array>}>
     */
    private function groupNetworkPricing(array $pricing, array $depots): array
    {
        $depotByTitle = [];
        foreach ($depots as $d) {
            $title = strtoupper(trim((string) ($d['Title'] ?? '')));
            if ($title !== '') {
                $depotByTitle[$title] = $d;
            }
        }

        $buckets = [];
        foreach ($pricing as $row) {
            $title = (string) ($row['DepotTitle'] ?? $row['SupplierName'] ?? '');
            $depot = $depotByTitle[strtoupper(trim($title))] ?? null;
            $loc = $this->classifyDepotLocation(
                isset($depot['GPSLatitude']) ? (float) $depot['GPSLatitude'] : null,
                isset($depot['GPSLongitude']) ? (float) $depot['GPSLongitude'] : null,
                $title,
            );
            $key = $loc['key'];
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'key'      => $key,
                    'label'    => $loc['label'],
                    'domestic' => $loc['domestic'],
                    'sort'     => $loc['sort'],
                    'rows'     => [],
                ];
            }
            $buckets[$key]['rows'][] = $row;
        }

        foreach ($buckets as &$bucket) {
            usort($bucket['rows'], fn ($a, $b) => ((float) ($a['PricePerLitre'] ?? 0)) <=> ((float) ($b['PricePerLitre'] ?? 0)));
        }
        unset($bucket);

        uasort($buckets, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        return array_values($buckets);
    }

    /**
     * @return array{key:string,label:string,domestic:bool,sort:int}
     */
    private function classifyDepotLocation(?float $lat, ?float $lon, string $title): array
    {
        $hay = strtolower($title);

        // Explicit foreign place-names win over GPS (border depots often
        // sit just inside the SA box but serve the other side).
        foreach ($this->foreignDepotKeywords() as $country => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($hay, $kw)) {
                    return $this->regionMeta($country, domestic: false);
                }
            }
        }

        foreach ($this->saProvinceKeywords() as $province => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($hay, $kw)) {
                    return $this->regionMeta($province, domestic: true);
                }
            }
        }

        if ($lat !== null && $lon !== null) {
            // Rough SA mainland box. Anything outside is cross-border.
            $inSa = $lat <= -22.0 && $lat >= -35.0 && $lon >= 16.0 && $lon <= 33.0;
            if (!$inSa) {
                return $this->regionMeta($this->countryFromGps($lat, $lon), domestic: false);
            }

            return $this->regionMeta($this->saProvinceFromGps($lat, $lon), domestic: true);
        }

        // No GPS and no name match — keep visible under SA "Other"
        // rather than burying it in foreign.
        return $this->regionMeta('Other (SA)', domestic: true);
    }

    /** @return array{key:string,label:string,domestic:bool,sort:int} */
    private function regionMeta(string $label, bool $domestic): array
    {
        $saOrder = [
            'Gauteng' => 10,
            'Mpumalanga' => 20,
            'Limpopo' => 30,
            'North West' => 40,
            'Free State' => 50,
            'KwaZulu-Natal' => 60,
            'Eastern Cape' => 70,
            'Northern Cape' => 80,
            'Western Cape' => 90,
            'Other (SA)' => 99,
        ];
        $foreignOrder = [
            'Botswana' => 200,
            'Zimbabwe' => 210,
            'Namibia' => 220,
            'Mozambique' => 230,
            'Eswatini' => 240,
            'Zambia' => 250,
            'Lesotho' => 260,
            'Other (cross-border)' => 299,
        ];

        $sort = $domestic
            ? ($saOrder[$label] ?? 99)
            : ($foreignOrder[$label] ?? 299);

        return [
            'key'      => ($domestic ? 'sa:' : 'fx:') . strtolower($label),
            'label'    => $label,
            'domestic' => $domestic,
            'sort'     => $sort,
        ];
    }

    /** @return array<string, list<string>> */
    private function foreignDepotKeywords(): array
    {
        return [
            'Botswana' => ['botswana', 'maun', 'francistown', 'lobatse', 'tlokweng', 'kazungula', 'mamuno', 'letlhakane', 'pandamatenga', 'palapye', 'gaborone', 'martinsdrift', 'kwa nokeng'],
            'Zimbabwe' => ['zimbabwe', 'harare', 'bulawayo', 'masvingo', 'mutare', 'victoria falls', 'duze petroleum'],
            'Namibia' => ['namibia', 'windhoek', 'walvis', 'oshikango', 'keetmanshoop'],
            'Mozambique' => ['mozambique', 'maputo', 'beira', 'ressano garcia', 'namaacha'],
            'Eswatini' => ['eswatini', 'swaziland', 'manzini', 'mbabane'],
            'Zambia' => ['zambia', 'lusaka', 'livingstone', 'ndola', 'kitwe', 'chirundu'],
            'Lesotho' => ['lesotho', 'maseru'],
        ];
    }

    /** @return array<string, list<string>> */
    private function saProvinceKeywords(): array
    {
        return [
            'Gauteng' => ['gauteng', 'johannesburg', 'pretoria', 'kempton', 'germiston', 'boksburg', 'alberton', 'midrand', 'centurion', 'soweto', 'heidelberg', 'nimrod'],
            'Mpumalanga' => ['mpumalanga', 'nelspruit', 'mbombela', 'witbank', 'emalahleni', 'secunda', 'middelburg', 'komatipoort'],
            'Limpopo' => ['limpopo', 'polokwane', 'musina', 'tzaneen', 'mokopane', 'beitbridge'],
            'North West' => ['north west', 'northwest', 'rustenburg', 'mahikeng', 'mafikeng', 'klerksdorp', 'potchefstroom', 'brit'],
            'Free State' => ['free state', 'bloemfontein', 'kroonstad', 'harrismith', 'welkom', 'bethlehem'],
            'KwaZulu-Natal' => ['kwazulu', 'kzn', 'durban', 'pietermaritzburg', 'ladysmith', 'newcastle', 'richards bay', 'pinetown'],
            'Eastern Cape' => ['eastern cape', 'gqeberha', 'port elizabeth', 'east london', 'mthatha', 'umthatha'],
            'Northern Cape' => ['northern cape', 'kimberley', 'upington', 'springbok', 'kuruman'],
            'Western Cape' => ['western cape', 'cape town', 'stellenbosch', 'paarl', 'worcester', 'beaufort west', 'george', 'mossel', 'kraaifontein', 'markman'],
        ];
    }

    private function saProvinceFromGps(float $lat, float $lon): string
    {
        // Coarse boxes — good enough to bucket ~270 depots for ops
        // browsing. Overlaps favour the denser truck-corridor province.
        return match (true) {
            $lat > -26.7 && $lat < -25.4 && $lon > 27.5 && $lon < 28.7 => 'Gauteng',
            $lat > -27.6 && $lat < -24.0 && $lon >= 28.7 && $lon < 32.5 => 'Mpumalanga',
            $lat > -25.5 && $lat <= -22.0 && $lon > 26.5 && $lon < 32.0 => 'Limpopo',
            $lat > -28.2 && $lat < -24.5 && $lon > 22.0 && $lon <= 27.5 => 'North West',
            $lat > -30.8 && $lat <= -26.5 && $lon > 24.0 && $lon < 29.8 => 'Free State',
            $lat > -31.5 && $lat < -26.8 && $lon >= 29.0 && $lon < 33.0 => 'KwaZulu-Natal',
            $lat <= -30.5 && $lat > -34.5 && $lon > 22.5 && $lon < 30.5 => 'Eastern Cape',
            $lat > -33.5 && $lat < -26.0 && $lon >= 16.0 && $lon <= 25.0 => 'Northern Cape',
            $lat <= -31.0 && $lon >= 17.0 && $lon <= 25.0 => 'Western Cape',
            default => 'Other (SA)',
        };
    }

    private function countryFromGps(float $lat, float $lon): string
    {
        return match (true) {
            $lat > -27.0 && $lat < -17.5 && $lon > 19.5 && $lon < 29.5 => 'Botswana',
            $lat > -22.5 && $lat < -15.5 && $lon >= 25.0 && $lon < 33.5 => 'Zimbabwe',
            $lat > -29.5 && $lat < -16.5 && $lon >= 11.5 && $lon < 20.5 => 'Namibia',
            $lat > -27.0 && $lat < -10.0 && $lon >= 30.0 && $lon < 41.0 => 'Mozambique',
            $lat > -27.5 && $lat < -25.5 && $lon > 30.5 && $lon < 32.5 => 'Eswatini',
            $lat > -18.5 && $lat < -8.0 && $lon > 21.5 && $lon < 34.0 => 'Zambia',
            $lat > -31.0 && $lat < -28.5 && $lon > 27.0 && $lon < 29.5 => 'Lesotho',
            default => 'Other (cross-border)',
        };
    }

    /**
     * Pre-render aggregations. We compute derived values here (rather
     * than in the Blade) so the template stays declarative.
     */
    public function with(): array
    {
        $data = $this->source();

        // Totals for the KPI strip. Exclude account payments/credits —
        // abs()'ing them previously made a R200k top-up look like spend.
        //
        // Always load month-start transactions when live: Litres MTD
        // falls back to them when the aggregate endpoint is empty, and
        // the "avg paid /L" KPI is volume-weighted from the same set.
        $monthTx = $data['transactions'];
        if (!empty($data['live'])) {
            try {
                $monthTx = app(TfnClient::class)->transactions(
                    Carbon::now()->startOfMonth()->toDateTimeImmutable()
                );
            } catch (\Throwable) {
                // Keep the in-window list — better a partial month than nothing.
            }
        }

        $litresMtd = collect($data['aggregate'])->sum(fn ($r) => $this->rowLitres($r));

        // SubAccountAggregateLitres often returns [] even when the pumps
        // have been busy (QA returned []; live can too when there is no
        // sub-account rollup). Fall back to net litres from transactions
        // since month-start so the tile isn't stuck at 0 L.
        if ($litresMtd <= 0) {
            $litresMtd = collect($monthTx)
                ->reject(fn ($t) => $this->isAccountPayment($t))
                ->sum(fn ($t) => $this->rowLitres($t));
        }

        // Volume-weighted average R/L actually paid at the pump this
        // calendar month (Σ|Amount| / ΣLitres on fuel fills). Min/max
        // are per-fill effective R/L so the helper shows the spread we
        // really paid, not the network list.
        $fuelFills = collect($monthTx)
            ->reject(fn ($t) => $this->isAccountPayment($t))
            ->filter(fn ($t) => $this->rowLitres($t) > 0);
        $paidLitres = $fuelFills->sum(fn ($t) => $this->rowLitres($t));
        $paidSpend  = $fuelFills->sum(fn ($t) => abs((float) ($t['Amount'] ?? 0)));
        $avgPaidPerLitre = $paidLitres > 0 ? ($paidSpend / $paidLitres) : 0.0;
        $perFillRpl = $fuelFills->map(fn ($t) => abs((float) ($t['Amount'] ?? 0)) / $this->rowLitres($t));
        $paidMinRpl = $perFillRpl->count() ? (float) $perFillRpl->min() : 0.0;
        $paidMaxRpl = $perFillRpl->count() ? (float) $perFillRpl->max() : 0.0;
        $paidFillCount = $fuelFills->count();

        $spendToday = collect($data['transactions'])
            ->filter(fn ($t) => \Illuminate\Support\Carbon::parse($t['TransactionDate'] ?? $t['CapturedDate'] ?? now())->isToday())
            ->reject(fn ($t) => $this->isAccountPayment($t))
            ->sum(fn ($t) => abs((float) ($t['Amount'] ?? 0)));

        // Product mix summary (litres by fuel grade this month).
        $productMix = collect($data['aggregate'])
            ->groupBy('ProductCode')
            ->map(fn ($rows) => $rows->sum(fn ($r) => $this->rowLitres($r)))
            ->toArray();

        // When aggregate was empty, rebuild product mix from the same
        // month-start fallback so the helper under the KPI isn't blank.
        if ($productMix === [] && $litresMtd > 0) {
            $productMix = collect($monthTx)
                ->reject(fn ($t) => $this->isAccountPayment($t))
                ->filter(fn ($t) => filled($t['ProductCode'] ?? null))
                ->groupBy('ProductCode')
                ->map(fn ($rows) => $rows->sum(fn ($r) => $this->rowLitres($r)))
                ->toArray();
        }

        $openOrders = collect($data['orders'])
            ->filter(fn ($o) => strcasecmp($o['Status'] ?? '', 'open') === 0)
            ->values()
            ->all();

        $utilisedOrders = collect($data['orders'])
            ->filter(fn ($o) => strcasecmp($o['Status'] ?? '', 'open') !== 0)
            ->values()
            ->all();

        // Attach "Placed by" from our local placement audit.  Demo
        // fixtures may already carry PlacedBy; the DB row wins when
        // both exist (live path after a TRIDENT-placed order).
        $placerByOrder = TfnFuelOrderPlacement::query()
            ->whereIn(
                'order_number',
                collect($openOrders)
                    ->merge($utilisedOrders)
                    ->pluck('OrderNumber')
                    ->filter()
                    ->unique()
                    ->all()
            )
            ->orderByDesc('placed_at')
            ->get()
            ->unique('order_number')
            ->keyBy('order_number');

        $attachPlacer = function (array $orders) use ($placerByOrder): array {
            return collect($orders)->map(function (array $o) use ($placerByOrder) {
                $fromDb = $placerByOrder->get($o['OrderNumber'] ?? '')?->placed_by_name;
                $o['PlacedBy'] = $fromDb ?: ($o['PlacedBy'] ?? null);
                return $o;
            })->all();
        };

        $openOrders = $attachPlacer($openOrders);
        $utilisedOrders = $attachPlacer($utilisedOrders);

        // Fleet table = only vehicles with an OPEN TFN order.  Real
        // customer accounts hold 1000+ registered vehicles (most
        // 'Unused' / 'Stolen' / 'Dormant') and rendering the whole
        // catalogue is noise -- the operational table wants the
        // handful of trucks actually in flight right now.
        //
        // Build two lookup indexes:
        //   $fleetRegs      : set of registrations that have an open order
        //   $lastTxByReg    : transactions grouped by VehicleRegistration
        //                     (real TFN key) with a VIN fallback for demo
        //                     fixtures that still emit VIN.
        //
        // Both are keyed on the *normalised* registration (uppercased,
        // whitespace-trimmed) so 'ABC 123 GP' and 'abc123gp' collide.
        $normReg = fn ($r) => strtoupper(preg_replace('/\s+/', '', (string) ($r ?? '')));

        $fleetRegs = collect($openOrders)
            ->map(fn ($o) => $normReg($o['VehicleRegistration'] ?? null))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $lastTxByReg = collect($data['transactions'])
            ->groupBy(fn ($t) => $normReg(
                $t['VehicleRegistration']
                ?? $t['Registration']
                ?? $t['VIN']  // legacy demo-fixture shape
                ?? null
            ))
            ->forget('');  // guard against phantom '' bucket bleed

        $fleet = collect($data['vehicles'])
            ->filter(function ($v) use ($fleetRegs, $normReg) {
                if (empty($fleetRegs)) {
                    return true;  // demo mode / no open orders -> keep old behaviour
                }
                return in_array($normReg($v['Registration'] ?? null), $fleetRegs, true);
            })
            ->map(function ($v) use ($data, $lastTxByReg, $normReg) {
                $reg = $normReg($v['Registration'] ?? null);
                $vin = $v['VIN'] ?? '';
                $card = $data['cards'][$reg] ?? $data['cards'][$vin] ?? null;
                $lastTx = $reg
                    ? $lastTxByReg->get($reg)?->sortByDesc('CapturedDate')->first()
                    : null;
                return [
                    'vin'          => $vin,
                    'registration' => $v['Registration'] ?? null,
                    'customer'     => $v['CustomerName'] ?? null,
                    'brand'        => $v['Brand'] ?? null,
                    'model'        => $v['Model'] ?? null,
                    'fleet_number' => $v['FleetNumber'] ?? null,
                    'job_number'   => $v['ExternalNumber'] ?? null,
                    'tank_size'    => $v['TankSize'] ?? null,
                    'status_code'  => $v['Status'] ?? null,
                    'card_number'  => $card['VirtualCardNumber'] ?? null,
                    'card_expires' => $card['ExpiryDate'] ?? null,
                    'last_tx_at'   => $lastTx['CapturedDate'] ?? null,
                    'last_tx_l'    => $lastTx['Litres'] ?? null,
                    'last_tx_dep'  => $lastTx['SupplierName'] ?? null,
                ];
            })
            ->values()
            ->all();

        $pricingByRegion = $this->groupNetworkPricing($data['pricing'], $data['depots']);

        return [
            'live'           => $data['live'],
            'banner'         => $data['banner'],
            'balance'        => $data['balance'],
            'litresMtd'      => $litresMtd,
            'spendToday'     => $spendToday,
            'avgPaidPerLitre'=> $avgPaidPerLitre,
            'paidMinRpl'     => $paidMinRpl,
            'paidMaxRpl'     => $paidMaxRpl,
            'paidFillCount'  => $paidFillCount,
            'productMix'     => $productMix,
            'pricing'        => $data['pricing'],
            'pricingByRegion'=> $pricingByRegion,
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
    {{-- Litres month-to-date + "Diesel · avg paid" are operational
         signals visible to everyone -- ops needs the realised R/L
         to know what we've actually been paying at the pump this
         month. Balance / credit limit / rand spend are FINANCE data
         -- hidden from ops / dispatcher, only owner + accounts +
         dev + super_admin see them. See `canSeeFinance()`. --}}
    @php
        // Network average kept for the per-transaction R/L delta
        // column (pump vs list). The KPI tile itself shows realised
        // avg paid from `with()` ($avgPaidPerLitre).
        $primaryProduct = array_key_first($orderableProducts) ?: 'D0';
        $primaryLabel   = $orderableProducts[$primaryProduct] ?? $primaryProduct;
        $primaryPrices  = collect($pricing)->where('ProductCode', $primaryProduct)->pluck('PricePerLitre');
        $networkAvg     = $primaryPrices->count() ? (float) $primaryPrices->avg() : 0.0;
    @endphp
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 {{ $canSeeFinance ? 'lg:grid-cols-5' : 'lg:grid-cols-3' }}">
        <x-stat-card
            label="Diesel · avg paid"
            :value="$avgPaidPerLitre > 0 ? ('R ' . number_format($avgPaidPerLitre, 2) . '/L') : '—'"
            color="sky"
            :helper="$avgPaidPerLitre > 0
                ? ($paidFillCount . ' fills this month · low R ' . number_format($paidMinRpl, 2) . ' · high R ' . number_format($paidMaxRpl, 2))
                : 'No fuel fills this month yet'"
            helperColor="sky"
        />
        @if($canSeeFinance)
            @php
                // Balance response shape: TFN's real v3 payload uses
                // `AccountBalance` + `AccountAvailableBalance`; older
                // demo fixtures used `Balance` + `AvailableCredit` +
                // `CreditLimit`.  Accept either -- new keys win, old
                // keys are the fallback.  AccountBalance is signed:
                // negative means the sub-account is in arrears.
                $rawBalance    = $balance['AccountBalance']          ?? $balance['Balance']         ?? 0;
                $rawAvailable  = $balance['AccountAvailableBalance'] ?? $balance['AvailableCredit'] ?? 0;
                $rawLimit      = $balance['CreditLimit']             ?? null;  // not in real v3 payload
                $balanceLabel = $rawBalance < 0
                    ? 'Sub-account · in arrears'
                    : 'Sub-account balance';
                $balanceHelper = 'Available credit R ' . number_format((float) $rawAvailable, 2);
            @endphp
            <x-stat-card
                :label="$balanceLabel"
                :value="'R ' . number_format(abs((float) $rawBalance), 2)"
                :color="$rawBalance < 0 ? 'red' : 'blue'"
                :helper="$balanceHelper"
                helperColor="slate"
            />
            @if($rawLimit !== null)
                <x-stat-card
                    label="Credit limit"
                    :value="'R ' . number_format((float) $rawLimit, 2)"
                    color="indigo"
                    :helper="'Set by TFN — request change via account manager'"
                    helperColor="slate"
                />
            @endif
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
                <p class="mt-0.5 text-xs text-slate-500">South Africa by province first (cheapest depot in each), then cross-border. Few trips leave SA &mdash; scroll past provinces only when needed.</p>
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

            {{-- SA provinces, then out-of-country. Bounded height so the
                 panel stays compact but scrollable. --}}
            <div class="max-h-96 overflow-y-auto">
                @php $sawForeign = false; @endphp
                @forelse($pricingByRegion as $region)
                    @if(!$region['domestic'] && !$sawForeign)
                        @php $sawForeign = true; @endphp
                        <div class="border-y border-amber-200 bg-amber-50 px-4 py-1.5">
                            <p class="text-[10px] font-semibold uppercase tracking-widest text-amber-800">Out of country</p>
                        </div>
                    @endif
                    <div class="border-b border-slate-200 bg-slate-100 px-4 py-1.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-[11px] font-semibold text-slate-700">{{ $region['label'] }}</p>
                            <p class="text-[10px] tabular-nums text-slate-400">{{ count($region['rows']) }} · from R {{ number_format((float) ($region['rows'][0]['PricePerLitre'] ?? 0), 2) }}</p>
                        </div>
                    </div>
                    <div class="divide-y divide-slate-100">
                        @foreach($region['rows'] as $row)
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
                        @endforeach
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
                <div class="sm:col-span-2" x-data="{
                    q: '',
                    filter() {
                        const needle = this.q.trim().toLowerCase();
                        let shown = 0;
                        Array.from(this.$refs.sel.options).forEach(o => {
                            if (!o.dataset.searchLabel) return; // keep the placeholder
                            const match = needle === '' || o.dataset.searchLabel.indexOf(needle) !== -1;
                            o.hidden = !match;
                            if (match) shown++;
                        });
                        this.$refs.count.textContent = needle === ''
                            ? (this.$refs.sel.options.length - 1) + ' vehicles'
                            : shown + ' of ' + (this.$refs.sel.options.length - 1) + ' vehicles';
                    }
                }" x-init="filter()">
                    <div class="mb-1 flex items-baseline justify-between">
                        <label class="block text-xs font-medium text-slate-700">Vehicle in transit</label>
                        <span class="text-[10px] text-slate-400" x-ref="count"></span>
                    </div>
                    <input type="text" x-model.debounce.100ms="q" @input="filter()" placeholder="Filter by plate, customer, VIN, driver, fleet number..." class="mb-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500" />
                    {{-- Real TFN accounts hold 1000+ vehicles; the select
                         still contains all of them for placing orders on
                         cold plates, but options with `hidden` are hidden
                         from the drop-down by the Alpine filter above. --}}
                    <select wire:model="orderRegistration" x-ref="sel" class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        <option value="">— Select a vehicle —</option>
                        @foreach($vehicles as $v)
                            @php
                                // Option value: demo fixtures carry VIN; real
                                // TFN vehicles carry only Registration.  Guard
                                // both -- an unguarded $v['VIN'] read on real
                                // payloads was the original /admin/fuel 500.
                                $optValue = !empty($v['VIN']) ? $v['VIN'] : ($v['Registration'] ?? '');
                                // Searchable haystack (all lowercase, all the
                                // fields an operator might type).  Kept in a
                                // data attribute so the Alpine filter can grep
                                // it without touching the visible option text.
                                $haystack = strtolower(implode(' ', array_filter([
                                    $v['CustomerName'] ?? null,
                                    $v['Brand'] ?? null,
                                    $v['Model'] ?? null,
                                    $v['VIN'] ?? null,
                                    $v['Registration'] ?? null,
                                    $v['DriverTradePlate'] ?? null,
                                    $v['DriverName'] ?? null,
                                    $v['ExternalNumber'] ?? null,
                                    $v['FleetNumber'] ?? null,
                                ])));
                            @endphp
                            <option value="{{ $optValue }}" data-search-label="{{ $haystack }}">
                                @if(!empty($v['CustomerName'])){{ $v['CustomerName'] }} · @endif{{ trim(($v['Brand'] ?? '').' '.($v['Model'] ?? '')) ?: ($v['FleetNumber'] ?? 'Unknown model') }}
                                @if(!empty($v['VIN'])) · VIN {{ $v['VIN'] }} @endif
                                @if(!empty($v['Registration']))
                                    · plate {{ $v['Registration'] }}
                                @elseif(!empty($v['DriverTradePlate']))
                                    · trade plate {{ $v['DriverTradePlate'] }}
                                @else
                                    · no registration
                                @endif
                                @if(!empty($v['DriverName'])) · {{ $v['DriverName'] }} @endif
                                @if(!empty($v['ExternalNumber'])) · {{ $v['ExternalNumber'] }} @endif
                                @if(!empty($v['TankSize'])) · {{ $v['TankSize'] }} L tank @endif
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-[10px] text-slate-500">TFN authorises against a registration, not a VIN. When the vehicle has no permanent plate we use the driver's trade plate for the drive-away leg &mdash; if neither is on file, the order is refused before it hits TFN.</p>
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
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Placed by</th>
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
                            <td class="px-4 py-3 text-xs text-slate-700">{{ $o['PlacedBy'] ?? '—' }}</td>
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
                        <tr><td colspan="{{ $canSeeFinance ? 14 : 13 }}" class="px-4 py-8 text-center text-sm text-slate-500">No open pre-authorisations. Use the form above to place one.</td></tr>
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
                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500" title="Cost per litre at this pump — vs network avg">R/L</th>
                        @endif
                        <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500">Odometer</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Ref</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($transactions as $t)
                        @php
                            $isPayment = $this->isAccountPayment($t);
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
                        <tr class="hover:bg-slate-50/50 {{ $isPayment ? 'bg-emerald-50/40' : '' }}">
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
                                @if($isPayment)
                                    <span class="text-[11px] italic text-slate-400">—</span>
                                @elseif(!empty($t['VehicleRegistration']))
                                    <span class="inline-flex rounded bg-yellow-100 border border-yellow-300 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-yellow-900">{{ $t['VehicleRegistration'] }}</span>
                                @else
                                    <span class="text-[11px] italic text-slate-400">no plate</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 font-mono text-[11px] text-slate-500">{{ $isPayment ? '—' : (($t['VehicleFleetNumber'] ?? $t['FleetNumber'] ?? null) ?: '—') }}</td>
                            <td class="px-4 py-2.5 text-sm text-slate-700">{{ $isPayment ? '—' : ($t['SupplierName'] ?? '—') }}</td>
                            <td class="px-4 py-2.5 text-sm">
                                @if($isPayment)
                                    <span class="inline-flex items-center rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-600/20">Payment</span>
                                @else
                                    <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">{{ $t['ProductCode'] ?? '—' }}</span>
                                    <span class="ml-1 text-xs text-slate-400">{{ $productLabels[$t['ProductCode'] ?? ''] ?? '' }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right text-sm tabular-nums text-slate-900">
                                @php $txLitresDisplay = $this->rowLitres($t); @endphp
                                {{ !$isPayment && abs($txLitresDisplay) > 0 ? number_format($txLitresDisplay) . ' L' : '—' }}
                            </td>
                            @if($canSeeFinance)
                                <td class="px-4 py-2.5 text-right text-sm tabular-nums {{ $isPayment ? 'font-semibold text-emerald-700' : 'text-slate-900' }}">
                                    @if($isPayment)
                                        +R {{ number_format(abs((float) ($t['Amount'] ?? 0)), 2) }}
                                    @else
                                        R {{ number_format(abs((float) ($t['Amount'] ?? 0)), 2) }}
                                    @endif
                                </td>
                                @php
                                    // Effective R/L for this pump event. Only meaningful for
                                    // fuel products (D0/D1/D3) where litres > 0. Non-fuel
                                    // items like WSH / CAN / OS show a dash. Payments never.
                                    $txLitres = $this->rowLitres($t);
                                    $txAmount = abs((float) ($t['Amount'] ?? 0));
                                    $txRpl    = (!$isPayment && $txLitres > 0) ? $txAmount / $txLitres : null;
                                    // Delta vs network avg -- >2c/L above = warn (red),
                                    // >2c/L below = win (emerald), else neutral. Cap the
                                    // sensitivity so day-to-day noise doesn't flag rows.
                                    $rplTone  = 'text-slate-900';
                                    $rplHint  = '';
                                    if ($txRpl && $networkAvg > 0) {
                                        $delta = $txRpl - $networkAvg;
                                        if ($delta > 0.02) {
                                            $rplTone = 'text-red-700';
                                            $rplHint = '+R ' . number_format($delta, 2) . ' vs network avg';
                                        } elseif ($delta < -0.02) {
                                            $rplTone = 'text-emerald-700';
                                            $rplHint = 'R ' . number_format(abs($delta), 2) . ' cheaper than avg';
                                        } else {
                                            $rplHint = 'On network avg';
                                        }
                                    }
                                @endphp
                                <td class="px-4 py-2.5 text-right text-sm tabular-nums {{ $rplTone }}" @if($rplHint) title="{{ $rplHint }}" @endif>
                                    @if($txRpl !== null)
                                        R {{ number_format($txRpl, 2) }}
                                    @else
                                        <span class="text-slate-400 text-xs">—</span>
                                    @endif
                                </td>
                            @endif
                            <td class="px-4 py-2.5 text-right text-xs tabular-nums text-slate-500">{{ !$isPayment && !empty($t['Odometer']) ? number_format((float) $t['Odometer']) . ' km' : '—' }}</td>
                            <td class="px-4 py-2.5 text-xs font-mono text-slate-500">{{ $t['TransactionReference'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canSeeFinance ? 12 : 10 }}" class="px-4 py-8 text-center text-sm text-slate-500">No transactions in this window.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ────────── Fleet · vehicles + virtual cards ────────── --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-100 p-4">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Vehicles with open TFN orders</h2>
                <p class="mt-0.5 text-xs text-slate-500">One row per vehicle with an open (unfilled) order on TFN &mdash; the trip is authorised, driver just hasn&rsquo;t pumped yet. Card retires when the order is fully utilised.</p>
            </div>
            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">{{ count($fleet) }} open</span>
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
                                @php $vehicleLabel = trim(($row['brand'] ?? '').' '.($row['model'] ?? '')); @endphp
                                @if($vehicleLabel !== '')
                                    <p class="font-medium text-slate-900">{{ $vehicleLabel }}</p>
                                @elseif($row['fleet_number'])
                                    {{-- Real TFN vehicles carry no brand/model.
                                         FleetNumber (e.g. 'OUTBOUND', 'FUEL') is
                                         the closest useful primary label. --}}
                                    <p class="font-mono text-[11px] font-medium text-slate-700" title="TFN fleet number">{{ $row['fleet_number'] }}</p>
                                @else
                                    <p class="text-slate-400">&mdash;</p>
                                @endif
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
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Placed by</th>
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
                                <td class="px-4 py-2.5 text-xs text-slate-700">{{ $o['PlacedBy'] ?? '—' }}</td>
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
