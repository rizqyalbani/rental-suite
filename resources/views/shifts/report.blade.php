<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Shift Closing Report</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>
        .custom-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-900">
    <div class="max-w-4xl mx-auto py-8 px-4 report-page">
        @if(session('success'))
            <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded text-sm">
                {{ session('success') }}
            </div>
        @endif

        <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 report-card">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Shift Closing Report</h1>
                    <p class="text-sm text-gray-500 mt-1">{{ $storeScopeLabel ?? 'Current Store' }}</p>
                    <p class="text-sm font-medium text-gray-800 mt-1">{{ $shift->periodLabel() }} shift</p>
                    <p class="text-sm text-gray-600 mt-1">
                        Opened by: {{ $shift->openedBy?->name ?? '-' }} at {{ $shift->opened_at?->format('d M Y H:i') }}
                    </p>
                    <p class="text-sm text-gray-600">
                        Closed by: {{ $shift->closedBy?->name ?? '-' }} at {{ $shift->closed_at?->format('d M Y H:i') }}
                    </p>
                    @if(! empty($reportScopedToYourHandledRentals))
                        <p class="text-xs text-amber-900/90 mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1.5">
                            You are viewing <strong>only rents you handled</strong>. Management and Super Admin see the full shift.
                        </p>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('rentals.index') }}" class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-md text-sm font-medium hover:bg-gray-50 cursor-pointer flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 256 256"><path d="M216,120H59.31l34.35-34.34a8,8,0,0,0-11.32-11.32l-48,48a8,8,0,0,0,0,11.32l48,48a8,8,0,0,0,11.32-11.32L59.31,136H216a8,8,0,0,0,0-16Z"></path></svg>
                        Back
                    </a>
                    @php $isTutorialShift = $shift instanceof \App\Models\TutorialShiftSession; @endphp
                    @unless($isTutorialShift)
                    <a id="shiftReportDownloadPdfLink" href="{{ $downloadPdfUrl }}" download class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 256 256"><path d="M224,144v64a8,8,0,0,1-8,8H40a8,8,0,0,1-8-8V144a8,8,0,0,1,16,0v56H208V144a8,8,0,0,1,16,0Zm-101.66,5.66a8,8,0,0,0,11.32,0l40-40a8,8,0,0,0-11.32-11.32L136,124.69V40a8,8,0,0,0-16,0v84.69L93.66,98.34a8,8,0,0,0-11.32,11.32Z"></path></svg>
                        Download PDF
                    </a>
                    @endunless
                    @if (! $isTutorialShift && \App\Support\DebugTools::enabled() && $shift->closed_at !== null)
                        <form method="POST" action="{{ route('shifts.reopen', $shift) }}" onsubmit="return confirm('Reopen this shift for debugging? Its closed totals will be cleared until it is closed again.');">
                            @csrf
                            <button type="submit" class="px-4 py-2 bg-amber-100 border border-amber-400 text-amber-900 rounded-md text-sm font-medium hover:bg-amber-200 cursor-pointer">
                                Reopen (debug)
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="mt-6 border border-gray-200 rounded-lg p-5 bg-white shadow-sm print-hidden">
                <form method="GET" action="{{ route('shifts.report', $shift) }}" class="flex flex-wrap gap-4 items-end">
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Period</label>
                        <select name="period" id="periodSelect" class="form-select custom-select w-full border border-gray-300 rounded-md pl-3 py-2">
                            <option value="daily" {{ ($filters['period'] ?? 'custom') === 'daily' ? 'selected' : '' }}>Daily</option>
                            <option value="monthly" {{ ($filters['period'] ?? 'custom') === 'monthly' ? 'selected' : '' }}>Monthly</option>
                            <option value="yearly" {{ ($filters['period'] ?? 'custom') === 'yearly' ? 'selected' : '' }}>Yearly</option>
                            <option value="custom" {{ ($filters['period'] ?? 'custom') === 'custom' ? 'selected' : '' }}>Custom range</option>
                        </select>
                    </div>
                    <div id="dayWrap" class="flex-1 min-w-[150px]">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Day</label>
                        <input type="date" name="day" value="{{ $filters['day'] ?? now()->toDateString() }}" class="form-input w-full border border-gray-300 rounded-md px-3 py-2">
                    </div>
                    <div id="monthWrap" class="flex-1 min-w-[150px]">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Month</label>
                        <input type="month" name="month_year" value="{{ sprintf('%04d-%02d', (int) ($filters['year'] ?? now()->year), (int) ($filters['month'] ?? now()->month)) }}" class="form-input w-full border border-gray-300 rounded-md px-3 py-2">
                    </div>
                    <div id="yearWrap" class="flex-1 min-w-[120px]">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Year</label>
                        <input type="number" name="year" min="2000" max="2100" value="{{ $filters['year'] ?? now()->year }}" class="form-input w-full border border-gray-300 rounded-md px-3 py-2">
                    </div>
                    <div id="fromWrap" class="flex-1 min-w-[150px]">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">From</label>
                        <input id="dateFromInput" type="date" name="date_from" value="{{ $filters['date_from'] ?? $shift->opened_at?->toDateString() }}" class="form-input w-full border border-gray-300 rounded-md px-3 py-2">
                    </div>
                    <div id="toWrap" class="flex-1 min-w-[150px]">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">To</label>
                        <input id="dateToInput" type="date" name="date_to" value="{{ $filters['date_to'] ?? now()->toDateString() }}" class="form-input w-full border border-gray-300 rounded-md px-3 py-2">
                    </div>
                    <div class="flex-1 min-w-[180px]">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Status</label>
                        <select name="status" class="form-select custom-select w-full border border-gray-300 rounded-md pl-3 py-2">
                            <option value="">All Status</option>
                            <option value="active" {{ ($filters['status'] ?? null) === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="returned" {{ ($filters['status'] ?? null) === 'returned' ? 'selected' : '' }}>Returned</option>
                            <option value="cancelled" {{ ($filters['status'] ?? null) === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                        </select>
                    </div>
                    <input type="hidden" name="month" id="monthHidden" value="{{ $filters['month'] ?? now()->month }}">
                    <div class="w-full lg:w-auto flex items-center gap-2 pt-2">
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer transition-all active:scale-95">Apply</button>
                        <a href="{{ route('shifts.report', $shift) }}" class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-md text-sm font-medium hover:bg-gray-50 transition-all active:scale-95 text-center">Reset</a>
                    </div>
                </form>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-6">
                <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4">
                    <div class="text-xs uppercase text-indigo-500 font-semibold">Total Transactions</div>
                    <div class="text-3xl font-bold text-indigo-700 mt-1">{{ $totalTransactions }}</div>
                </div>
                <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-4">
                    <div class="text-xs uppercase text-emerald-500 font-semibold">Total Paid</div>
                    <div class="text-3xl font-bold text-emerald-700 mt-1">Rp {{ number_format((float) ($totalReceived ?? 0), 0, ',', '.') }}</div>
                </div>
            </div>

            @php
                $ft = $financialTotals ?? [];
                $showTax = (float) ($ft['tax'] ?? 0) > 0.009 && (float) ($taxPercent ?? 0) > 0.009;
            @endphp
            <div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-800">
                <h3 class="text-xs font-semibold uppercase text-gray-500 mb-2">Payments &amp; discounts</h3>
                <ul class="space-y-1 text-xs sm:text-sm">
                    <li class="flex flex-wrap justify-between gap-2"><span>Item total</span><span class="font-medium tabular-nums">Rp {{ number_format((float) ($ft['gross'] ?? 0), 0, ',', '.') }}</span></li>
                    <li class="flex flex-wrap justify-between gap-2 text-amber-900"><span>Item-level discounts{{ $showTax ? ' (pre-tax)' : '' }}</span><span class="font-medium tabular-nums">−Rp {{ number_format((float) ($ft['item_discounts'] ?? 0), 0, ',', '.') }}</span></li>
                    <li class="flex flex-wrap justify-between gap-2 text-amber-900"><span>Order-level discounts{{ $showTax ? ' (pre-tax)' : '' }}</span><span class="font-medium tabular-nums">−Rp {{ number_format((float) ($ft['order_discounts'] ?? 0), 0, ',', '.') }}</span></li>
                    @if($showTax)
                    <li class="flex flex-wrap justify-between gap-2 border-t border-gray-100 pt-2 mt-2"><span>Subtotal (pre-tax)</span><span class="font-medium tabular-nums">Rp {{ number_format((float) ($ft['pre_tax'] ?? 0), 0, ',', '.') }}</span></li>
                    <li class="flex flex-wrap justify-between gap-2"><span>Tax ({{ number_format((float) ($taxPercent ?? 0), 2, ',', '.') }}%)</span><span class="font-medium tabular-nums">Rp {{ number_format((float) ($ft['tax'] ?? 0), 0, ',', '.') }}</span></li>
                    @endif
                    <li class="flex flex-wrap justify-between gap-2 font-semibold text-green-800"><span>{{ $showTax ? 'Total due (incl. tax)' : 'Total due' }}</span><span class="tabular-nums">Rp {{ number_format((float) ($ft['net_incl_tax'] ?? $totalRevenue), 0, ',', '.') }}</span></li>
                    <li class="flex flex-wrap justify-between gap-2 font-semibold text-emerald-800"><span>Paid</span><span class="tabular-nums">Rp {{ number_format((float) ($totalReceived ?? 0), 0, ',', '.') }}</span></li>
                    <li class="flex flex-wrap justify-between gap-2 font-semibold text-amber-900"><span>Remaining</span><span class="tabular-nums">Rp {{ number_format((float) ($totalOutstanding ?? 0), 0, ',', '.') }}</span></li>
                </ul>
            </div>

            @if($showAdminSummary ?? false)
            <div class="mt-6">
                <h2 class="text-lg font-semibold mb-3">Per-Admin Summary</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Admin</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Transactions</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Paid</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Average</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($byAdmin as $row)
                                <tr>
                                    <td class="px-3 py-2">{{ $row['name'] }}</td>
                                    <td class="px-3 py-2 text-right">{{ $row['transaction_count'] }}</td>
                                    <td class="px-3 py-2 text-right font-semibold text-green-700">Rp {{ number_format((float) ($row['received'] ?? 0), 0, ',', '.') }}</td>
                                    <td class="px-3 py-2 text-right">Rp {{ number_format($row['average_transaction'], 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">No admin activity in selected filter.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            <div class="mt-6">
                <h2 class="text-lg font-semibold mb-3">Transaction detail</h2>
                <p class="text-xs text-gray-500 mb-2">Items show list price and any line discount; order-level discount is separate. Net includes tax.</p>
                <p class="text-xs text-gray-500 mb-2">Rows marked <span class="inline-block rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-800 align-middle">Payment only</span> are a final/overdue payment collected this shift for a rent created on another day — only the amount received this shift counts toward Paid; the rent's full value was booked on its creation day. Unmarked rows are new rents created during this shift. A negative amount marked <span class="ml-1 inline-block rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-800 align-middle">Correction</span> is money handed back after the bill went down; it reduces Received.</p>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm shift-report-transaction-table">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase min-w-[12rem]">Items (list &amp; line disc.)</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Dates</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Pickup</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Pay</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Paid</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Remaining</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Handled</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase min-w-[8rem]">Discounts</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Net (incl. tax)</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase min-w-[8rem]">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($rentalReports as $report)
                                @php
                                    $rental = $report['rental'];
                                    $fin = $report['financial'];
                                    $paid = (float) ($report['paid'] ?? 0);
                                    $outstanding = (float) ($report['outstanding'] ?? 0);
                                @endphp
                                @php
                                    $dpBefore = ! empty($report['from_other_shift']) && $rental->down_payment_amount !== null && (float) $rental->down_payment_amount > 0.004
                                        ? (float) $rental->down_payment_amount
                                        : null;
                                @endphp
                                <tr class="align-top">
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        {{ $rental->customer_name }}@if(! empty($report['from_other_shift'])) <span class="ml-1 inline-block rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-800 align-middle">Payment only</span>@endif @if($paid < 0) <span class="ml-1 inline-block rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-800 align-middle">Correction</span>@endif
                                        <div class="text-[10px] font-normal text-gray-400">Created: {{ $rental->created_at?->format('d M Y H:i') ?? '—' }}</div>
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $rental->phone }}</td>
                                    <td class="px-3 py-2 text-xs text-gray-800 break-words max-w-xs">{{ $fin['items_display'] }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-600">{{ $rental->start_date?->format('d M Y') }} → {{ $rental->end_date?->format('d M Y') }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs">{{ $rental->picked_up_at ? $rental->picked_up_at->format('d M H:i') : '—' }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs">{{ $rental->payment_method ?: '—' }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-right text-xs tabular-nums">
                                        Rp {{ number_format($paid, 0, ',', '.') }}
                                        @if($dpBefore !== null)
                                            <div class="text-[10px] font-normal text-gray-500 whitespace-nowrap">DP paid before: Rp {{ number_format($dpBefore, 0, ',', '.') }}</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-right text-xs tabular-nums">Rp {{ number_format($outstanding, 0, ',', '.') }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ ucfirst($rental->status) }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $rental->handledBy?->name ?? '—' }}</td>
                                    <td class="px-3 py-2 text-xs text-gray-800 break-words max-w-[10rem]">{{ $fin['discounts_display'] }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-right font-medium">Rp {{ number_format($fin['net_incl_tax'], 0, ',', '.') }}</td>
                                    <td class="px-3 py-2 text-xs text-gray-600 break-words max-w-[10rem]">{{ $rental->notes ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="13" class="px-3 py-6 text-center text-gray-500">No rents in selected filter.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var periodEl = document.getElementById('periodSelect');
            var dayWrap = document.getElementById('dayWrap');
            var monthWrap = document.getElementById('monthWrap');
            var yearWrap = document.getElementById('yearWrap');
            var fromWrap = document.getElementById('fromWrap');
            var toWrap = document.getElementById('toWrap');
            var monthYearEl = document.querySelector('input[name="month_year"]');
            var monthHidden = document.getElementById('monthHidden');
            if (!periodEl || !dayWrap || !monthWrap || !yearWrap || !fromWrap || !toWrap || !monthHidden) return;

            function syncPeriodUi() {
                var p = periodEl.value;
                dayWrap.style.display = p === 'daily' ? 'block' : 'none';
                monthWrap.style.display = p === 'monthly' ? 'block' : 'none';
                yearWrap.style.display = p === 'yearly' ? 'block' : 'none';
                fromWrap.style.display = p === 'custom' ? 'block' : 'none';
                toWrap.style.display = p === 'custom' ? 'block' : 'none';
            }
            if (monthYearEl) {
                monthYearEl.addEventListener('change', function () {
                    var v = monthYearEl.value || '';
                    var parts = v.split('-');
                    if (parts.length === 2) {
                        document.querySelector('input[name="year"]').value = parts[0];
                        monthHidden.value = parts[1];
                    }
                });
            }
            periodEl.addEventListener('change', syncPeriodUi);
            syncPeriodUi();
        })();

        (function () {
            var link = document.getElementById('shiftReportDownloadPdfLink');
            if (!link) return;

            link.addEventListener('click', function (e) {
                e.preventDefault();

                fetch(link.href, { credentials: 'same-origin' })
                    .then(function (res) {
                        if (!res.ok) {
                            window.location.href = link.href;
                            return null;
                        }

                        var disposition = res.headers.get('Content-Disposition') || '';
                        var fileName = '';
                        var match = disposition.match(/filename\\*?=(?:UTF-8''|\"?)([^\";]+)/i);
                        if (match && match[1]) {
                            fileName = decodeURIComponent(match[1].replace(/\"/g, '').trim());
                        }

                        return res.blob().then(function (blob) {
                            return { blob: blob, fileName: fileName };
                        });
                    })
                    .then(function (payload) {
                        if (!payload) return;

                        var url = URL.createObjectURL(payload.blob);
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = payload.fileName || '';
                        document.body.appendChild(a);
                        a.click();
                        a.remove();
                        setTimeout(function () {
                            URL.revokeObjectURL(url);
                        }, 250);
                    })
                    .catch(function () {
                        window.location.href = link.href;
                    });
            });
        })();
    </script>
</body>
</html>
