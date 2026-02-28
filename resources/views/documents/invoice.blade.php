<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #333; margin: 40px; }
        .header { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .company-name { font-size: 24px; font-weight: bold; color: #1e40af; }
        .invoice-title { font-size: 20px; font-weight: bold; text-align: right; }
        .invoice-number { font-size: 14px; color: #666; text-align: right; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background-color: #f3f4f6; padding: 10px; text-align: left; font-size: 11px; text-transform: uppercase; color: #666; border-bottom: 2px solid #e5e7eb; }
        td { padding: 10px; border-bottom: 1px solid #e5e7eb; }
        .totals { margin-top: 20px; text-align: right; }
        .totals td { border: none; padding: 5px 10px; }
        .total-label { font-weight: bold; }
        .grand-total { font-size: 16px; font-weight: bold; color: #1e40af; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 10px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <table style="width: 100%; margin-bottom: 30px;">
        <tr>
            <td style="border: none; padding: 0;">
                <div class="company-name">Proselver TOP</div>
                <div style="color: #666; margin-top: 5px;">Transport Operations Platform</div>
            </td>
            <td style="border: none; padding: 0; text-align: right;">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-number">{{ $invoice->invoice_number }}</div>
                <div style="margin-top: 10px; color: #666;">Date: {{ $invoice->generated_at->format('d M Y') }}</div>
            </td>
        </tr>
    </table>

    <table style="margin-bottom: 20px;">
        <tr>
            <td style="border: none; padding: 0; width: 50%; vertical-align: top;">
                <strong>Bill To:</strong><br>
                {{ $invoice->job->company->name }}<br>
                @if($invoice->job->company->address){{ $invoice->job->company->address }}<br>@endif
                @if($invoice->job->company->vat_number)VAT: {{ $invoice->job->company->vat_number }}@endif
            </td>
            <td style="border: none; padding: 0; width: 50%; vertical-align: top; text-align: right;">
                <strong>Job #:</strong> {{ $invoice->job->job_number }}<br>
                <strong>Type:</strong> {{ $invoice->job->isTransport() ? 'Transport' : 'Yard Work' }}<br>
                @if($invoice->job->isTransport())
                <strong>Route:</strong> {{ $invoice->job->pickupLocation?->company_name }} → {{ $invoice->job->deliveryLocation?->company_name }}<br>
                @endif
                <strong>Date:</strong> {{ $invoice->job->scheduled_date?->format('d M Y') }}
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th style="text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    @if($invoice->job->isTransport())
                        Transport: {{ $invoice->job->pickupLocation?->company_name }} → {{ $invoice->job->deliveryLocation?->company_name }}
                        @if($invoice->job->brand)<br>{{ $invoice->job->brand->name }} {{ $invoice->job->model_name }}@endif
                    @else
                        Yard Work: {{ $invoice->job->drivers_required }} driver(s) × {{ $invoice->job->hours_required }}h
                    @endif
                </td>
                <td style="text-align: right;">R{{ number_format($invoice->job->base_transport_price, 2) }}</td>
            </tr>
            @if($invoice->job->delivery_fuel_price > 0)
            <tr>
                <td>Delivery Fuel</td>
                <td style="text-align: right;">R{{ number_format($invoice->job->delivery_fuel_price, 2) }}</td>
            </tr>
            @endif
            @if($invoice->job->penalty_amount > 0)
            <tr>
                <td>Late Cancellation Penalty</td>
                <td style="text-align: right;">R{{ number_format($invoice->job->penalty_amount, 2) }}</td>
            </tr>
            @endif
            @if($invoice->job->credit_amount > 0)
            <tr>
                <td>Performance Credit</td>
                <td style="text-align: right; color: green;">-R{{ number_format($invoice->job->credit_amount, 2) }}</td>
            </tr>
            @endif
        </tbody>
    </table>

    <table class="totals" style="width: 300px; float: right;">
        <tr>
            <td class="total-label">Subtotal</td>
            <td style="text-align: right;">R{{ number_format($invoice->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td class="total-label">VAT (15%)</td>
            <td style="text-align: right;">R{{ number_format($invoice->vat_amount, 2) }}</td>
        </tr>
        <tr>
            <td class="total-label grand-total">Total</td>
            <td class="grand-total" style="text-align: right;">R{{ number_format($invoice->total, 2) }}</td>
        </tr>
    </table>

    <div style="clear: both;"></div>

    <div class="footer">
        <p>Proselver TOP &mdash; Generated {{ now()->format('d M Y H:i') }}</p>
    </div>
</body>
</html>
