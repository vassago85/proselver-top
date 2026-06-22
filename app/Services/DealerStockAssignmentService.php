<?php

namespace App\Services;

use App\Models\BodyBuilderDealerLink;
use App\Models\Company;
use App\Models\DealerStock;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * OEM-direct arrival workflow at a body-builder.
 *
 * Two operations:
 *
 *   1. recordOemArrival() -- a chassis turned up at the BB before
 *      anyone told us which dealer it's for.  We create an unassigned
 *      dealer_stock row (dealer_company_id NULL, oem_company_id set)
 *      so the vehicle exists in our world and the BB yard list shows
 *      it.  Idempotent on VIN: re-recording the same VIN at the same
 *      BB returns the existing row.
 *
 *   2. assignToDealer() -- BB (or ops) identifies which dealer the
 *      chassis is for.  We stamp dealer_company_id, audit the change,
 *      and auto-create the BB->dealer link (if not present) so the
 *      dealer can immediately see the vehicle on their stock list and
 *      the BB has continued authority to raise movement requests on
 *      it.
 *
 * Auto-linking on assign deliberately mirrors the BodyBuilderRequest
 * approve flow: in both cases the system has now learned "this BB
 * legitimately holds vehicles for this dealer", so the link is a
 * natural consequence.  The dealer can pause it later from
 * /customer/body-builders if they object.
 */
class DealerStockAssignmentService
{
    /**
     * Record a chassis that's just turned up at a BB workshop.
     *
     * @param  array<string, mixed>  $attributes  vin (required),
     *         brand_id, model_name, colour, suffix, variant,
     *         description, model_year, engine_number, registration,
     *         oem_company_id, notes (mapped to bb_build_notes).
     * @throws InvalidArgumentException if the BB doesn't own the
     *         location, or if a DIFFERENT dealer already owns a row
     *         with this VIN (the existing dealer's row should be used
     *         instead -- this prevents two rows competing for the
     *         same chassis).
     */
    public function recordOemArrival(
        Company $bb,
        Location $location,
        User $recordedBy,
        array $attributes,
    ): DealerStock {
        if (!$bb->isBodyBuilder()) {
            throw new InvalidArgumentException('Arrivals can only be recorded against a body-builder company.');
        }
        if ($location->company_id !== $bb->id) {
            throw new InvalidArgumentException('Workshop location does not belong to this body builder.');
        }

        $vin = isset($attributes['vin']) ? strtoupper(trim((string) $attributes['vin'])) : '';
        if ($vin === '') {
            throw new InvalidArgumentException('VIN is required to record an OEM arrival.');
        }

        return DB::transaction(function () use ($bb, $location, $recordedBy, $attributes, $vin) {
            // If a dealer already has this VIN on their books we use
            // that row -- this is the "the chassis was actually
            // already known to us" case.  We update its location to
            // the BB and return it; no new row is created.
            $existingAssigned = DealerStock::where('vin', $vin)
                ->whereNotNull('dealer_company_id')
                ->first();
            if ($existingAssigned) {
                $existingAssigned->update([
                    'previous_location_type' => $existingAssigned->current_location_type,
                    'current_location_type'  => DealerStock::LOCATION_BODY_BUILDER,
                    'current_location_id'    => $location->id,
                ]);
                AuditService::log('dealer_stock_arrived_at_bb', 'dealer_stock', $existingAssigned->id, null, [
                    'bb_company_id'    => $bb->id,
                    'bb_location_id'   => $location->id,
                    'recorded_by'      => $recordedBy->id,
                    'pre_existing_row' => true,
                ]);
                return $existingAssigned;
            }

            // Re-recording an already-unassigned VIN -- update its
            // location + OEM if we now know it, but don't duplicate.
            $existingUnassigned = DealerStock::where('vin', $vin)
                ->whereNull('dealer_company_id')
                ->first();
            if ($existingUnassigned) {
                $existingUnassigned->update(array_filter([
                    'current_location_id'   => $location->id,
                    'current_location_type' => DealerStock::LOCATION_BODY_BUILDER,
                    'oem_company_id'        => $attributes['oem_company_id'] ?? $existingUnassigned->oem_company_id,
                    'brand_id'              => $attributes['brand_id']      ?? $existingUnassigned->brand_id,
                    'model_name'            => $attributes['model_name']    ?? $existingUnassigned->model_name,
                    'colour'                => $attributes['colour']        ?? $existingUnassigned->colour,
                ], fn ($v) => $v !== null));
                return $existingUnassigned;
            }

            $stock = DealerStock::create([
                'dealer_company_id'     => null,
                'oem_company_id'        => $attributes['oem_company_id'] ?? null,
                'vin'                   => $vin,
                'engine_number'         => $attributes['engine_number'] ?? null,
                'registration'          => $attributes['registration']  ?? null,
                'brand_id'              => $attributes['brand_id']      ?? null,
                'model_name'            => $attributes['model_name']    ?? null,
                'suffix'                => $attributes['suffix']        ?? null,
                'variant'               => $attributes['variant']       ?? null,
                'description'           => $attributes['description']   ?? null,
                'colour'                => $attributes['colour']        ?? null,
                'model_year'            => $attributes['model_year']    ?? null,
                'current_location_type' => DealerStock::LOCATION_BODY_BUILDER,
                'current_location_id'   => $location->id,
                'status'                => DealerStock::STATUS_AVAILABLE,
                'bb_build_notes'        => $attributes['notes'] ?? null,
            ]);

            AuditService::log('oem_arrival_recorded', 'dealer_stock', $stock->id, null, [
                'bb_company_id'  => $bb->id,
                'bb_location_id' => $location->id,
                'recorded_by'    => $recordedBy->id,
                'oem_company_id' => $stock->oem_company_id,
                'vin'            => $vin,
            ]);

            return $stock;
        });
    }

    /**
     * Allocate an unassigned stock row to a dealer.  Stamps
     * dealer_company_id, auto-creates the BB->dealer link (so the
     * BB keeps authority over the vehicle without an extra step),
     * and audit-logs the assignment.
     */
    public function assignToDealer(DealerStock $stock, Company $dealer, User $assigner): DealerStock
    {
        if ($stock->dealer_company_id !== null) {
            throw new RuntimeException("Stock #{$stock->id} is already assigned to a dealer.");
        }
        if (!$dealer->isDealer()) {
            throw new InvalidArgumentException('Assignment target must be a dealer company.');
        }

        return DB::transaction(function () use ($stock, $dealer, $assigner) {
            // Duplicate guard: if this VIN already exists on this
            // dealer's books, refuse rather than crash on the
            // unique(dealer_company_id, vin) constraint.  Caller can
            // handle the duplicate by merging manually.
            $existing = DealerStock::where('dealer_company_id', $dealer->id)
                ->where('vin', $stock->vin)
                ->first();
            if ($existing && $existing->id !== $stock->id) {
                throw new RuntimeException("Dealer {$dealer->name} already has VIN {$stock->vin} on their books -- merge the two rows manually.");
            }

            $before = ['dealer_company_id' => null];
            $stock->dealer_company_id = $dealer->id;
            $stock->save();

            // BB <-> dealer link.  If the stock is sitting at a BB
            // workshop, we infer the BB from current_location_id
            // and ensure the link exists + is active.
            $bbCompanyId = null;
            if ($stock->current_location_type === DealerStock::LOCATION_BODY_BUILDER
                && $stock->current_location_id) {
                $loc = Location::find($stock->current_location_id);
                if ($loc) {
                    $bbCompanyId = $loc->company_id;
                }
            }

            if ($bbCompanyId) {
                $link = BodyBuilderDealerLink::firstOrNew([
                    'dealer_company_id'       => $dealer->id,
                    'body_builder_company_id' => $bbCompanyId,
                ]);
                $link->is_active = true;
                if (!$link->exists) {
                    $link->linked_by_user_id = $assigner->id;
                    $link->notes = 'Auto-linked: BB assigned an OEM-direct arrival to this dealer.';
                }
                $link->save();
            }

            AuditService::log('dealer_stock_assigned_to_dealer', 'dealer_stock', $stock->id, $before, [
                'dealer_company_id' => $dealer->id,
                'assigned_by'       => $assigner->id,
                'vin'               => $stock->vin,
                'bb_company_id'     => $bbCompanyId,
            ]);

            return $stock->fresh();
        });
    }
}
