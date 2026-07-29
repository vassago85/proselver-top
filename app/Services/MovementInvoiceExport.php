<?php

namespace App\Services;

use App\Models\Job;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Build the customer-invoicing Excel sheet that accounts e-mails to the
 * OEM (FAW today, the others use the same layout with the fuel/extras
 * columns left blank).
 *
 * Column order matches FAW's spreadsheet exactly so accounts can paste
 * straight in without reformatting:
 *
 *   ORDER DATE RECEIVED · MODEL · CHASSIS NO ·
 *   FROM · TO ·
 *   COLLECTION DATE · DELIVERY DATE ·
 *   INVOICE AMOUNT · INVOICE NO ·
 *   Extras / Truck Stop etc · LITRES · AMOUNT
 *
 * Invoice amount + extras are INCLUSIVE of VAT.  Fuel amount is the
 * RAND figure on the slip, no VAT applied.  Any of these can be left
 * blank -- the customer-invoicing page captures them as-and-when.
 */
class MovementInvoiceExport
{
    /** Header row, in display order. */
    public const HEADERS = [
        'ORDER DATE RECEIVED',
        'MODEL',
        'CHASSIS NO',
        'FROM',
        'TO',
        'COLLECTION DATE',
        'DELIVERY DATE',
        'INVOICE AMOUNT',
        'INVOICE NO',
        'Extras / Truck Stop etc',
        'LITRES',
        'AMOUNT',
    ];

    /**
     * Build the workbook and return the binary contents ready to stream.
     *
     * @param  iterable<int, Job>  $jobs        Eager-loaded with company, pickupLocation, deliveryLocation
     * @param  string              $customer    Customer / OEM name for the sheet title
     * @param  ?string             $periodLabel "01-05-2026 to 01-06-2026" or similar
     */
    public function build(iterable $jobs, string $customer, ?string $periodLabel = null): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Sheet title (Excel caps at 31 chars and disallows a few
        // punctuation marks; sanitise defensively).
        $title = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $customer);
        $sheet->setTitle(substr($title ?: 'Movements', 0, 31));

        // Header row.
        $sheet->fromArray(self::HEADERS, null, 'A1', true);

        $lastCol = Coordinate::stringFromColumnIndex(count(self::HEADERS));
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E2E8F0'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => true,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Data rows.
        $row = 2;
        foreach ($jobs as $job) {
            /** @var Job $job */
            $sheet->fromArray([
                $this->fmtDate($job->created_at),
                (string) ($job->model_name ?? ''),
                // Fall back to registration when VIN wasn't captured
                // -- the customer's invoice sheet still needs SOME
                // vehicle identifier per line.
                (string) ($job->vin ?: ($job->registration ?? '')),
                (string) ($job->pickupLocation?->company_name ?? ''),
                (string) ($job->deliveryLocation?->company_name ?? ''),
                $this->fmtDate($job->collected_at),
                $this->fmtDate($job->delivered_at),
                $job->invoice_amount !== null ? (float) $job->invoice_amount : null,
                (string) ($job->invoice_number ?? ''),
                $job->extras_amount !== null ? (float) $job->extras_amount : null,
                $job->fuel_litres !== null ? (float) $job->fuel_litres : null,
                $job->fuel_amount !== null ? (float) $job->fuel_amount : null,
            ], null, "A{$row}", true);
            $row++;
        }

        $lastDataRow = $row - 1;
        if ($lastDataRow >= 2) {
            // Rand format: "R 1 234.56".  Litres: simple decimal.
            $randCols = ['H', 'J', 'L']; // invoice amount, extras, fuel amount
            foreach ($randCols as $c) {
                $sheet->getStyle("{$c}2:{$c}{$lastDataRow}")
                    ->getNumberFormat()
                    ->setFormatCode('"R "#,##0.00');
            }
            $sheet->getStyle("K2:K{$lastDataRow}")
                ->getNumberFormat()
                ->setFormatCode('#,##0.00');

            // Date columns -- A (received), F (collected), G (delivered).
            foreach (['A', 'F', 'G'] as $c) {
                $sheet->getStyle("{$c}2:{$c}{$lastDataRow}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
        }

        // Reasonable widths so accounts doesn't have to hand-resize.
        $widths = [
            'A' => 18, 'B' => 18, 'C' => 22,
            'D' => 28, 'E' => 28,
            'F' => 16, 'G' => 16,
            'H' => 16, 'I' => 14,
            'J' => 18, 'K' => 10, 'L' => 16,
        ];
        foreach ($widths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        $sheet->freezePane('A2');

        // Optional context row above the table -- nice to have when
        // accounts e-mails the sheet so the period is visible without
        // looking at the filename.
        if ($periodLabel) {
            $spreadsheet->getProperties()
                ->setTitle("ProSelver movements for {$customer}")
                ->setSubject($periodLabel)
                ->setCompany('ProSelver');
        }

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        return (string) ob_get_clean();
    }

    /**
     * Suggested filename for the download.  Spaces -> dashes, slashes
     * stripped, lowercased so it works across operating systems.
     */
    public function filename(string $customer, ?CarbonInterface $from, ?CarbonInterface $to): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($customer)));
        $slug = trim($slug, '-') ?: 'customer';
        $window = $from && $to
            ? '-' . $from->format('Y-m-d') . '_to_' . $to->format('Y-m-d')
            : '';
        return "proselver-invoices-{$slug}{$window}.xlsx";
    }

    private function fmtDate($value): string
    {
        if (!$value) {
            return '';
        }
        $dt = $value instanceof CarbonInterface ? $value : \Illuminate\Support\Carbon::parse($value);
        return $dt->format('d-m-Y');
    }
}
