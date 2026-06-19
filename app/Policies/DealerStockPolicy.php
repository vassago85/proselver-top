<?php

namespace App\Policies;

use App\Models\DealerStock;
use App\Models\User;

class DealerStockPolicy
{
    /**
     * Platform / developer staff bypass — matches the other policies.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isDeveloper() || $user->belongsToPlatformOwner()) {
            return true;
        }

        return null;
    }

    /**
     * Print (or reprint) the sale delivery note for a sold stock unit.
     *
     * Two routes to allow:
     *   1. A stock manager (manage_dealer_stock) whose visible
     *      companies include the stock's dealership — covers the
     *      dealer principal / sales manager / stock controller and
     *      any franchise CEO promoted to group principal.
     *   2. The originating salesperson reprinting their own sale,
     *      even without the manage permission, as long as the unit
     *      is actually sold.
     */
    public function printSaleNote(User $user, DealerStock $stock): bool
    {
        $inScope = in_array($stock->dealer_company_id, $user->visibleCompanyIds(), true);

        if ($user->hasPermission('manage_dealer_stock') && $inScope) {
            return true;
        }

        return $user->id === $stock->salesperson_user_id
            && $stock->status === DealerStock::STATUS_SOLD;
    }
}
