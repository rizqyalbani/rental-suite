<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trial Balance</title>
    <style>
        @page { size: A4; margin: 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; margin: 0; }
        h1 { font-size: 18px; margin: 0 0 4px 0; text-align: center; }
        .sub { font-size: 11px; color: #444; margin-bottom: 16px; text-align: center; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        th { background: #f5f5f5; font-size: 10px; text-transform: uppercase; color: #555; }
        td.num, th.num { text-align: right; }
        tfoot td { font-weight: bold; background: #f5f5f5; }
        .status { margin-top: 12px; font-size: 11px; font-weight: bold; }
        .status.ok { color: #047857; }
        .status.bad { color: #b91c1c; }
    </style>
</head>
<body>
    <h1>Trial Balance</h1>
    <div class="sub">{{ $storeLabel }}, per {{ \Illuminate\Support\Carbon::parse($asOf)->format('d M Y') }}</div>
    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Account</th>
                <th class="num">Debit</th>
                <th class="num">Credit</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row['account']->code }}</td>
                    <td>{{ $row['account']->name }}</td>
                    <td class="num">{{ $row['debit'] > 0 ? 'Rp '.number_format($row['debit'], 0, ',', '.') : '—' }}</td>
                    <td class="num">{{ $row['credit'] > 0 ? 'Rp '.number_format($row['credit'], 0, ',', '.') : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4">No activity up to this date yet.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Total</td>
                <td class="num">Rp {{ number_format($totalDebit, 0, ',', '.') }}</td>
                <td class="num">Rp {{ number_format($totalCredit, 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>
    <div class="status {{ abs($totalDebit - $totalCredit) < 0.01 ? 'ok' : 'bad' }}">
        {{ abs($totalDebit - $totalCredit) < 0.01 ? 'Balanced' : 'NOT BALANCED' }}
    </div>
</body>
</html>
