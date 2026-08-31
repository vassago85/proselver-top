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
     * Per-sub-account fuel aggregates for a given month.
     *
     * TFN v3 /api/SubAccountAggregateLitres (confirmed via QA probe on
     * 2026-08-31): requires a `month` query param in `yyyyMM` form,
     * e.g. `202608` for August 2026.  Omitting the parameter returns
     * 404 "No HTTP resource was found ..." because the api-versioning
     * router can't resolve the operation without a required param;
     * passing anything other than 6 digits returns a helpful HTTP 400
     * "Invalid month 'X' received, expected yyyyMM format e.g. 202607".
     *
     * The same missing-required-param bug that produced the mysterious
     * 405 UnsupportedApiVersion on /api/Orders (fixed in PR #7) also
     * gave us the earlier 404 here.
     *
     * Response is a flat array of per-sub-account rows for the
     * requested month; empty array means no fuel was purchased under
     * any sub-account that month (which is what QA returns today).
     */
    public function subAccountAggregateLitres(?DateTimeInterface $month = null): array
    {
        $month = $month ?? Carbon::now();

        return (array) $this->requireJson($this->get('/api/SubAccountAggregateLitres', [
            'customerNumber' => $this->customerNumber(),
            'month'          => $month->format('Ym'),
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
     * List pre-authorisation orders modified since a given date.
     *
     * TFN v3 /api/Orders (per 2026-08-31 QA probe against the real
     * swagger spec fetched from https://customerapi.qa.tfn.co.za/v3/swagger):
     *
     *   GET /api/Orders
     *     customerNumber=<cn>          required
     *     modifiedAfterDate=<iso8601>  required (rejected earlier than
     *                                  ~14 days ago with a 400 "Please
     *                                  specify a modifiedAfterDate
     *                                  after <date>")
     *     api-version=3                required
     *
     * The historical 405 "UnsupportedApiVersion" turned out to be a
     * misleading routing error -- when a REQUIRED query param is
     * missing, ASP.NET's version negotiator kicks in first and can't
     * find a matching (method, version) tuple, so it returns 405
     * instead of the more informative "missing parameter" error.
     * Passing modifiedAfterDate makes it resolve cleanly.
     *
     * Response is a flat array of Order objects (each with a nested
     * Entries[] array); see TfnDemoFixtures::orders() for the exact
     * shape.
     */
    public function orders(?DateTimeInterface $modifiedAfterDate = null): array
    {
        // QA rejects dates earlier than ~14 days ago; default to 13
        // days so the caller has one day of head-room before the
        // window would flip.  Tune upward if TFN widens the window.
        $modifiedAfterDate = $modifiedAfterDate ?? Carbon::now()->subDays(13);

        return (array) $this->requireJson($this->get('/api/Orders', [
            'customerNumber'    => $this->customerNumber(),
            'modifiedAfterDate' => $modifiedAfterDate->format(DATE_ATOM),
        ]));
    }

    /* ----------------------------------------------------------------
     |  Writes -- diesel ordering
     |----------------------------------------------------------------*/

    /**
     * Place a pre-authorisation ("order") against a vehicle.  The
     * order later gets consumed by transactions at the pump, and the
     * resulting transaction rows can be joined back via
     * `Entry.LinkedTransactions[]` on the order (populated on GET
     * /api/Orders).
     *
     * TFN v3 POST /api/Orders (per 2026-08-31 QA probe):
     *
     *   POST /api/Orders?newRecordIdentifier=<uuid>&api-version=3
     *
     * `newRecordIdentifier` is a REQUIRED client-generated UUID that
     * acts as an idempotency key.  Retrying with the same value gets
     * the same order back; TFN's routing layer 405s the request if
     * it's missing (the misleading "UnsupportedApiVersion" error).
     * We auto-generate one if the caller doesn't supply one so retry
     * safety is opt-in but explicit.
     *
     * The response is wrapped:
     *
     *   { "ValidationResult": "Successful",
     *     "Order":            { ...OrderSerializableV2... },
     *     "Message":          null | "..." }
     *
     * We unwrap and return just `Order` so callers don't have to
     * know about the envelope.  On a non-"Successful" ValidationResult
     * we throw TfnException carrying the `Message` field so the fuel
     * Volt page's placeOrder() flashes it in the UI.
     *
     * @param  array   $order              Shape follows OrderSerializableV2 from
     *                                     the swagger.  Caller is expected to
     *                                     have run client-side validation.
     * @param  ?string $newRecordIdentifier Optional idempotency UUID (auto if null).
     * @return array                        The created Order (fully populated
     *                                     with server-side fields including
     *                                     OrderNumber, Entry Position, and the
     *                                     issued CurrentVirtualCardNumber).
     */
    public function createOrder(array $order, ?string $newRecordIdentifier = null): array
    {
        $newRecordIdentifier ??= (string) \Illuminate\Support\Str::uuid();

        // Laravel's HTTP client silently discards any query string on
        // the URL argument to ->post() when withQueryParameters() has
        // been set on the pending request (as `request()` does with
        // api-version).  So the idempotency UUID goes through
        // withQueryParameters() to make sure it reaches the wire.
        $call = fn () => $this->request()
            ->withQueryParameters(['newRecordIdentifier' => $newRecordIdentifier])
            ->withBody(json_encode($order), 'application/json')
            ->post($this->url('/api/Orders'));

        $response = $this->handleAuthRetry($call(), $call);
        $body = (array) $this->requireJson($response);

        // Unwrap the { ValidationResult, Order, Message } envelope.  A
        // failed ValidationResult carries operator-friendly text in
        // Message; surface it as an exception so the Volt page flashes
        // exactly what TFN said.
        $result = $body['ValidationResult'] ?? null;
        if ($result !== null && strcasecmp((string) $result, 'Successful') !== 0) {
            throw new TfnException(
                message: 'TFN rejected the order: ' . ((string) ($body['Message'] ?? $result)),
                payload: $body,
            );
        }

        // Prefer the unwrapped Order; some legacy stubs may still
        // return the flat shape, so accept either.
        return isset($body['Order']) && is_array($body['Order'])
            ? $body['Order']
            : $body;
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
