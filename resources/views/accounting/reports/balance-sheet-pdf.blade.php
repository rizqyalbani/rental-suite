<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Balance Sheet</title>
    <style>
        @page { size: A4; margin: 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; margin: 0; }
        h1 { font-size: 18px; margin: 0 0 4px 0; text-align: center; }
        .sub { font-size: 11px; color: #444; margin-bottom: 16px; text-align: center; }
        h2 { font-size: 12px; margin: 14px 0 6px 0; border-bottom: 1px solid #ccc; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 6px; }
        td { padding: 3px 4px; }
        td.num { text-align: right; }
        tr.total td { font-weight: bold; border-top: 1px solid #999; }
        .status { margin-top: 12px; font-size: 11px; font-weight: bold; }
        .status.ok { color: #047857; }
        .status.bad { color: #b91c1c; }
        .cols { width: 100%; }
        .cols td { vertical-align: top; width: 50%; padding: 0 6px; }
    </style>
</head>
<body>
    <h1>Balance Sheet</h1>
    <div class="sub">{{ $storeLabel }}, per {{ \Illuminate\Support\Carbon::parse($asOf)->format('d M Y') }}</div>

    <table class="cols">
        <tr>
            <td>
                <h2>Assets</h2>
                <table>
                    @foreach($assetRows as $row)
                        <tr><td>{{ $row['account']->name }}</td><td class="num">Rp {{ number_format($row['balance'], 0, ',', '.') }}</td></tr>
                    @endforeach
                    <tr class="total"><td>Total Assets</td><td class="num">Rp {{ number_format($assetTotal, 0, ',', '.') }}</td></tr>
                </table>
            </td>
            <td>
                <h2>Liabilities</h2>
                <table>
                    @foreach($liabilityRows as $row)
                        <tr><td>{{ $row['account']->name }}</td><td class="num">Rp {{ number_format($row['balance'], 0, ',', '.') }}</td></tr>
                    @endforeach
                    <tr class="total"><td>Total Liabilities</td><td class="num">Rp {{ number_format($liabilityTotal, 0, ',', '.') }}</td></tr>
                </table>

                <h2>Equity</h2>
                <table>
                    @foreach($equityRows as $row)
                        <tr><td>{{ $row['account']->name }}</td><td class="num">Rp {{ number_format($row['balance'], 0, ',', '.') }}</td></tr>
                    @endforeach
                    <tr><td><em>Current Year Profit (Loss) (calculated automatically)</em></td><td class="num"><em>Rp {{ number_format($currentEarnings, 0, ',', '.') }}</em></td></tr>
                    <tr class="total"><td>Total Equity</td><td class="num">Rp {{ number_format($equityTotal, 0, ',', '.') }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @php $liabPlusEquity = $liabilityTotal + $equityTotal; @endphp
    <div class="status {{ abs($assetTotal - $liabPlusEquity) < 0.01 ? 'ok' : 'bad' }}">
        Total Assets Rp {{ number_format($assetTotal, 0, ',', '.') }} = Total Liabilities + Equity Rp {{ number_format($liabPlusEquity, 0, ',', '.') }}:
        {{ abs($assetTotal - $liabPlusEquity) < 0.01 ? 'Balanced' : 'NOT BALANCED' }}
    </div>
</body>
</html>
