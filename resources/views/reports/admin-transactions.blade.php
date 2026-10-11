<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            My Transaction Report
        </h2>
        <p class="text-sm text-gray-500 mt-1">{{ $storeScopeLabel ?? 'Current Store' }}</p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 space-y-4">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">All shifts under your account</h3>
                            <p class="text-sm text-gray-500 mt-1">
                                Combines rentals created in your shifts and payments collected in your shifts for the selected date range.
                            </p>
                        </div>
                        <a href="{{ route('rentals.index') }}" class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-md text-sm font-medium hover:bg-gray-50">
                            Back to rents
                        </a>
                    </div>

                    <form method="GET" action="{{ route('reports.admin-transactions') }}" class="grid grid-cols-1 md:grid-cols-6 gap-4 items-end">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Period</label>
                            <select name="period" id="periodSelect" class="form-select w-full">
                                <option value="daily" {{ ($filters['period'] ?? 'monthly') === 'daily' ? 'selected' : '' }}>Daily</option>
                                <option value="monthly" {{ ($filters['period'] ?? 'monthly') === 'monthly' ? 'selected' : '' }}>Monthly</option>
                                <option value="yearly" {{ ($filters['period'] ?? 'monthly') === 'yearly' ? 'selected' : '' }}>Yearly</option>
                                <option value="custom" {{ ($filters['period'] ?? 'monthly') === 'custom' ? 'selected' : '' }}>Custom range</option>
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
                                <option value="active" {{ ($filters['status'] ?? null) === 'active' ? 'selected' : '' }}>Active</option>
                                <option value="returned" {{ ($filters['status'] ?? null) === 'returned' ? 'selected' : '' }}>Returned</option>
                                <option value="cancelled" {{ ($filters['status'] ?? null) === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                            </select>
                        </div>
                        <input type="hidden" name="month" id="monthHidden" value="{{ $filters['month'] ?? now()->month }}">
                        <div class="flex gap-2 md:col-span-6">
                            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700">Apply</button>
                            <a href="{{ route('reports.admin-transactions') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md text-sm font-medium hover:bg-gray-200">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            @if(!$pricingEnabled)
                <div class="bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-md text-sm">
                    Pricing is currently OFF. Remaining and total due values may appear as zero depending on item prices.
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-5 h-full">
                        <div class="text-xs font-medium text-gray-500 uppercase">Shift Sessions Covered</div>
                        <div class="mt-2 text-2xl font-bold text-indigo-600">{{ $metrics['shift_count'] ?? 0 }}</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-5 h-full">
                        <div class="text-xs font-medium text-gray-500 uppercase">Transactions Touched</div>
                        <div class="mt-2 text-2xl font-bold text-gray-800">{{ $metrics['transaction_count'] ?? 0 }}</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-5 h-full">
                        <div class="text-xs font-medium text-gray-500 uppercase">Paid</div>
                        <div class="mt-2 text-2xl font-bold text-emerald-600">Rp {{ number_format((float) ($metrics['total_paid'] ?? 0), 0, ',', '.') }}</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-5 h-full">
                        <div class="text-xs font-medium text-gray-500 uppercase">Remaining</div>
                        <div class="mt-2 text-2xl font-bold text-amber-600">Rp {{ number_format((float) ($metrics['total_outstanding'] ?? 0), 0, ',', '.') }}</div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Payments &amp; totals</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Paid includes money collected in your selected shifts, even when the rental originally started in an earlier shift.
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                            <ul class="space-y-2">
                                <li class="flex items-center justify-between gap-4 font-semibold text-emerald-900">
                                    <span>Paid</span>
                                    <span class="tabular-nums">Rp {{ number_format((float) ($metrics['total_paid'] ?? 0), 0, ',', '.') }}</span>
                                </li>
                                <li class="flex items-center justify-between gap-4 text-emerald-900">
                                    <span>Collected from previous-shift rentals</span>
                                    <span class="font-medium tabular-nums">Rp {{ number_format((float) ($metrics['paid_from_previous_shift_rentals'] ?? 0), 0, ',', '.') }}</span>
                                </li>
                                <li class="flex items-center justify-between gap-4 text-gray-700">
                                    <span>Collected from current-shift rentals</span>
                                    <span class="font-medium tabular-nums">Rp {{ number_format((float) ($metrics['paid_from_current_shift_rentals'] ?? 0), 0, ',', '.') }}</span>
                                </li>
                            </ul>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                            <ul class="space-y-2">
                                <li class="flex items-center justify-between gap-4">
                                    <span>Total due on listed rentals</span>
                                    <span class="font-medium tabular-nums">Rp {{ number_format((float) ($metrics['total_due'] ?? 0), 0, ',', '.') }}</span>
                                </li>
                                <li class="flex items-center justify-between gap-4 font-semibold text-amber-900">
                                    <span>Remaining</span>
                                    <span class="tabular-nums">Rp {{ number_format((float) ($metrics['total_outstanding'] ?? 0), 0, ',', '.') }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Shift list</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Shows each shift under your account. Open a shift to see the transaction detail on a separate page.
                    </p>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Shift</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Opened</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">User</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Transactions</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Fund</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($shiftReports as $shiftReport)
                                    @php
                                        $shift = $shiftReport['shift'];
                                    @endphp
                                    <tr class="align-top">
                                        <td class="px-3 py-3 whitespace-nowrap text-gray-900 font-medium">
                                            {{ $shift->periodLabel() }} shift
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap text-gray-700">
                                            {{ $shift->opened_at?->format('d M Y H:i') ?? '—' }}
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap text-gray-700">
                                            {{ $shift->openedBy?->name ?? '—' }}
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap text-right text-gray-900 font-medium">{{ $shiftReport['transaction_count'] ?? 0 }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap text-right font-semibold text-emerald-700">Rp {{ number_format((float) ($shiftReport['total_paid'] ?? 0), 0, ',', '.') }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap text-right">
                                            <a
                                                href="{{ route('reports.admin-transactions.shift', array_merge(['shift' => $shift->id], request()->query())) }}"
                                                class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-xs font-medium text-white hover:bg-indigo-700"
                                            >
                                                View transactions
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-8 text-center text-gray-500">No shifts found for your account in the selected period.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
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
                var period = periodEl.value;
                dayWrap.style.display = period === 'daily' ? '' : 'none';
                monthWrap.style.display = period === 'monthly' ? '' : 'none';
                yearWrap.style.display = period === 'yearly' ? '' : 'none';
                fromEl.closest('div').style.display = period === 'custom' ? '' : 'none';
                toEl.closest('div').style.display = period === 'custom' ? '' : 'none';
                monthFromEl.closest('div').style.display = period === 'custom' ? '' : 'none';
                monthToEl.closest('div').style.display = period === 'custom' ? '' : 'none';
            }

            if (monthYearEl) {
                monthYearEl.addEventListener('change', function() {
                    var value = monthYearEl.value || '';
                    if (/^\d{4}-\d{2}$/.test(value)) {
                        monthHidden.value = parseInt(value.slice(5, 7), 10);
                    }
                });
            }

            periodEl.addEventListener('change', syncPeriodUi);
            syncPeriodUi();
        })();
    </script>
</x-app-layout>
