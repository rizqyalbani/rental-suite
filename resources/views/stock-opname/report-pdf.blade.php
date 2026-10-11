<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Stock Opname Report #{{ $stockOpname->id }}</title>
    <style>
        @page { size: A4; margin: 6mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #111; margin: 0; }
        .brand { text-align: center; font-size: 11px; color: #666; margin-bottom: 12px; }
        h1 { font-size: 20px; margin: 0 0 8px 0; text-align: center; }
        .sub { font-size: 12px; color: #444; margin-bottom: 16px; text-align: center; }
        .row { margin: 4px 0; font-size: 12px; }
        .metrics { width: 100%; margin: 16px 0; border-collapse: collapse; }
        .metrics td { border: 1px solid #ccc; padding: 10px; width: 25%; text-align: center; }
        .metric-label { font-size: 11px; text-transform: uppercase; font-weight: bold; color: #555; }
        .metric-value { font-size: 22px; font-weight: bold; margin-top: 4px; }
        h2 { font-size: 13px; margin: 14px 0 6px 0; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
        table.detail { width: 100%; border-collapse: collapse; font-size: 12px; table-layout: fixed; margin: 0; }
        table.detail th, table.detail td { border: 1px solid #ccc; padding: 5px; text-align: left; vertical-align: top; word-break: break-word; }
        table.detail th { background: #f5f5f5; font-size: 11px; text-transform: uppercase; color: #555; }
        table.detail thead { display: table-header-group; }
        table.detail tr { page-break-inside: avoid; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10.5px; font-weight: bold; }
        .badge-missing { background: #fee2e2; color: #991b1b; }
        .badge-damaged { background: #fef3c7; color: #92400e; }
        .empty { color: #999; text-align: center; padding: 14px; }
        .footer-note { margin-top: 16px; font-size: 10.5px; color: #888; }
    </style>
</head>
<body>
    <div class="brand">{{ $stockOpname->store->name ?? 'Store' }} — Stock Opname Report</div>
    <h1>Stock Opname Report</h1>
    <div class="sub">Session #{{ $stockOpname->id }} — {{ $stockOpname->started_at->format('d M Y') }}</div>

    <div class="row"><strong>Started:</strong> {{ $stockOpname->started_at->format('d M Y H:i') }} by {{ $stockOpname->startedBy->name ?? 'Unknown' }}</div>
    <div class="row"><strong>Completed:</strong> {{ $stockOpname->completed_at->format('d M Y H:i') }} by {{ $stockOpname->completedBy->name ?? 'Unknown' }}</div>
    @if($stockOpname->notes)
        <div class="row"><strong>Session notes:</strong> {{ $stockOpname->notes }}</div>
    @endif

    <table class="metrics">
        <tr>
            <td>
                <div class="metric-label">Present</div>
                <div class="metric-value">{{ $okCount }}</div>
            </td>
            <td>
                <div class="metric-label">Missing</div>
                <div class="metric-value">{{ $missingCount }}</div>
            </td>
            <td>
                <div class="metric-label">Damaged</div>
                <div class="metric-value">{{ $damagedCount }}</div>
            </td>
            <td>
                <div class="metric-label">Not Checked</div>
                <div class="metric-value">{{ $uncheckedCount }}</div>
            </td>
        </tr>
    </table>

    <h2>Differences (Missing &amp; Damaged) — {{ $exceptions->count() }} items</h2>
    @if($exceptions->isEmpty())
        <p class="empty">No differences in this session: every item that was checked is marked Present.</p>
    @else
        <table class="detail">
            <thead>
                <tr>
                    <th style="width: 14%;">Code</th>
                    <th style="width: 26%;">Description</th>
                    <th style="width: 10%;">Status</th>
                    <th style="width: 30%;">Notes</th>
                    <th style="width: 20%;">Marked By</th>
                </tr>
            </thead>
            <tbody>
                @foreach($exceptions as $item)
                    <tr>
                        <td>{{ $item->outfit->code ?? '—' }}</td>
                        <td>{{ $item->outfit->description ?? '—' }}</td>
                        <td>
                            <span class="badge badge-{{ $item->status }}">{{ $item->status === 'missing' ? 'Missing' : 'Damaged' }}</span>
                        </td>
                        <td>{{ $item->note ?? '—' }}</td>
                        <td>{{ $item->markedBy->name ?? 'Unknown' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($uncheckedCount > 0)
        <p class="footer-note">
            {{ $uncheckedCount }} items were still "Not Checked" when this session was finished. They still count as
            not checked, not automatically as Present.
        </p>
    @endif

    <p class="footer-note">Generated automatically by the Rental Suite system on {{ now()->format('d M Y H:i') }}.</p>
</body>
</html>
