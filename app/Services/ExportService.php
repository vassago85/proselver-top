<?php

namespace App\Services;

use Illuminate\Support\Collection;

class ExportService
{
    public function toCsv(Collection $data, array $headers, string $filename): string
    {
        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);

        foreach ($data as $row) {
            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        AuditService::log('csv_export', 'export', null, null, [
            'filename' => $filename,
            'rows' => $data->count(),
            'headers' => $headers,
        ]);

        return $csv;
    }

    public function jobRegisterCsv($query): string
    {
        $data = $query->get()->map(fn($j) => [
            $j->job_number,
            $j->job_type,
            $j->status,
            $j->company?->name,
            $j->fromHub?->name,
            $j->toHub?->name,
            $j->driver?->name,
            $j->scheduled_date?->format('Y-m-d'),
            $j->po_number,
            number_format($j->po_amount ?? 0, 2),
            number_format($j->total_sell_price ?? 0, 2),
            $j->created_at->format('Y-m-d H:i'),
        ]);

        return $this->toCsv($data, [
            'Job #', 'Type', 'Status', 'Company', 'From', 'To',
            'Driver', 'Date', 'PO #', 'PO Amount', 'Total', 'Created',
        ], 'job_register.csv');
    }

    public function invoiceReportCsv($query): string
    {
        $data = $query->get()->map(fn($i) => [
            $i->invoice_number,
            $i->job?->job_number,
            $i->company?->name,
            number_format($i->subtotal, 2),
            number_format($i->vat_amount, 2),
            number_format($i->total, 2),
            $i->status,
            $i->generated_at?->format('Y-m-d'),
        ]);

        return $this->toCsv($data, [
            'Invoice #', 'Job #', 'Company', 'Subtotal', 'VAT', 'Total', 'Status', 'Date',
        ], 'invoice_report.csv');
    }
}
