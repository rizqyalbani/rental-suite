<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shift Closing Report #{{ $shift->id }}</title>
    <style>
        @page { size: A4; margin: 6mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #111; margin: 0; }
        h1 { font-size: 20px; margin: 0 0 8px 0; text-align: center; }
        .sub { font-size: 12px; color: #444; margin-bottom: 16px; text-align: center; }
        .row { margin: 4px 0; font-size: 12px; }
        .metrics { width: 100%; margin: 16px 0; border-collapse: collapse; }
        .metrics td { border: 1px solid #ccc; padding: 10px; width: 50%; vertical-align: top; }
        .metrics td.highlight-indigo { background-color: #f5f3ff; border-color: #ddd6fe; }
        .metrics td.highlight-emerald { background-color: #ecfdf5; border-color: #a7f3d0; }
        .metric-label { font-size: 11px; text-transform: uppercase; font-weight: bold; }
        .metric-label.indigo { color: #6366f1; }
        .metric-label.emerald { color: #10b981; }
        .metric-value { font-size: 24px; font-weight: bold; margin-top: 4px; }
        .metric-value.indigo { color: #4338ca; }
        .metric-value.emerald { color: #047857; }
        h2 { font-size: 13px; margin: 10px 0 6px 0; border-bottom: 1px solid #ccc; padding-bottom: 4px; page-break-after: avoid; }
        table.detail { width: 100%; border-collapse: collapse; font-size: 12px; table-layout: fixed; margin: 0; }
        table.detail th, table.detail td { border: 1px solid #ccc; padding: 5px; text-align: left; vertical-align: top; word-break: break-word; }
        table.detail th { background: #f5f5f5; font-size: 11px; text-transform: uppercase; color: #555; }
        table.detail thead { display: table-header-group; }
        table.detail tr { page-break-inside: avoid; }
        table.detail + h2 { margin-top: 6px; }
        .brand { text-align: center; font-size: 11px; color: #666; margin-bottom: 12px; }
        .tx-list { margin-top: 0; }
        .tx-item { border: 1px solid #ddd; padding: 8px; margin: 0 0 8px 0; page-break-inside: avoid; }
        .tx-title { font-size: 13px; font-weight: bold; margin-bottom: 6px; }
        .tx-kv { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .tx-kv td { padding: 2px 4px; vertical-align: top; font-size: 12px; }
        .tx-kv .lbl { width: 13%; color: #333; font-weight: bold; }
        .tx-kv .val { width: 20%; }
        .tx-kv .wide { width: auto; }
        .debug-wrap { background: #f3f4f6; padding: 18px; }
        .debug-page { background: #fff; width: 210mm; min-height: 297mm; margin: 0 auto; box-shadow: 0 8px 30px rgba(0,0,0,.12); padding: 6mm; box-sizing: border-box; }
        .debug-bar { width: 210mm; margin: 0 auto 12px auto; font-size: 12px; color: #111; }
        .debug-bar a { color: #1d4ed8; text-decoration: underline; }
    </style>
</head>
<body>
    @php
        $debugHtml = (bool) ($debugHtml ?? false);
    @endphp
    @if($debugHtml)
        <div class="debug-wrap">
            <div class="debug-bar">
                <strong>PDF layout debug:</strong>
                <a href="{{ route('shifts.report.download-pdf', array_merge(['shift' => $shift->id], $filters ?? [])) }}" target="_blank" rel="noopener noreferrer">Download PDF</a>
            </div>
            <div class="debug-page">
    @endif
                <div class="brand">{{ $storeScopeLabel ?? 'Current Store' }} — Shift Closing Report</div>
                <h1>Shift Closing Report</h1>
                <div class="sub">Shift #{{ $shift->id }} — {{ $shift->periodLabel() }}</div>
                <div class="row"><strong>Date basis:</strong> transaction date (created at)</div>
                <div class="row"><strong>Filter period:</strong> {{ ucfirst($filters['period'] ?? 'custom') }}</div>
                <div class="row"><strong>Filter range:</strong> {{ $filters['date_from'] ?? '-' }} to {{ $filters['date_to'] ?? '-' }}</div>
                <div class="row"><strong>Status:</strong> {{ $filters['status'] ?: 'All' }}</div>
                <div class="row"><strong>Tax:</strong> {{ number_format((float) ($taxPercent ?? 0), 2, '.', '') }}%</div>
                <div class="row"><strong>Period:</strong> {{ $shift->periodLabel() }}</div>
                <div class="row"><strong>Opened by:</strong> {{ $shift->openedBy?->name ?? '-' }} — {{ $shift->opened_at?->format('d M Y H:i') }}</div>
                <div class="row"><strong>Closed by:</strong> {{ $shift->closedBy?->name ?? '-' }} — {{ $shift->closed_at?->format('d M Y H:i') }}</div>
                @if(! empty($reportScopedToYourHandledRentals))
                    <div class="row"><em>This export includes only rents handled by the viewing staff account (not the full shift).</em></div>
                @endif
                @if($shift->opening_note)
                    <div class="row"><strong>Opening note:</strong> {{ $shift->opening_note }}</div>
                @endif
                @if($shift->closing_note)
                    <div class="row"><strong>Closing note:</strong> {{ $shift->closing_note }}</div>
                @endif

                <table class="metrics">
                    <tr>
                        <td class="highlight-indigo">
                            <div class="metric-label indigo">Total transactions</div>
                            <div class="metric-value indigo">{{ $totalTransactions }}</div>
                        </td>
                        <td class="highlight-emerald">
                            <div class="metric-label emerald">Total paid</div>
                            <div class="metric-value emerald">Rp {{ number_format((float) ($totalReceived ?? 0), 0, ',', '.') }}</div>
                        </td>
                    </tr>
                </table>

    @php
        $ft = $financialTotals ?? [];
        $showTax = (float) ($ft['tax'] ?? 0) > 0.009;
    @endphp
    <h2>Payments &amp; discounts</h2>
    <div class="row">Item total: Rp {{ number_format((float) ($ft['gross'] ?? 0), 0, ',', '.') }}</div>
    <div class="row">Item discounts{{ $showTax ? ' (pre-tax)' : '' }}: -Rp {{ number_format((float) ($ft['item_discounts'] ?? 0), 0, ',', '.') }}</div>
    <div class="row">Order discounts{{ $showTax ? ' (pre-tax)' : '' }}: -Rp {{ number_format((float) ($ft['order_discounts'] ?? 0), 0, ',', '.') }}</div>
    @if($showTax)
        <div class="row">Subtotal (pre-tax): Rp {{ number_format((float) ($ft['pre_tax'] ?? 0), 0, ',', '.') }}</div>
        <div class="row">Tax: Rp {{ number_format((float) ($ft['tax'] ?? 0), 0, ',', '.') }}</div>
    @endif
    <div class="row"><strong>{{ $showTax ? 'Total due (incl. tax):' : 'Total due:' }}</strong> Rp {{ number_format((float) ($ft['net_incl_tax'] ?? $totalRevenue), 0, ',', '.') }}</div>
    <div class="row"><strong>Paid:</strong> Rp {{ number_format((float) ($totalReceived ?? 0), 0, ',', '.') }}</div>
    <div class="row"><strong>Remaining:</strong> Rp {{ number_format((float) ($totalOutstanding ?? 0), 0, ',', '.') }}</div>

    @if($showAdminSummary ?? false)
    <h2>Per-admin summary</h2>
    <table class="detail">
        <thead>
            <tr>
                <th>Admin</th>
                <th>Transactions</th>
                <th>Paid</th>
                <th>Average</th>
            </tr>
        </thead>
        <tbody>
            @forelse($byAdmin as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['transaction_count'] }}</td>
                    <td>Rp {{ number_format((float) ($row['received'] ?? 0), 0, ',', '.') }}</td>
                    <td>Rp {{ number_format($row['average_transaction'], 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center;color:#666;">No admin activity in selected filter.</td></tr>
            @endforelse
        </tbody>
    </table>
    @endif

    <h2>Transaction detail</h2>
    <p style="font-size: 10px; color: #6b7280; margin: 0 0 8px;">Rows marked "Payment only" are a final/overdue payment collected this shift for a rent created on another day — only the amount received this shift counts toward Paid; the rent's full value was booked on its creation day. Unmarked rows are new rents created during this shift. A negative amount marked "Correction" is money handed back after the bill went down; it reduces Received.</p>
    <div class="tx-list">
        @forelse($rentalReports as $i => $report)
            @php
                $rental = $report['rental'];
                $fin = $report['financial'];
                $paid = (float) ($report['paid'] ?? 0);
                $outstanding = (float) ($report['outstanding'] ?? 0);
            @endphp
            <div class="tx-item">
                <div class="tx-title">
                    #{{ (int) $i + 1 }} — {{ $rental->customer_name }}@if(! empty($report['from_other_shift'])) <span style="font-size:9px;font-weight:700;color:#3730a3;background:#e0e7ff;padding:1px 4px;border-radius:3px;">Payment only</span>@endif @if($paid < 0) <span style="font-size:9px;font-weight:700;color:#3730a3;background:#e0e7ff;padding:1px 4px;border-radius:3px;">Correction</span>@endif
                </div>
                <table class="tx-kv">
                    <tr>
                        <td class="lbl">Phone</td>
                        <td class="val">{{ $rental->phone }}</td>
                        <td class="lbl">Status</td>
                        <td class="val">{{ ucfirst($rental->status) }}</td>
                        <td class="lbl">Pay</td>
                        <td class="val">{{ $rental->payment_method ?: '—' }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">Created</td>
                        <td class="val">{{ $rental->created_at?->format('d M Y H:i') ?? '—' }}</td>
                        <td class="lbl">Dates</td>
                        <td class="val">{{ $rental->start_date?->format('d M Y') }} → {{ $rental->end_date?->format('d M Y') }}</td>
                        <td class="lbl">Pickup</td>
                        <td class="val">{{ $rental->picked_up_at ? $rental->picked_up_at->format('d M H:i') : '—' }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">Handled</td>
                        <td class="val" colspan="5">{{ $rental->handledBy?->name ?? '—' }}</td>
                    </tr>
                    @php
                        $dpBefore = ! empty($report['from_other_shift']) && $rental->down_payment_amount !== null && (float) $rental->down_payment_amount > 0.004
                            ? (float) $rental->down_payment_amount
                            : null;
                    @endphp
                    <tr>
                        <td class="lbl">Paid</td>
                        <td class="val">Rp {{ number_format($paid, 0, ',', '.') }}</td>
                        <td class="lbl">Remaining</td>
                        <td class="val">Rp {{ number_format($outstanding, 0, ',', '.') }}</td>
                        <td class="lbl">Net</td>
                        <td class="val">Rp {{ number_format($fin['net_incl_tax'], 0, ',', '.') }}</td>
                    </tr>
                    @if($dpBefore !== null)
                    <tr>
                        <td class="lbl">DP paid before</td>
                        <td class="val" colspan="5">Rp {{ number_format($dpBefore, 0, ',', '.') }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td class="lbl">Items</td>
                        <td class="wide" colspan="5">
                            @if($fin['items_display'] !== '—')
                                <ul style="margin: 0; padding: 0; list-style: none;">
                                    @foreach(explode('; ', $fin['items_display']) as $item)
                                        <li>• {{ $item }}</li>
                                    @endforeach
                                </ul>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="lbl">Discounts</td>
                        <td class="wide" colspan="5">{{ $fin['discounts_display'] }}</td>
                    </tr>
                    @if($rental->notes)
                    <tr>
                        <td class="lbl">Notes</td>
                        <td class="wide" colspan="5">{{ $rental->notes }}</td>
                    </tr>
                    @endif
                </table>
            </div>
        @empty
            <div class="row" style="text-align:center;color:#666;">No rents in selected filter.</div>
        @endforelse
    </div>
    @if($debugHtml)
            </div>
        </div>
    @endif
</body>
</html>
