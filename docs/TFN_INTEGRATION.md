# TFN Integration — Scoping Document

**Prepared for:** Mr. S. Mzele (Proselver Technologies) — meeting with TFN Account Manager Sibusiso Sibisi
**Prepared by:** Developer (ProBooking / TRIDENT)
**Date:** 2026-08-25
**Status:** Discovery — no code changes made yet
**Source docs:**
- TFN developer portal: https://app.tfn.co.za/api/
- Prod Swagger: https://api.tfn.co.za/v3/swagger
- QA Swagger: https://customerapi.qa.tfn.co.za/v3/swagger

---

## Contents

1. [What TFN's API actually offers](#1-what-tfns-api-actually-offers)
2. [Where this plugs into TRIDENT](#2-where-this-plugs-into-trident)
3. [Proposed integration architecture](#3-proposed-integration-architecture)
4. [Rollout plan](#4-rollout-plan)
5. [Questions for TFN](#5-questions-for-tfn)
6. [Assumptions & open risks](#6-assumptions--open-risks)

---

## 1. What TFN's API actually offers

TFN CustomerAPI v3 is a RESTful, OAuth 2 password-grant, versioned API. Every call must include `api-version=3` (query string) and, once authenticated, `Authorization: Bearer <access_token>`.

### Environments

| Environment | Host |
|---|---|
| Production | `https://api.tfn.co.za` |
| QA | `https://customerapi.qa.tfn.co.za` |

### Authentication

- `POST /api/token#Login` (`application/x-www-form-urlencoded`, `grant_type=password`, `client_ID=customerAPI`, `username`, `password`) → `access_token`, `refresh_token`, `expires_in`.
- `POST /api/token#RefreshAccessToken` — silent refresh.
- `POST /api/Logout` — server-side invalidation.
- `GET /api/UserStatus` — check whether the service account is locked / login attempts / last activity.
- `GET /api/Ping` — health / server timestamp.

### Full endpoint inventory

| Domain | Endpoint(s) | Verbs | Purpose |
|---|---|---|---|
| **Auth** | `/api/token#Login`, `/api/token#RefreshAccessToken`, `/api/Logout`, `/api/UserStatus`, `/api/Ping` | POST/GET | OAuth + health |
| **Depots** | `/api/Depots` | GET | Depots the user may transact at (GPS, product mix, marketing category: Refuel2Save / Standard / CrossBorder) |
| **Drivers** | `/api/Drivers` | GET / POST | List + upsert drivers into TFN |
| **Vehicles** | `/api/Vehicle`, `/api/Vehicle/{vehicleRegistration}` | GET / POST / PUT | List, create, update (registration, fleet number, tank size, status, external number) |
| **Virtual Card** | `/api/VirtualCardNumber` | GET | Current virtual card number per vehicle — the token used to authorise a pump. May be single-use or time-boxed (`StartDate`/`ExpiryDate`, `IsOneUse`). |
| **Pricing** | `/api/Pricing` | GET | Current price per product code |
| **Orders (pre-authorisations)** | `/api/Orders`, `/api/Orders/{orderNumber}`, `/api/Orders/{entryNumber}` | GET / POST / PUT / DELETE | Create/update/retrieve/delete a fuel order (pre-authorisation) against a vehicle before it arrives at a depot |
| **Transactions (actuals)** | `/api/Transactions`, `/api/TransactionsWithUtilisedOrders`, `/api/TransactionsWithUtilisedOrders/{transactionId}` | GET | Pull captured transactions since `capturedDateAfter` — 100/req, 3-month window (50/req, 1-month for the utilised-orders variant) |
| **Sub-accounts** | `/api/subAccount` | GET / POST / PUT / DELETE | Onboard / update / discontinue driver sub-accounts, with their vehicles and driver-vehicle links |
| **Sub-account balances & credit** | `/api/SubAccountBalance`, `/api/SubAccountCreditLimit`, `/api/SubAccountCreditLimits`, `/api/SubAccountAggregateLitres` | GET / POST | Balance, credit limits (single and bulk), aggregated litres per product |
| **Sub-account payments** | `/api/SubAccountPayment` | POST | Apply a debit or credit to a sub-account |
| **Webhook** | Separate swagger — "Web Hook – Receive Transactions Sample" | POST *(inbound)* | TFN pushes transactions to us in near real-time |

### Product codes we should recognise

| Code | Meaning | Category |
|---|---|---|
| `D0` | Diesel (50ppm) | Fuel — **the only grade Proselver transacts** |
| `D1` | Diesel (500ppm) | Fuel — not used by Proselver; kept in the label map only |
| `D3` | Diesel (10ppm) | Fuel — not used by Proselver; kept in the label map only |
| `ULP93` | Petrol (ULP93) | Fuel — **pending Director approval** |
| `ULP95` | Petrol (ULP95) | Fuel — **pending Director approval** |
| `F2` | AdBlue | Fluid |
| `F` | Oil | Fluid |
| `PAR` | Parts | Non-fuel |
| `WKS` | Workshop | Non-fuel |
| `W` | Truck wash | Non-fuel |
| `SHO` | Shower | Non-fuel |
| `OS` | Overnight stay | Non-fuel |
| `L1` | Laundry | Non-fuel |
| `CAN` | Canteen | Non-fuel |
| `SHP` | Shop | Non-fuel |
| `WB` | Weighbridge | Non-fuel |
| `IPB` | IP (Bulk) | Non-fuel |
| `EW` | eWallet allocation | Financial |

Transaction type codes (non-purchase) to handle: `CX` correction, `CC` customer credit, `CD` account debit, and various fees (`MCF`, `ACF`, `OF`, `OR`, `EM`, `SMS`, `HBDF`, `ETMF`, `EWF`, `ASF`, `LCP`, `CDR`, `CDF`, `ER`). All ride on the same `Transactions` endpoint.

### The Transaction payload

Each transaction returned by TFN carries:

- `TransactionID` (UUID), `TransactionReference`, `TransactionDate`, `CapturedDate`
- `CustomerNumber`, `CustomerExternalNumber`, `ChildCustomerNumber`, `ChildCustomerExternalNumber`
- `ProductCode`, `TransactionTypeCode`
- `SupplierName`, `SupplierNumber` (which depot)
- `VehicleRegistration`, `VehicleFleetNumber`, `VehicleExternalNumber`
- `Amount`, `VAT`, `ChildAccountAmount`, `ChildAccountVAT`
- `Litres`, `Odometer`
- `UtilisedOrders[]` — pre-authorisations burnt down by this transaction
- `Identifier` — the identifiers used to identify the customer at the pump
- `ReversedTransaction` — populated when this row is a reversal of a prior transaction

This is enough data to auto-reconcile every drop of diesel to a specific trip and driver without any re-keying.

---

## 2. Where this plugs into TRIDENT

TRIDENT already models everything a TFN integration needs — the mapping is direct and no new domain concepts are required.

| TRIDENT concept | TFN concept | Change |
|---|---|---|
| `Job.cost_fuel` (per-job internal cost, currently manual) | `Transaction.Amount` filtered by product `D0/D1/D3` | Derive from actuals — remove manual capture |
| `PettyCashEntry` for fuel + fuel receipt `JobDocument`s | `Transaction` rows + `SupplierName` + digital receipt reference | The whole "driver photographs slip → ops re-keys → reconciles against advance" loop collapses to a signed digital record captured at the pump |
| `advance_food`, `advance_taxi`, `advance_accommodation` on `PettyCashPlan` | `ProductCode` = `CAN` / `OS` / `SHO` | If drivers use TFN sites for meals & overnight stays, those become card transactions too — physical cash exposure drops materially |
| Vehicles referenced from `Job.vin` / registration | `/api/Vehicle` + `/api/VirtualCardNumber` | Store `tfn_vehicle_registration` on our vehicle records; cache the current virtual card and its expiry |
| `DriverProfile` | `/api/Drivers` + sub-account driver records | Two-way sync: onboarding a driver in TRIDENT creates the TFN sub-account with correct vehicle links |
| `Company` (Proselver-owned fleet) | Sub-account under Proselver's TFN parent | Credit limits live in TFN and are mirrored into TRIDENT for dispatch-time decisions |
| `TripCostEstimator` fuel component | `/api/Pricing` (live product price) | Replace the static R/litre constant with a live lookup at planning time |
| `Trip.status = completed` | `GET /api/TransactionsWithUtilisedOrders` filtered to that trip window | Auto-attach TFN spend to the completed trip for invoicing and margin calculation |

Existing files that will change (short list, from the current codebase):

- `app/Models/Job.php` — new relation to `FuelTransaction`; `cost_fuel` becomes derived
- `app/Models/Trip.php`, `app/Models/TripStop.php` — the trip window is the reconciliation key
- `app/Services/PettyCashTransferService.php` — split "fuel advance" out of cash-in-hand; only food, taxi and accommodation remain as physical cash if drivers don't use the card there
- `app/Services/TripCostEstimator.php` — replace static fuel-price constant with live `Pricing` lookup
- `app/Services/MovementInvoiceExport.php` — include TFN-sourced fuel line items with supplier and litre detail
- `app/Services/TripReportService.php` — "actual vs. estimated fuel" panel

---

## 3. Proposed integration architecture

Self-contained module under `App\Services\Tfn\`, thin persistence layer, no coupling from the TRIDENT domain into TFN types.

### New service classes

| Class | Responsibility |
|---|---|
| `TfnClient` | HTTP wrapper (Symfony HttpClient — already in `composer.json`). Injects `api-version`, base URL, bearer token. Retries with exponential backoff on 5xx. |
| `TfnTokenManager` | OAuth token cache in Redis, transparent refresh, single-flight refresh under contention, retry-once-on-401. |
| `TfnTransactionSyncer` | Cursor-based poller. Respects the 100/req and 3-month window for `/api/Transactions`, and 50/req / 1-month for `/api/TransactionsWithUtilisedOrders`. |
| `TfnVehicleSyncer` | Bi-directional vehicle upsert (TRIDENT ↔ TFN). Reads current virtual card and its expiry. |
| `TfnDriverSyncer` | Driver + sub-account onboarding, updates and discontinuation. |
| `TfnPricingService` | Cached (short TTL) product-code → R/litre lookup. |
| `TfnWebhookController` | Receives real-time transactions. Dedup by `TransactionID`. Applies reversals via `ReversedTransaction`. |
| `TfnReconciliationService` | Matches a `Transaction` to an open job/trip using `VehicleRegistration` + `TransactionDate` within the job window. |

### New tables (migrations)

| Table | Purpose |
|---|---|
| `tfn_transactions` | Verbatim copy of every transaction, immutable audit. FK to `transport_jobs` / `trips` / vehicles once reconciled. |
| `tfn_vehicles` | Mirror of TFN vehicle state incl. current virtual card + expiry. |
| `tfn_sub_accounts` | Driver / vehicle segregation with credit limits and balances. |
| `tfn_sync_cursors` | Last successful `capturedDateAfter` per stream so polling is safely resumable. |
| `tfn_webhook_events` | Raw webhook payloads for replay and forensics. |

### New config

`config/tfn.php` — `base_url`, `qa_base_url`, `customer_number`, `client_id`, credential secret, API version, webhook shared secret, polling cadence.

### Queue jobs

| Job | Trigger |
|---|---|
| `SyncTfnTransactionsJob` | Scheduled every 5 min (belt-and-braces vs webhook) |
| `ReconcileFuelToJobJob` | Dispatched when a transaction arrives (webhook or poll) |
| `RefreshTfnVehicleCardsJob` | Nightly, before virtual card expiry |
| `SyncTfnPricingJob` | Hourly |

### Security

- OAuth secrets in `.env`; never committed. Prod + QA credentials issued by TFN.
- Webhook receiver validates HMAC / shared secret (mechanism TBC — question for TFN).
- All outbound calls behind Laravel's rate limiter to respect TFN's per-endpoint caps.
- Source IP allowlist for the webhook receiver.

---

## 4. Rollout plan

### Phase 0 — Access & discovery (this week)
1. Obtain QA credentials (`client_ID`, `username`, `password`, `customerNumber`).
2. Obtain the "Web Hook – Receive Transactions Sample" swagger URL.
3. Confirm HMAC scheme, retry policy and source IP allowlist for the webhook.
4. Confirm the current parent customer number for Proselver and the list of existing sub-accounts, vehicles and drivers already on TFN, so the first sync is a merge and not a duplicate.

### Phase 1 — Read-only sync (week 1–2)
- Ingest Depots, Vehicles, Drivers, Pricing, Transactions from QA every 5 min.
- Reconcile transactions to jobs and expose an "actual fuel" figure on the job detail screen.
- No writes to TFN — shadow-mode only.

### Phase 2 — Webhook + dashboards (week 3)
- Register the webhook receiver.
- Add a "Fuel" dashboard: litres by driver, vehicle, month; actual vs. estimated per job; product-mix breakdown.

### Phase 3 — Writes (week 4)
- Auto-create TFN sub-accounts and driver-vehicle links when TRIDENT onboards a new driver.
- Pre-authorise fuel via `/api/Orders` when a trip is confirmed *(optional — depends on whether we want card limits to be trip-scoped)*.
- Adjust credit limits via `/api/SubAccountCreditLimit` when TRIDENT's own risk rules change.

### Phase 4 — Petrol (blocked)
- Feature-flag `ULP93`/`ULP95` product codes; enable the moment TFN approves petrol for Proselver.

---

## 5. Questions for TFN

1. **QA sandbox credentials + a test customer number** — nothing starts without these.
2. **Webhook auth scheme + retry semantics** — HMAC secret? Idempotency-Key header? How many retries on non-2xx?
3. **`customerNumber` model** — one per company or one Proselver parent with sub-accounts per driver? Drives table design.
4. **Explicit rate limits per endpoint** — Swagger shows a few; we need the full numbers before scheduling polls.
5. **Reversal semantics** — does a reversal come as a new row with `ReversedTransaction` populated, or does the original mutate? (Schema suggests the former; confirm.)
6. **Reversal timing** — what's the maximum realistic delay between an original transaction and its reversal? Can a reversal arrive days or weeks later (i.e. after we've already closed the trip reconciliation on our side)? Any SLA?
7. **`VehicleRegistration` max length** — the Swagger declares the field as `type: string` with no `maxLength` or `pattern`. What length does the server actually enforce, and is any normalisation applied (case, spaces, hyphens)?
8. **Using a VIN as the vehicle identifier** — TRIDENT already tracks vehicles by VIN (17-character ISO 3779 identifier), which is permanent across re-plating and ownership changes. Would TFN accept the full VIN in the `VehicleRegistration` field instead of the licence plate? If yes, is the whole 17 characters supported, and does the receipt / pump-side identifier the driver keys in still work the same way?
9. **Timezone on transaction timestamps** — `TransactionDate` and `CapturedDate` are ISO 8601 strings; are they SAST (UTC+2), UTC, or driven by the client's zone? Our system is UTC-internal so we need to know where the conversion happens.
10. **Existing Proselver data snapshot** — before we run the first sync, can you provide the current list of registered vehicles, drivers and sub-accounts (with fleet numbers, registrations, status) so we can dedupe against what we hold and flag anything dormant or misaligned?
11. **Credential rotation policy** — is there a mandated rotation cadence on the service-account password? If so, is there an out-of-band notification (email / portal) before a rotation is enforced, so we can update our secret store without an outage?
12. **Order vs. virtual card overlap** — is `/api/Orders` still worth using when virtual card numbers already scope spend? What do TFN's higher-volume customers actually use?
13. **Petrol timeline** — commit date once Director signs off.
14. **API v3 deprecation timeline** — anyone integrating now wants a heads-up on v4.
15. **Sample transaction JSON payloads** (real, sanitised) — including edge cases: nulls, unusual product codes, service-provider transactions with `ChildCustomerNumber`, reversals, corrections.

---

## 6. Assumptions & open risks

**Assumptions**
- TFN's product-code list in the Swagger is authoritative and stable.
- All Proselver vehicles are already registered with TFN under a single parent customer number.
- `VehicleRegistration` in TFN is the same string as we hold in TRIDENT (or is mappable). If it isn't, we need `tfn_vehicle_registration` as a separate field with an operational reconciliation step.
- Webhook delivery is best-effort and idempotent — we treat polling as the source of truth if the two ever diverge.

**Open risks**
- **Data quality on first sync**: existing TFN records may have inconsistent registrations, missing fleet numbers, or dormant vehicles. Plan a reconciliation UI for the first sync run.
- **Credential rotation**: TFN uses a single service account per customer today. If they mandate rotation, the token manager needs an out-of-band update path (currently `.env` — should move to a secrets store on production).
- **Timezone handling**: `TransactionDate` and `CapturedDate` are ISO strings — confirm they're in SAST. All TRIDENT timestamps are UTC; conversion happens at the boundary.
- **Reversals arriving out of order**: a reversal captured later than the trip close-out could re-open a "completed" reconciliation. `TfnReconciliationService` must handle this by treating a reversal as an event, not a state.
- **Petrol scope creep**: if petrol is approved mid-build, we don't want a hard cut-over — the feature flag is essential.

---

*End of document.*
