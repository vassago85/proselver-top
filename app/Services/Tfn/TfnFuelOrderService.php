<?php

namespace App\Services\Tfn;

use App\Models\DriverProfile;
use App\Models\Job;
use App\Models\TfnFuelOrderPlacement;
use App\Services\Tfn\Exceptions\TfnException;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Placement service for TFN pre-authorisation orders.
 *
 * Extracted from `resources/views/pages/admin/fuel.blade.php` so both
 * the fuel operations page and the order-show page can place TFN
 * orders through a single tested code path.
 *
 * Responsibilities:
 *   1. Resolve the POS VehicleRegistration TFN expects on every
 *      transaction (never the VIN -- Sikelela 2026-08-28).
 *          - Job's permanent registration wins.
 *          - Fall back to the assigned driver's trade plate for
 *            plateless new-from-plant units.
 *   2. Build the TFN v3 /api/Orders payload identically to the fuel
 *      page (kept byte-for-byte compatible so QA sandbox coverage
 *      carries over).
 *   3. Call `TfnClient::createOrder` in live mode, or synthesise a
 *      DEMO/{YmdHis} order number when the client is not live.
 *   4. Record the placer to `TfnFuelOrderPlacement` and log the
 *      placement -- TFN itself has no "placed by" field.
 *
 * The Volt page still owns UI validation (litres/nights ranges,
 * expiry-in-future, product-code allow-list); this service assumes
 * its callers have already validated the inputs it receives.
 */
class TfnFuelOrderService
{
    public function __construct(private readonly TfnClient $client)
    {
    }

    /**
     * Resolve the POS registration for an arbitrary (registration,
     * trade plate) pair.  Both are optional -- returns null when
     * neither is usable, which is the caller's cue to refuse the
     * order.  Every non-null return is normalised (uppercase, no
     * whitespace or punctuation) the same way `DriverProfile`
     * normalises trade plates at rest.
     */
    public function resolvePosRegistration(?string $vehicleRegistration, ?string $driverTradePlate): ?string
    {
        if (!blank($vehicleRegistration)) {
            return DriverProfile::normalisePlate($vehicleRegistration);
        }
        if (!blank($driverTradePlate)) {
            return DriverProfile::normalisePlate($driverTradePlate);
        }
        return null;
    }

    /**
     * Same rule applied to a Job: prefer the job's permanent plate,
     * fall back to the assigned driver's trade plate.  Returns the
     * pair {registration, source} so the UI can label WHICH plate is
     * about to hit TFN (a permanent plate we know is correct vs. a
     * driver trade plate the operator must eyeball on the truck).
     *
     * @return array{registration:?string, source:'registration'|'trade_plate'|null}
     */
    public function resolvePosRegistrationForJob(Job $job): array
    {
        $reg = $job->registration ?? null;
        if (!blank($reg)) {
            return [
                'registration' => DriverProfile::normalisePlate($reg),
                'source'       => 'registration',
            ];
        }

        $trade = $job->driver?->driverProfile?->trade_plate ?? null;
        if (!blank($trade)) {
            return [
                'registration' => DriverProfile::normalisePlate($trade),
                'source'       => 'trade_plate',
            ];
        }

        return ['registration' => null, 'source' => null];
    }

    /**
     * Place a TFN pre-authorisation order.
     *
     * On live: calls `/api/Orders` and audits the returned OrderNumber.
     * On demo: mints a `DEMO/{YmdHis}` order number and audits that.
     *
     * TFN sends the voucher SMS to `DriverCellNumber` on the entry
     * (unless `SkipSMS` is true) -- pass the assigned driver's
     * cellphone through so the driver gets the redemption code
     * directly.  When empty, TFN has nothing to route the SMS to
     * and the driver has to hear the voucher from ops verbally.
     *
     * @param string $posRegistration     Already-normalised POS plate.
     * @param string $productCode          e.g. 'D0' (diesel) or 'OS' (overnight).
     * @param float  $allocation           Litres for diesel; nights for OS.
     * @param DateTimeInterface $expiresAt Order expiry (TFN local time).
     * @param string $customerReference    Free-form audit text -- job ref.
     * @param string $driverCellNumber     Optional. E.164 or local; TFN
     *                                     accepts either.  Empty = no SMS.
     *
     * @return array{order_number:string, demo:bool}
     * @throws TfnException When TFN rejects the order live.
     */
    public function place(
        string $posRegistration,
        string $productCode,
        float $allocation,
        DateTimeInterface $expiresAt,
        string $customerReference,
        string $driverCellNumber = '',
    ): array {
        $productCode = strtoupper($productCode);
        $validStart  = Carbon::now();
        $validEnd    = Carbon::instance(Carbon::parse($expiresAt));

        // Payload matches TFN v3 POST /api/Orders exactly (confirmed
        // 2026-08-31 against QA sandbox with a real 200 OK response).
        // The empty top-level `OrderNumber`, empty `Entry.Position` /
        // `CurrentVirtualCardNumber`, and the null-UUID
        // `LinkedTransactions[0].TransactionID` are Swashbuckle-style
        // placeholders -- TFN's model binder ignores them on write and
        // the server fills them in on the response.
        $payload = [
            'IsDeleted'                     => false,
            'Planned'                       => false,
            'PlannedReasons'                => '',
            'OrderNumber'                   => '',
            'CustomerNumber'                => config('tfn.customer_number'),
            'SubContractorCustomerNumber'   => '',
            'CustomerReference'             => $customerReference,
            'EntriesCompleteAfterFirstUse'  => true,
            'MaxAllocation'                 => $allocation,
            'SubContractorAccepted'         => false,
            'SubContractorDeclined'         => false,
            'StatusTitle'                   => '',
            'SkipSMS'                       => false,
            'Entries' => [[
                'IsDeleted'                => false,
                'Position'                 => 0,
                'SupplierNumber'           => 0,
                'ProductCode'              => $productCode,
                'VehicleRegistration'      => $posRegistration,
                'CardNumber'               => '',
                'DriverCellNumber'         => trim($driverCellNumber),
                'CurrentVirtualCardNumber' => '',
                'MaxAllocation'            => $allocation,
                'ValidDateStart'           => $validStart->format('Y-m-d\TH:i:s.u'),
                'ValidDateEnd'             => $validEnd->format('Y-m-d\TH:i:s.u'),
                'CustomerReference'        => $customerReference,
                'LinkedTransactions'       => [[
                    'TransactionID' => '00000000-0000-0000-0000-000000000000',
                ]],
            ]],
        ];

        if (!$this->client->isLive()) {
            $orderNumber = 'DEMO/' . now()->format('YmdHis');
            $this->audit(
                orderNumber: $orderNumber,
                registration: $posRegistration,
                productCode: $productCode,
                litres: $allocation,
                customerReference: $customerReference,
            );
            return ['order_number' => $orderNumber, 'demo' => true];
        }

        // TfnClient::createOrder unwraps the ValidationResult/Order/Message
        // envelope and throws TfnException with the server's Message on
        // a non-Successful result.  We deliberately do not catch it here
        // -- the caller wants to flash the exact text TFN returned.
        $order = $this->client->createOrder($payload);
        $orderNumber = (string) ($order['OrderNumber'] ?? '');

        if ($orderNumber !== '') {
            $this->audit(
                orderNumber: $orderNumber,
                registration: $posRegistration,
                productCode: $productCode,
                litres: $allocation,
                customerReference: $customerReference,
            );
        }

        return ['order_number' => $orderNumber, 'demo' => false];
    }

    /**
     * Persist who placed a TFN pre-auth so the open/closed orders
     * tables can show "Placed by".  TFN itself does not return a
     * placer -- CustomerReference is the job ref -- so this is local.
     */
    private function audit(
        string $orderNumber,
        string $registration,
        string $productCode,
        float $litres,
        string $customerReference,
    ): void {
        $user = Auth::user();
        $name = $user?->name ?: ($user?->username ?: 'Unknown');

        TfnFuelOrderPlacement::query()->create([
            'order_number'         => $orderNumber,
            'vehicle_registration' => $registration,
            'product_code'         => $productCode,
            'litres'               => $litres,
            'customer_reference'   => $customerReference !== '' ? $customerReference : null,
            'user_id'              => $user?->id,
            'placed_by_name'       => $name,
            'placed_at'            => now(),
        ]);

        Log::info('TFN fuel order placed', [
            'order_number'         => $orderNumber,
            'vehicle_registration' => $registration,
            'product_code'         => $productCode,
            'litres'               => $litres,
            'customer_reference'   => $customerReference,
            'user_id'              => $user?->id,
            'placed_by'            => $name,
        ]);
    }
}
