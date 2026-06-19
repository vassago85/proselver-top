<?php

namespace App\Services;

use App\Models\DealerStock;
use App\Support\Documents\IssuerProfile;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Phase 1B — sale delivery note.
 *
 * Renders a single-page delivery note straight off a dealer_stock
 * row when a vehicle is sold off the floor with NO transport job.
 * Same Dompdf 3.0 pipeline as CollectionNoteService, but driven by
 * the stock unit's own attributes (VIN, suffix, variant, colour…)
 * and the buyer / salesperson captured at mark-as-sold time.
 *
 * The masthead re-uses the dealer's IssuerProfile so the note
 * carries the same letterhead (logo + address + VAT + reg) as the
 * branded movement notes.
 */
class SaleDeliveryNoteService
{
    public function generate(DealerStock $stock): string
    {
        $stock->loadMissing(['dealerCompany', 'brand', 'salesperson', 'currentLocation']);

        $company = $stock->dealerCompany;

        $issuer = $company
            ? IssuerProfile::forCompany($company, 'Delivery Note')
            : IssuerProfile::forCourier('Dealer', 'Delivery Note');

        $html = view('documents.sale-delivery-note', [
            'stock'     => $stock,
            'issuer'    => $issuer,
            'docNumber' => $this->documentNumber($stock),
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'sans-serif');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Human-friendly document number derived from the stock row id —
     * stable across reprints so the same sale always prints the same
     * reference. e.g. SDN-000042.
     */
    public function documentNumber(DealerStock $stock): string
    {
        return 'SDN-' . str_pad((string) $stock->id, 6, '0', STR_PAD_LEFT);
    }
}
