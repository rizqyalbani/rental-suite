<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt #{{ $rental->id }} — {{ $companyName }}</title>
    @if(! empty($ogUrl ?? null))
        <link rel="canonical" href="{{ $ogUrl }}">
        <meta property="og:url" content="{{ $ogUrl }}">
        <meta property="og:title" content="Receipt #{{ $rental->id }} — {{ $companyName }}">
        <meta property="og:description" content="{{ $rental->customer_name }} · Signed rent receipt">
        <meta property="og:type" content="website">
        <meta name="twitter:card" content="summary_large_image">
        @if(! empty($ogImageUrl ?? null))
            <meta property="og:image" content="{{ $ogImageUrl }}">
            <meta property="og:image:alt" content="Receipt preview image">
            <meta name="twitter:image" content="{{ $ogImageUrl }}">
        @endif
    @endif
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
    <div class="secure-wrap">
        <div class="secure-bar">
            <p>Signed receipt link · Rent #{{ $rental->id }}</p>
            <div class="secure-actions">
                @auth
                    <a href="#" id="secureReceiptBackBtn" class="btn-secondary" aria-label="Back" title="Back">
                        <span aria-hidden="true" style="display:inline-flex;align-items:center;gap:8px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display:block;">
                                <path d="M15 18L9 12L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Back
                        </span>
                    </a>
                @endauth
                @if(! empty($regenUrl ?? null))
                    <a href="{{ $regenUrl }}" class="btn-secondary" id="regenBtn">Regenerate</a>
                @endif
                @if($pdfUrl ?? null)
                    <a href="{{ $pdfUrl }}" class="btn-secondary" download>Download PDF</a>
                @endif
                @if($imageUrl ?? null)
                    <a href="{{ $imageUrl }}" target="_blank" rel="noopener" class="btn-secondary">Open image</a>
                    <a href="{{ $imageUrl }}" class="btn-primary" download>Download image</a>
                @endif
            </div>
        </div>

        @include('invoices.partials.note-invoice-inner')
    </div>
    <script>
        (function () {
            var regen = document.getElementById('regenBtn');
            if (!regen) return;
            regen.addEventListener('click', function () {
                regen.textContent = 'Generating…';
                regen.style.opacity = '0.6';
                regen.style.pointerEvents = 'none';
                regen.style.cursor = 'default';
            });
        })();
    </script>
    @auth
        <script>
            (function () {
                var btn = document.getElementById('secureReceiptBackBtn');
                if (!btn) return;
                var hasHistory = false;
                try {
                    hasHistory = (window.history && typeof window.history.length === 'number' && window.history.length > 1) || !!document.referrer;
                } catch (e) {}
                if (!hasHistory) {
                    btn.style.display = 'none';
                    return;
                }
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    try { window.history.back(); } catch (err) {}
                });
            })();
        </script>
    @endauth
</body>
</html>
