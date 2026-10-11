<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Manager Report Dashboard
        </h2>
        <p class="text-sm text-gray-500 mt-1">{{ $storeScopeLabel ?? 'Current Store' }}</p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <form method="GET" action="{{ route('reports.manager') }}" class="grid grid-cols-1 md:grid-cols-6 gap-4 items-end">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Period</label>
                            <select name="period" id="periodSelect" class="form-select w-full">
                                <option value="daily" {{ ($filters['period'] ?? 'daily') === 'daily' ? 'selected' : '' }}>Daily</option>
                                <option value="monthly" {{ ($filters['period'] ?? 'daily') === 'monthly' ? 'selected' : '' }}>Monthly</option>
                                <option value="yearly" {{ ($filters['period'] ?? 'daily') === 'yearly' ? 'selected' : '' }}>Yearly</option>
                                <option value="custom" {{ ($filters['period'] ?? 'daily') === 'custom' ? 'selected' : '' }}>Custom range</option>
                            </select>
                        </div>
                        <div id="dayWrap">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Day</label>
                            <input type="date" name="day" value="{{ $filters['day'] ?? now()->toDateString() }}" class="form-input w-full">
                        </div>
                        <div id="monthWrap">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Month</label>
                            <input type="month" name="month_year" value="{{ sprintf('%04d-%02d', (int) ($filters['year'] ?? now()->year), (int) ($filters['month'] ?? now()->month)) }}" class="form-input w-full">
                        </div>
                        <div id="yearWrap">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Year</label>
                            <input type="number" min="2000" max="2100" name="year" value="{{ $filters['year'] ?? now()->year }}" class="form-input w-full">
                        </div>
                        <div id="monthFromWrap">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Start month</label>
                            <input type="month" id="monthFromInput" name="month_from" value="{{ $filters['month_from'] ?? '' }}" class="form-input w-full" placeholder="YYYY-MM">
                        </div>
                        <div id="monthToWrap">
                            <label class="block text-sm font-medium text-gray-700 mb-1">End month</label>
                            <input type="month" id="monthToInput" name="month_to" value="{{ $filters['month_to'] ?? '' }}" class="form-input w-full" placeholder="YYYY-MM">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">From</label>
                            <input type="date" id="dateFromInput" name="date_from" value="{{ $filters['date_from'] }}" class="form-input w-full">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">To</label>
                            <input type="date" id="dateToInput" name="date_to" value="{{ $filters['date_to'] }}" class="form-input w-full">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select name="status" class="form-select w-full">
                                <option value="">All</option>
                                <option value="active" {{ $filters['status'] === 'active' ? 'selected' : '' }}>Active</option>
                                <option value="returned" {{ $filters['status'] === 'returned' ? 'selected' : '' }}>Returned</option>
                                <option value="cancelled" {{ $filters['status'] === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                            </select>
                        </div>
                        <input type="hidden" name="month" id="monthHidden" value="{{ $filters['month'] ?? now()->month }}">
                        <div class="flex gap-2 md:col-span-6">
                            @if(Auth::user()->isManagement() || Auth::user()->isSuperAdmin())
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700 mr-2">
                                    <input type="checkbox" name="all_stores" value="1" {{ request()->boolean('all_stores') ? 'checked' : '' }}>
                                    All stores
                                </label>
                            @endif
                            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer">Apply</button>
                            <a href="{{ route('reports.manager.download-pdf', request()->query()) }}" download class="px-4 py-2 bg-emerald-600 text-white rounded-md text-sm font-medium hover:bg-emerald-700">Download PDF</a>
                            <a href="{{ route('reports.manager') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md text-sm font-medium hover:bg-gray-200">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            @if(!$pricingEnabled)
                <div class="bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-md text-sm">
                    Pricing is currently OFF. Revenue values may appear as zero depending on item prices.
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-4">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg col-span-2">
                    <div class="p-5  flex-col justify-between h-full">
                        <div class="text-xs font-medium text-gray-500 uppercase">Transactions</div>
                        <div class="mt-2 text-2xl font-bold text-indigo-600">{{ $metrics['transaction_count'] }}</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg col-span-2">
                    <div class="p-5  flex-col justify-between h-full">
                        <div class="text-xs font-medium text-gray-500 uppercase">Gross Revenue</div>
                        <div class="mt-1 text-[11px] text-gray-400">Item list prices × duration, before discounts. Excludes overdue fines</div>
                        <div class="mt-2 text-2xl font-bold text-gray-800">Rp {{ number_format($metrics['gross_revenue'], 0, ',', '.') }}</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg col-span-2">
                    <div class="p-5  flex-col justify-between h-full">
                        <div class="text-xs font-medium text-gray-500 uppercase">Discount Total</div>
                        <div class="mt-2 text-2xl font-bold text-amber-600">Rp {{ number_format($metrics['discount_total'], 0, ',', '.') }}</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg col-span-2">
                    <div class="p-5 flex flex-col justify-between h-full">
                        <div class="text-xs font-medium text-gray-500 uppercase">Overdue &amp; Extra Fees</div>
                        <div class="mt-1 text-[11px] text-gray-400">Fines applied this period (any rent, on the day applied) + extra-day charges of this period's rents</div>
                        <div class="mt-2 text-2xl font-bold text-red-600">Rp {{ number_format($metrics['fees_total'] ?? 0, 0, ',', '.') }}</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg col-span-2">
                    <div class="p-5 flex flex-col justify-between h-full">
                        <div class="text-xs font-medium text-gray-500 uppercase">Net Revenue</div>
                        <div class="mt-1 text-[11px] text-gray-400">Gross − discounts + fees (excl. tax)</div>
                        <div class="mt-2 text-2xl font-bold text-green-600">Rp {{ number_format($metrics['net_revenue'], 0, ',', '.') }}</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg col-span-2">
                    <div class="p-5 flex flex-col justify-between h-full">
                        <div class="text-xs font-medium text-gray-500 uppercase">Avg / Transaction</div>
                        <div class="mt-1 text-[11px] text-gray-400">Net revenue ÷ transactions</div>
                        <div class="mt-2 text-2xl font-bold text-blue-600">Rp {{ number_format($metrics['average_transaction'], 0, ',', '.') }}</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg col-span-3">
                    <div class="p-5 flex flex-col justify-between h-full">
                        <div class="text-xs font-medium text-gray-500 uppercase">Cash Received</div>
                        <div class="mt-1 text-[11px] text-gray-400">Payments recorded this period (down payments, settlements, overdue fines) — money actually in hand</div>
                        <div class="mt-2 text-2xl font-bold text-emerald-700">Rp {{ number_format($metrics['cash_received'] ?? 0, 0, ',', '.') }}</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg col-span-3">
                    <div class="p-5 flex flex-col justify-between h-full">
                        <div class="text-xs font-medium text-gray-500 uppercase">Outstanding Balance</div>
                        <div class="mt-1 text-[11px] text-gray-400">Still owed on this period's rents (incl. tax) — booked but not yet collected</div>
                        <div class="mt-2 text-2xl font-bold text-orange-600">Rp {{ number_format($metrics['outstanding_total'] ?? 0, 0, ',', '.') }}</div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Per-Admin Summary</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Admin</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Transactions</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Gross</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Discount</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Net (excl. tax)</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Collected</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Avg</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($byAdmin as $row)
                                    <tr>
                                        <td class="px-3 py-3 whitespace-nowrap text-gray-900 font-medium">{{ $row['name'] }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap text-right">{{ $row['transaction_count'] }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap text-right">Rp {{ number_format($row['gross_revenue'], 0, ',', '.') }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap text-right">Rp {{ number_format($row['discount_total'], 0, ',', '.') }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap text-right font-semibold text-green-700">Rp {{ number_format($row['net_revenue'], 0, ',', '.') }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap text-right font-semibold text-emerald-700">Rp {{ number_format($row['collected'] ?? 0, 0, ',', '.') }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap text-right">Rp {{ number_format($row['average_transaction'], 0, ',', '.') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">No admin activity for selected filters.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Shift Summary --}}
            @if($shifts->count() > 0)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Shift Summary</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Admin</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Opened</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Closed</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($shifts as $shift)
                                    <tr>
                                        <td class="px-3 py-3 whitespace-nowrap text-gray-900 font-medium">{{ $shift->openedBy?->name ?? '-' }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap text-gray-700">{{ $shift->opened_at?->format('d M Y H:i') }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap text-gray-700">{{ $shift->closed_at?->format('d M Y H:i') ?? '-' }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap">
                                            @if($shift->closed_at)
                                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">Closed</span>
                                            @else
                                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-800">Open</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Transaction Report</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Items</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Admin</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Shift</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Net (excl. tax)</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($rentals as $rental)
                                    @php
                                        $fin = \App\Support\RentalFinancialSummary::summarize($rental, (float) ($taxPercent ?? 0));
                                        $net = (float) ($fin['net_excl_tax'] ?? ($fin['pre_tax_net'] ?? 0)) - max(0, (float) ($fin['overdue_fee_amount'] ?? 0));
                                        $shiftLabel = $rental->shiftSession
                                            ? ($rental->shiftSession->opened_at?->format('H:i') . ($rental->shiftSession->closed_at ? '–'.$rental->shiftSession->closed_at->format('H:i') : ' (open)'))
                                            : '-';
                                    @endphp
                                    <tr>
                                        <td class="px-3 py-3 whitespace-nowrap text-gray-700">{{ $rental->created_at?->format('d M Y') }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap text-gray-900 font-medium">{{ $rental->customer_name }}</td>
                                        <td class="px-3 py-3 text-gray-700">{{ $rental->outfits->map(fn($s) => $s->code . ($s->new_code ? ' | ' . $s->new_code : ''))->implode(', ') }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap">
                                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full
                                                {{ $rental->status === 'active' ? 'bg-green-100 text-green-800' : ($rental->status === 'returned' ? 'bg-gray-100 text-gray-800' : 'bg-red-100 text-red-800') }}">
                                                {{ ucfirst($rental->status) }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap text-gray-700">{{ $rental->handledBy?->name ?? '-' }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap text-gray-500 text-xs">{{ $shiftLabel }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap text-right text-gray-900 font-semibold">
                                            Rp {{ number_format($net, 0, ',', '.') }}
                                        </td>
                                        <td class="px-3 py-3 text-gray-600 text-xs break-words max-w-[10rem]">{{ $rental->notes ?: '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-4 py-8 text-center text-gray-500">No transactions found for selected filters.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Discount Transactions --}}
            @if($pricingEnabled)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-1">Discount Transactions</h3>
                    <p class="text-sm text-gray-500 mb-4">All rents with a discount applied in this period.</p>
                    @if($discountRentals->isEmpty())
                        <p class="text-gray-500 text-sm py-4 text-center">No discount transactions for the selected period.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Items</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Admin</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Shift</th>
                                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Discount</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Note</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($discountRentals as $dr)
                                        @php
                                            $dfin = \App\Support\RentalFinancialSummary::summarize($dr, (float) ($taxPercent ?? 0));
                                            $dTotal = (float) $dfin['item_discount_total'] + (float) $dfin['order_discount_amount'];
                                            $dShiftLabel = $dr->shiftSession
                                                ? ($dr->shiftSession->opened_at?->format('H:i') . ($dr->shiftSession->closed_at ? '–'.$dr->shiftSession->closed_at->format('H:i') : ' (open)'))
                                                : '-';
                                        @endphp
                                        <tr>
                                            <td class="px-3 py-3 whitespace-nowrap text-gray-700">{{ $dr->created_at?->format('d M Y') }}</td>
                                            <td class="px-3 py-3 whitespace-nowrap text-gray-900 font-medium">{{ $dr->customer_name }}</td>
                                            <td class="px-3 py-3 text-gray-700">{{ $dr->outfits->map(fn($s) => $s->code . ($s->new_code ? ' | '.$s->new_code : ''))->implode(', ') }}</td>
                                            <td class="px-3 py-3 whitespace-nowrap text-gray-700">{{ $dr->handledBy?->name ?? '-' }}</td>
                                            <td class="px-3 py-3 whitespace-nowrap text-gray-500 text-xs">{{ $dShiftLabel }}</td>
                                            <td class="px-3 py-3 whitespace-nowrap text-right text-amber-700 font-semibold">Rp {{ number_format($dTotal, 0, ',', '.') }}</td>
                                            <td class="px-3 py-3 text-gray-700 max-w-xs">
                                                @php
                                                    $noteLines = [];
                                                    if ($dr->discount_note) $noteLines[] = '<span class="font-medium">Order:</span> ' . e($dr->discount_note);
                                                    foreach ($dr->outfits as $ds) {
                                                        if ((float)($ds->pivot->discount ?? 0) > 0 && $ds->pivot->discount_note) {
                                                            $noteLines[] = '<span class="font-medium">' . e($ds->code) . ':</span> ' . e($ds->pivot->discount_note);
                                                        }
                                                    }
                                                @endphp
                                                @if($noteLines)
                                                    <div class="space-y-0.5 text-xs">{!! implode('<br>', $noteLines) !!}</div>
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
            @endif
        </div>
    </div>
    <script>
        (function() {
            var periodEl = document.getElementById('periodSelect');
            var dayWrap = document.getElementById('dayWrap');
            var monthWrap = document.getElementById('monthWrap');
            var yearWrap = document.getElementById('yearWrap');
            var fromEl = document.getElementById('dateFromInput');
            var toEl = document.getElementById('dateToInput');
            var monthFromEl = document.getElementById('monthFromInput');
            var monthToEl = document.getElementById('monthToInput');
            var monthYearEl = document.querySelector('input[name="month_year"]');
            var monthHidden = document.getElementById('monthHidden');
            if (!periodEl || !dayWrap || !monthWrap || !yearWrap || !fromEl || !toEl || !monthHidden || !monthFromEl || !monthToEl) return;

            function syncPeriodUi() {
                var p = periodEl.value;
                dayWrap.style.display = p === 'daily' ? '' : 'none';
                monthWrap.style.display = p === 'monthly' ? '' : 'none';
                yearWrap.style.display = p === 'yearly' ? '' : 'none';
                fromEl.closest('div').style.display = p === 'custom' ? '' : 'none';
                toEl.closest('div').style.display = p === 'custom' ? '' : 'none';
            }

            function parseMonthValue(v) {
                var m = String(v || '').match(/^(\d{4})-(\d{2})$/);
                if (!m) return null;
                return Number(m[1]) * 100 + Number(m[2]);
            }

            function syncMonthRangeValidation() {
                var a = parseMonthValue(monthFromEl.value);
                var b = parseMonthValue(monthToEl.value);
                if (a && b && a > b) {
                    monthToEl.value = monthFromEl.value;
                }
            }

            if (monthYearEl) {
                monthYearEl.addEventListener('change', function() {
                    var v = monthYearEl.value || '';
                    var parts = v.split('-');
                    if (parts.length === 2) {
                        document.querySelector('input[name="year"]').value = parts[0];
                        monthHidden.value = parts[1];
                    }
                });
            }
            monthFromEl.addEventListener('change', syncMonthRangeValidation);
            monthToEl.addEventListener('change', syncMonthRangeValidation);
            periodEl.addEventListener('change', syncPeriodUi);
            syncMonthRangeValidation();
            syncPeriodUi();
        })();
    </script>
</x-app-layout>
