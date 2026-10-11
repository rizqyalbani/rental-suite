<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manager Transaction Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 8px 0; text-align: center; }
        .sub { font-size: 10px; color: #444; margin-bottom: 14px; text-align: center; }
        .row { margin: 3px 0; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; margin-top: 8px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; vertical-align: top; }
        th { background: #f5f5f5; font-size: 9px; text-transform: uppercase; color: #555; }
        .right { text-align: right; }
        .metrics td { width: 20%; }
    </style>
</head>
<body>
    <h1>Manager Transaction Report</h1>
    <div class="sub">{{ $storeScopeLabel ?? 'Current Store' }} | Period: {{ ucfirst($filters['period'] ?? 'monthly') }} | Date basis: rental start date</div>
    <div class="row"><strong>Range:</strong> {{ $filters['date_from'] ?? '-' }} to {{ $filters['date_to'] ?? '-' }}</div>
    <div class="row"><strong>Status:</strong> {{ $filters['status'] ?: 'All' }}</div>
    @php
        $showTax = (float) ($taxPercent ?? 0) > 0.009;
    @endphp
    @if($showTax)
        <div class="row"><strong>Tax:</strong> {{ number_format((float) ($taxPercent ?? 0), 2, '.', '') }}%</div>
    @endif

    <table class="metrics">
        <tr>
            <td><strong>Transactions</strong><br>{{ $metrics['transaction_count'] }}</td>
            <td><strong>Gross</strong><br>Rp {{ number_format($metrics['gross_revenue'], 0, ',', '.') }}</td>
            <td><strong>Discount</strong><br>Rp {{ number_format($metrics['discount_total'], 0, ',', '.') }}</td>
            <td><strong>Overdue &amp; extra fees</strong><br>Rp {{ number_format($metrics['fees_total'] ?? 0, 0, ',', '.') }}</td>
            <td><strong>{{ $showTax ? 'Net (excl. tax)' : 'Net' }}</strong><br>Rp {{ number_format($metrics['net_revenue'], 0, ',', '.') }}</td>
            <td><strong>Avg</strong><br>Rp {{ number_format($metrics['average_transaction'], 0, ',', '.') }}</td>
            <td><strong>Cash received</strong><br>Rp {{ number_format($metrics['cash_received'] ?? 0, 0, ',', '.') }}</td>
            <td><strong>Outstanding</strong><br>Rp {{ number_format($metrics['outstanding_total'] ?? 0, 0, ',', '.') }}</td>
        </tr>
    </table>
    <p style="font-size: 10px; color: #6b7280; margin: 4px 0 0;">Gross = item list prices × duration, before discounts. Net = gross - discounts + fees. Fees = overdue fines applied in this period (counted on the day applied, whichever day the rent was booked) + extra-day charges. Cash received = payments recorded in the period. Outstanding = still owed on this period's rents (incl. tax).</p>

    <h3>Per-Admin Summary</h3>
    <table>
        <thead>
            <tr>
                <th>Admin</th>
                <th class="right">Transactions</th>
                <th class="right">Gross</th>
                <th class="right">Discount</th>
                <th class="right">{{ $showTax ? 'Net (excl. tax)' : 'Net' }}</th>
                <th class="right">Collected</th>
                <th class="right">Avg</th>
            </tr>
        </thead>
        <tbody>
            @forelse($byAdmin as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="right">{{ $row['transaction_count'] }}</td>
                    <td class="right">Rp {{ number_format($row['gross_revenue'], 0, ',', '.') }}</td>
                    <td class="right">Rp {{ number_format($row['discount_total'], 0, ',', '.') }}</td>
                    <td class="right">Rp {{ number_format($row['net_revenue'], 0, ',', '.') }}</td>
                    <td class="right">Rp {{ number_format($row['collected'] ?? 0, 0, ',', '.') }}</td>
                    <td class="right">Rp {{ number_format($row['average_transaction'], 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="7">No admin activity for selected filters.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3>Transaction Detail</h3>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Customer</th>
                <th>Items</th>
                <th>Status</th>
                <th>Handled By</th>
                <th class="right">{{ $showTax ? 'Net (excl. tax)' : 'Net' }}</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rentals as $rental)
                @php
                    $fin = \App\Support\RentalFinancialSummary::summarize($rental, (float) ($taxPercent ?? 0));
                    $net = (float) ($fin['net_excl_tax'] ?? ($fin['pre_tax_net'] ?? 0)) - max(0, (float) ($fin['overdue_fee_amount'] ?? 0));
                @endphp
                <tr>
                    <td>{{ $rental->created_at?->format('d M Y') }}</td>
                    <td>{{ $rental->customer_name }}</td>
                    <td>{{ $rental->outfits->map(fn($s) => $s->code . ($s->new_code ? ' | ' . $s->new_code : ''))->implode(', ') }}</td>
                    <td>{{ ucfirst($rental->status) }}</td>
                    <td>{{ $rental->handledBy?->name ?? '-' }}</td>
                    <td class="right">Rp {{ number_format($net, 0, ',', '.') }}</td>
                    <td>{{ $rental->notes ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7">No transactions found for selected filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
