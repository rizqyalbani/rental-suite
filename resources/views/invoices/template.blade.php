<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt #{{ $rental->id }}</title>
    @include('invoices.partials.note-invoice-styles')
<style>
        body {
            margin: 0;
            padding: 0;
            background: #f1f5f9;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            color: #0f172a;
        }
        .secure-wrap {
            max-width: 920px;
            margin: 0 auto;
            padding: 20px 16px 40px;
        }
        .secure-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
            padding: 12px 14px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
        }
        .secure-bar p {
            margin: 0;
            font-size: 13px;
            color: #64748b;
        }
        .secure-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .secure-actions a {
            display: inline-block;
            text-decoration: none;
            padding: 9px 14px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
        }
        .btn-primary {
            background: #166534;
            color: #ffffff;
        }
        .btn-primary:hover {
            background: #14532d;
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
        }
        .btn-secondary:hover {
            background: #cbd5e1;
        }
    </style>
</head>
<body>
<div id="invoice-print-target">
    @include('invoices.partials.note-invoice-inner')
</div>
</body>
</html>
