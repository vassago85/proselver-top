<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Job;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function generateInvoice(Job $job, int $generatedByUserId): Invoice
    {
        return DB::transaction(function () use ($job, $generatedByUserId) {
            $job->calculateFinancials();
            $job->save();

            $invoiceNumber = $this->generateInvoiceNumber();

            $invoice = Invoice::create([
                'job_id' => $job->id,
                'company_id' => $job->company_id,
                'invoice_number' => $invoiceNumber,
                'subtotal' => $job->total_sell_price - $job->vat_amount,
                'vat_amount' => $job->vat_amount,
                'total' => $job->total_sell_price,
                'status' => Invoice::STATUS_ISSUED,
                'generated_at' => now(),
                'generated_by_user_id' => $generatedByUserId,
            ]);

            $job->transitionTo(Job::STATUS_INVOICED);

            AuditService::log('invoice_generated', 'invoice', $invoice->id, null, [
                'invoice_number' => $invoiceNumber,
                'total' => $invoice->total,
                'job_id' => $job->id,
            ]);

            return $invoice;
        });
    }

    public function generatePdf(Invoice $invoice): string
    {
        $invoice->load(['job.company', 'job.fromHub', 'job.toHub', 'job.brand']);

        $html = view('documents.invoice', ['invoice' => $invoice])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'sans-serif');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    protected function generateInvoiceNumber(): string
    {
        $prefix = 'INV-' . now()->format('ym');
        $last = Invoice::where('invoice_number', 'like', $prefix . '%')
            ->orderByDesc('invoice_number')
            ->lockForUpdate()
            ->first();

        $seq = $last ? ((int) substr($last->invoice_number, -4)) + 1 : 1;

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
