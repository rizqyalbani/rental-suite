<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profit &amp; Loss</title>
    <style>
        @page { size: A4; margin: 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; margin: 0; }
        h1 { font-size: 18px; margin: 0 0 4px 0; text-align: center; }
        .sub { font-size: 11px; color: #444; margin-bottom: 16px; text-align: center; }
        h2 { font-size: 12px; margin: 14px 0 6px 0; border-bottom: 1px solid #ccc; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        td { padding: 3px 4px; }
        td.num { text-align: right; }
        tr.total td { font-weight: bold; border-top: 1px solid #999; }
        .net { margin-top: 16px; font-size: 14px; font-weight: bold; text-align: right; }
        .net.pos { color: #047857; }
        .net.neg { color: #b91c1c; }
    </style>
</head>
<body>
    <h1>Profit &amp; Loss</h1>
    <div class="sub">
        {{ $storeLabel }}, {{ \Illuminate\Support\Carbon::parse($dateFrom)->format('d M Y') }} to {{ \Illuminate\Support\Carbon::parse($dateTo)->format('d M Y') }}
    </div>

    <h2>Revenue</h2>
    <table>
        @foreach($revenueRows as $row)
            <tr><td>{{ $row['account']->name }}</td><td class="num">Rp {{ number_format($row['amount'], 0, ',', '.') }}</td></tr>
        @endforeach
        <tr class="total"><td>Total Revenue</td><td class="num">Rp {{ number_format($revenueTotal, 0, ',', '.') }}</td></tr>
    </table>

    <h2>Expenses</h2>
    <table>
        @foreach($expenseRows as $row)
            <tr><td>{{ $row['account']->name }}</td><td class="num">Rp {{ number_format($row['amount'], 0, ',', '.') }}</td></tr>
        @endforeach
        <tr class="total"><td>Total Expenses</td><td class="num">Rp {{ number_format($expenseTotal, 0, ',', '.') }}</td></tr>
    </table>

    <div class="net {{ $netIncome >= 0 ? 'pos' : 'neg' }}">
        Net Profit (Loss): Rp {{ number_format($netIncome, 0, ',', '.') }}
    </div>
</body>
</html>
