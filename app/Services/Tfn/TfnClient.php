<?php

namespace App\Services\Tfn;

use App\Services\Tfn\Exceptions\TfnException;
use App\Services\Tfn\Exceptions\TfnNotConfiguredException;
use DateTimeInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin, typed wrapper around the TFN CustomerAPI (v3).
 *
 *   - Every request pins `api-version` from config (never hardcoded).
 *   - Bearer token is fetched lazily via TfnTokenManager; a 401 forces
 *     exactly one re-auth + retry, then propagates as TfnException.
 *   - Success responses are decoded and returned as arrays. Consumers
 *     don't touch \Illuminate\Http\Client\Response, keeping the Livewire
 *     page thin.
 *   - Config-gated: if TFN_ENABLED is false OR credentials are missing,
 *     every method throws TfnNotConfiguredException up-front so the
 *     caller can decide to render fixtures instead of erroring out.
 *
 * Not covered here (deliberately -- add methods when the workflow needs
 * them, don't build ahead of the UI):
 *   - /api/subAccount write endpoints (onboarding)
 *   - /api/SubAccountPayment (debit/credit)
 *   - /api/SubAccountCreditLimit POST
 *   - /api/Vehicle POST/PUT
 *   - /api/Drivers POST
 *
 * The Fuel operations screen only needs the read-heavy endpoints plus
 * Orders create/list/delete.
 */
class TfnClient
{
    // Short cache windows for endpoints that are hit repeatedly during a
    // Livewire lifecycle (e.g. multiple wire:polls) but whose data
    // doesn't change second-to-second.
    private const CACHE_PRICING_SECONDS = 60;
    private const CACHE_DEPOTS_SECONDS = 300;
    private const CACHE_VEHICLES_SECONDS = 120;
    private const CACHE_BALANCE_SECONDS = 30;

    public function __construct(private readonly TfnTokenManager $tokens)
    {
    }

    /**
     * True when the integration is switched on AND has the credentials
     * it needs to actually make a call. The Livewire page checks this
     * before every action -- when false it falls back to fixture data.
     */
    public function isLive(): bool
    {
        return (bool) config('tfn.enabled')
            && !config('tfn.demo_mode')
            && filled(config('tfn.username'))
            && filled(config('tfn.password'))
            && filled(config('tfn.customer_number'));
    }

    /**
     * Cheap health check. Doesn't strictly require auth per the swagger,
     * but we still send the bearer token when we have one so we
     * simultaneously verify the credentials are still good.
     *
     * @return array{status:string, timestamp:?string, latency_ms:int}
     */
    public function ping(): array
    {
        $started = microtime(true);
        $response = $this->get('/api/Ping', [], authenticated: false);
        $latency = (int) round((microtime(true) - $started) * 1000);

        return [
            'status'     => $response->successful() ? 'ok' : 'error',
            'timestamp'  => data_get($response->json(), 'Timestamp'),
            'latency_ms' => $latency,
        ];
    }

    /* ----------------------------------------------------------------
     |  Reads used by the Fuel operations screen
     |----------------------------------------------------------------*/

    public function depots(): array
    {
        return Cache::remember(
            $this->cacheKey('depots'),
            self::CACHE_DEPOTS_SECONDS,
            fn () => (array) $this->requireJson($this->get('/api/Depots', [
                'customerNumber' => $this->customerNumber(),
            ])),
        );
    }

    /**
     * TFN v3 /api/Vehicle response is thin -- only Registration,
     * FleetNumber, TankSize, Status, ExternalNumber (per 2026-08-28
     * QA probe).  No VIN, no CustomerName, no Brand/Model.  Callers
     * that need those must join TFN's list to our own Job table on
     * Registration (or, for plateless units, the driver trade plate).
     */
    public function vehicles(): array
    {
        return Cache::remember(
            $this->cacheKey('vehicles'),
            self::CACHE_VEHICLES_SECONDS,
            fn () => (array) $this->requireJson($this->get('/api/Vehicle', [
                'customerNumber' => $this->customerNumber(),
            ])),
        );
    }

    public function virtualCardNumber(string $vehicleRegistration): array
    {
        return (array) $this->requireJson($this->get('/api/VirtualCardNumber', [
            'customerNumber'      => $this->customerNumber(),
            'vehicleRegistration' => $vehicleRegistration,
        ]));
    }

    /**
     * Live product pricing. Cached for a minute so wire:poll doesn't
     * hammer /api/Pricing on every tick.
     *
     * TFN v3 /api/Pricing response (per 2026-08-28 QA probe): a flat
     * array of per-depot rows, each with `SupplierName`, `SupplierNumber`
     * (zero-padded 4-digit string), `Price` (ex-grid product cost),
     * `PriceIncludingGrid` (driver-paid R/L, this is the number to
     * display), `VolumeDiscount`, `PromotionalDiscount`,
     * `HasSpecificPricing`, `SpecificPricing[]`, `CustomerExternalReference`,
     * `ParentExternalReference`.  Requires customerNumber even though
     * pricing is largely network-wide -- omitting it returns 404.
     */
    public function pricing(string $productCode): array
    {
        return Cache::remember(
            $this->cacheKey('pricing:' . $productCode),
            self::CACHE_PRICING_SECONDS,
            fn () => (array) $this->requireJson($this->get('/api/Pricing', [
                'customerNumber' => $this->customerNumber(),
                'productCode'    => $productCode,
            ])),
        );
    }

    /**
     * TFN v3 /api/SubAccountBalance response (per 2026-08-28 QA probe):
     *
     *   { CustomerNumber, AccountBalance, AccountAvailableBalance }
     *
     * `AccountBalance` is SIGNED -- a negative value means the account
     * is in arrears.  There is NO `CreditLimit` and NO `AsOf` field in
     * the real payload.  Consumers must handle their absence.
     */
    public function subAccountBalance(): array
    {
        return Cache::remember(
            $this->cacheKey('balance'),
            self::CACHE_BALANCE_SECONDS,
            fn () => (array) $this->requireJson($this->get('/api/SubAccountBalance', [
                'customerNumber' => $this->customerNumber(),
            ])),
        );
    }

    /**
     * @deprecated 2026-08-28 -- QA returns HTTP 404 on both v3 and v4
     * for /api/SubAccountAggregateLitres and /api/SubAccountAggregatedLitres.
     * The correct endpoint (or query shape) is unclear; queued as a
     * follow-up question for Sikelela.  Callers already fall back to
     * the fixture when TfnException fires, so this stays functional
     * until the endpoint is confirmed.  Do NOT wire new consumers to
     * this method until then.
     */
    public function subAccountAggregateLitres(): array
    {
        return (array) $this->requireJson($this->get('/api/SubAccountAggregateLitres', [
            'customerNumber' => $this->customerNumber(),
        ]));
    }

    /**
     * Recent captured transactions. Swagger enforces a max of 100 rows
     * per request and a 3-month lookback ceiling -- we deliberately
     * pass a bounded window (default 24h) so a first-load call
     * completes fast.
     */
    public function transactions(?DateTimeInterface $capturedDateAfter = null): array
    {
        $after = $capturedDateAfter ?? Carbon::now()->subDay();

        return (array) $this->requireJson($this->get('/api/Transactions', [
            'customerNumber'    => $this->customerNumber(),
            'capturedDateAfter' => $after->format(DATE_ATOM),
        ]));
    }

    /**
     * List pre-authorisation orders.
     *
     * NOTE 2026-08-28: `/api/Orders` on the QA sandbox is currently
     * unusable for BOTH read and write from our credentials.
     *
     *   OPTIONS /api/Orders            allow: GET,POST,PUT,DELETE
     *                                  api-supported-versions: 2.0, 3.0
     *   GET  /api/Orders  v3          -> 405 UnsupportedApiVersion
     *   GET  /api/Orders  v4          -> 400 UnsupportedApiVersion
     *   GET  /api/Orders  v2          -> 404 not found
     *   POST /api/Orders  v3          -> 405 UnsupportedApiVersion
     *   POST /api/Orders  v2          -> 404 not found
     *   POST /api/Order (singular)    -> 404 not found
     *   OPTIONS response advertises the versions but individual
     *   method+version combinations are all rejected.
     *
     * Sikelela is out on annual leave until Tuesday 2026-09-01; he
     * owes us the correct URL / method / version pair AND likely a
     * scope grant on the sandbox account.  Callers fall back to the
     * fixture when TfnException fires, so the fuel screen keeps
     * rendering while this is unresolved.
     */
    public function orders(?DateTimeInterface $after = null): array
    {
        $after = $after ?? Carbon::now()->subDays(7);

        return (array) $this->requireJson($this->get('/api/Orders', [
            'customerNumber' => $this->customerNumber(),
            'dateAfter'      => $after->format(DATE_ATOM),
        ]));
    }

    /* ----------------------------------------------------------------
     |  Writes -- diesel ordering
     |----------------------------------------------------------------*/

    /**
     * Place a pre-authorisation ("order") against a vehicle. The order
     * later gets consumed by transactions at the pump, and appears in
     * their UtilisedOrders[] array once fuel is drawn.
     *
     * WARNING 2026-08-28: this call currently 405s on the QA sandbox
     * with either a flat or a nested payload shape (both v2 and v3).
     * See the note on `orders()` above.  Sikelela is out until Tuesday
     * -- expect this to be blocked at go-live until he replies.  The
     * fuel Volt page's placeOrder() catches TfnException from here
     * and surfaces the message, so an ops controller trying to place
     * a real order today would see \"TFN rejected the order: ...\"
     * rather than a 500.
     *
     * @param  array  $order  Shape follows OrderSerializableV2 from the
     *                        swagger. Caller is expected to have run
     *                        client-side validation already.
     * @return array          The created order (mirrors the request
     *                        body with server-side fields like
     *                        OrderNumber / EntryNumber populated).
     */
    public function createOrder(array $order): array
    {
        $response = $this->request()
            ->withBody(json_encode($order), 'application/json')
            ->post($this->url('/api/Orders'));

        return (array) $this->requireJson($this->handleAuthRetry($response, fn () => $this->request()
            ->withBody(json_encode($order), 'application/json')
            ->post($this->url('/api/Orders'))));
    }

    /**
     * Cancel a specific order entry (typically because a trip was
     * scrapped or the driver was reassigned to a different vehicle).
     */
    public function deleteOrderEntry(string $entryNumber): void
    {
        $response = $this->request()->delete($this->url('/api/Orders/' . urlencode($entryNumber)));
        $this->handleAuthRetry($response, fn () => $this->request()->delete($this->url('/api/Orders/' . urlencode($entryNumber))));
    }

    /* ----------------------------------------------------------------
     |  Internals
     |----------------------------------------------------------------*/

    /**
     * Build a PendingRequest pre-populated with base URL, timeout, the
     * mandatory api-version query, JSON headers, and (unless suppressed)
     * a bearer token. Callers add path-specific query/body via the
     * returned instance.
     */
    private function request(bool $authenticated = true): PendingRequest
    {
        $this->assertConfigured();

        $request = Http::baseUrl(rtrim(config('tfn.base_url'), '/'))
            ->timeout(config('tfn.timeout'))
            ->acceptJson()
            ->withQueryParameters(['api-version' => (string) config('tfn.api_version')]);

        if ($authenticated) {
            $request = $request->withToken($this->tokens->token());
        }

        return $request;
    }

    /**
     * GET helper: assembles a request, merges query params, and hands
     * back the raw response so callers can pick apart status / body.
     */
    private function get(string $path, array $query = [], bool $authenticated = true): Response
    {
        $call = fn () => $this->request($authenticated)->get($this->url($path), $query);
        $response = $call();
        return $this->handleAuthRetry($response, $call);
    }

    /**
     * TFN's bearer tokens are short-lived. If the server-side session
     * was invalidated (rotation, revoke, TFN maintenance) we might have
     * a locally-cached token that reads 401. Refresh once transparently
     * and retry -- if it still fails, escalate.
     */
    private function handleAuthRetry(Response $response, \Closure $retry): Response
    {
        if ($response->status() !== 401) {
            return $response;
        }

        $this->tokens->invalidate();

        try {
            $retried = $retry();
        } catch (\Throwable $e) {
            Log::warning('TFN retry after 401 failed to build request', ['error' => $e->getMessage()]);
            throw $e;
        }

        if ($retried->status() === 401) {
            throw new TfnException(
                'TFN rejected credentials twice in a row (HTTP 401). Check TFN_USERNAME / TFN_PASSWORD.',
                httpStatus: 401,
                payload: $retried->json() ?? [],
            );
        }

        return $retried;
    }

    /**
     * Enforce non-4xx / non-5xx and return the decoded JSON body. Every
     * public read method funnels through here so the Livewire caller
     * never has to think about HTTP semantics.
     */
    private function requireJson(Response $response): mixed
    {
        if ($response->failed()) {
            Log::warning('TFN request failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
                'url'    => (string) $response->effectiveUri(),
            ]);
            throw new TfnException(
                message: sprintf('TFN request failed (HTTP %d). %s', $response->status(), $this->extractMessage($response)),
                httpStatus: $response->status(),
                payload: $response->json() ?? [],
            );
        }

        return $response->json();
    }

    private function extractMessage(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            return (string) (
                $json['Message']
                ?? $json['error_description']
                ?? $json['error']
                ?? 'See logs for full response.'
            );
        }
        $body = trim((string) $response->body());
        return $body !== '' ? $body : 'Empty response body.';
    }

    private function url(string $path): string
    {
        return '/' . ltrim($path, '/');
    }

    private function assertConfigured(): void
    {
        if (!config('tfn.enabled')) {
            throw new TfnNotConfiguredException('TFN integration is disabled. Set TFN_ENABLED=true.');
        }
        foreach (['username', 'password', 'customer_number'] as $key) {
            if (blank(config("tfn.{$key}"))) {
                throw new TfnNotConfiguredException(
                    "TFN configuration is incomplete: missing tfn.{$key} (set TFN_" . strtoupper($key) . ' in .env).'
                );
            }
        }
    }

    private function customerNumber(): string
    {
        $number = config('tfn.customer_number');
        if (blank($number)) {
            throw new TfnNotConfiguredException('TFN_CUSTOMER_NUMBER is not configured.');
        }
        return (string) $number;
    }

    private function cacheKey(string $suffix): string
    {
        return config('tfn.cache_prefix', 'tfn:') . $suffix . ':' . config('tfn.customer_number');
    }
}
