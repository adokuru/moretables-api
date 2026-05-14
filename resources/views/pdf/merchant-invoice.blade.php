<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.5; }
        .header { border-bottom: 2px solid #111827; margin-bottom: 24px; padding-bottom: 16px; }
        .muted { color: #6b7280; }
        .grid { display: table; width: 100%; }
        .col { display: table-cell; width: 50%; vertical-align: top; }
        .right { text-align: right; }
        .badge { border-radius: 999px; display: inline-block; padding: 4px 10px; text-transform: uppercase; }
        .paid { background: #dcfce7; color: #166534; }
        .pending { background: #fef9c3; color: #854d0e; }
        table { border-collapse: collapse; margin-top: 24px; width: 100%; }
        th { background: #f3f4f6; text-align: left; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 10px; }
        .total { font-size: 16px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header grid">
        <div class="col">
            <h1>MoreTables</h1>
            <p class="muted">Merchant billing invoice</p>
        </div>
        <div class="col right">
            <h2>{{ $invoice->invoice_number }}</h2>
            <span class="badge {{ $invoice->status?->value === 'paid' ? 'paid' : 'pending' }}">
                {{ $invoice->status?->value ?? 'pending' }}
            </span>
        </div>
    </div>

    <div class="grid">
        <div class="col">
            <strong>Billed to</strong>
            <p>
                {{ $restaurant->name }}<br>
                {{ $restaurant->organization?->name }}<br>
                {{ $restaurant->email ?? $restaurant->organization?->billing_email ?? $restaurant->organization?->primary_contact_email }}
            </p>
        </div>
        <div class="col right">
            <strong>Invoice details</strong>
            <p>
                Issued: {{ $invoice->created_at?->format('M j, Y') }}<br>
                Due: {{ $invoice->due_at?->format('M j, Y') ?? 'N/A' }}<br>
                Paid: {{ $invoice->paid_at?->format('M j, Y') ?? 'N/A' }}
            </p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th>Period</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $invoice->plan?->name ?? 'Merchant subscription' }}</td>
                <td>
                    {{ $invoice->billing_period_start?->format('M j, Y') ?? 'N/A' }}
                    -
                    {{ $invoice->billing_period_end?->format('M j, Y') ?? 'N/A' }}
                </td>
                <td class="right">{{ $invoice->currency }} {{ number_format($invoice->amount / 100, 2) }}</td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2" class="total right">Total</td>
                <td class="total right">{{ $invoice->currency }} {{ number_format($invoice->amount / 100, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    @if ($invoice->payments->isNotEmpty())
        <h3>Payment</h3>
        @foreach ($invoice->payments as $payment)
            <p>
                Reference: {{ $payment->reference }}<br>
                Channel: {{ $payment->channel ?? 'N/A' }}<br>
                Card: {{ $payment->paymentMethod?->brand ?? $payment->paymentMethod?->card_type ?? 'Card' }}
                ending {{ $payment->paymentMethod?->last4 ?? 'N/A' }}
            </p>
        @endforeach
    @endif
</body>
</html>
