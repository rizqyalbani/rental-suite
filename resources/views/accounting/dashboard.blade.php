<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Accounting
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            @include('partials.accounting-trial-banner')

            <div class="flex items-center justify-end mb-6">
                <form id="accountingDashboardFilterForm" method="GET" class="flex flex-wrap items-center gap-2 text-sm">
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-input text-sm">
                    <span class="text-gray-400">to</span>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-input text-sm">
                    <button type="submit" class="px-3 py-2 bg-gray-900 text-white rounded-md text-sm hover:bg-black cursor-pointer">Filter</button>
                </form>
            </div>

            <div id="accountingDashboardKpis" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-10">
                <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Cash Collected from Rentals</p>
                    <p class="mt-2 text-2xl font-bold text-emerald-700">Rp {{ number_format($rentalRevenue, 0, ',', '.') }}</p>
                    <p id="kasMasukSplit" class="mt-1 text-xs text-gray-500">Cash Rp {{ number_format($rentalCash, 0, ',', '.') }} · Bank Rp {{ number_format($rentalBank, 0, ',', '.') }}</p>
                    <p class="mt-1 text-xs text-gray-400">All rental money received in this period (deposits, settlements, including rentals from previous months).</p>
                    @if($freeRefundCount > 0)
                        <p id="kasMasukFreeRefund" class="mt-2 text-xs text-amber-800">− refunded because of FREE ({{ $freeRefundCount }} {{ \Illuminate\Support\Str::plural('rental', $freeRefundCount) }}) Rp {{ number_format($freeRefundTotal, 0, ',', '.') }} · net Rp {{ number_format($rentalRevenue - $freeRefundTotal, 0, ',', '.') }}</p>
                    @endif
                </div>
                <div id="pendapatanDiakuiUpdate" class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Recognised Rental Revenue</p>
                    <p class="mt-2 text-2xl font-bold text-emerald-700">Rp {{ number_format($pendapatanDiakuiUpdate, 0, ',', '.') }}</p>
                    <div class="mt-2 space-y-0.5 text-xs text-gray-500">
                        <p>Cash collected from rentals Rp {{ number_format($rentalRevenue, 0, ',', '.') }}</p>
                        <p>settlement of earlier rentals ({{ $pelunasanLamaJumlah }} {{ \Illuminate\Support\Str::plural('rental', $pelunasanLamaJumlah) }}) Rp {{ number_format($pelunasanLama, 0, ',', '.') }} <span class="block text-gray-400">remaining balance of earlier months' rentals, paid in this period</span></p>
                        <p class="font-semibold text-gray-700">cash collected − settlement of earlier rentals</p>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Cash In</p>
                    <p class="mt-2 text-2xl font-bold text-emerald-700">Rp {{ number_format($cashIn, 0, ',', '.') }}</p>
                </div>
                <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Cash Out</p>
                    <p class="mt-2 text-2xl font-bold text-red-700">Rp {{ number_format($cashOut, 0, ',', '.') }}</p>
                </div>
                <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Total Expenses</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">Rp {{ number_format($totalExpenses, 0, ',', '.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4 items-start">
                <div id="accountingDashboardOrders" class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Order Summary</p>
                    <p class="mt-1 text-xs text-gray-500">Rentals created {{ \Illuminate\Support\Carbon::parse($dateFrom)->format('d M') }} – {{ \Illuminate\Support\Carbon::parse($dateTo)->format('d M Y') }}. The full list is in the Rents menu.</p>
                    <div class="mt-3 space-y-0.5 text-sm text-gray-600">
                        <p>number of orders <span class="font-semibold text-gray-800">{{ $orderCounts->sum() }}</span>
                            <span class="text-xs text-gray-400">(active {{ $orderCounts['active'] ?? 0 }} · returned {{ $orderCounts['returned'] ?? 0 }} · cancelled {{ $orderCounts['cancelled'] ?? 0 }})</span></p>
                        @php $orderLain = $pendapatanDiakuiUpdate - $periodeDibayar; @endphp
                        <div class="grid grid-cols-[1fr_auto] gap-x-6 gap-y-0.5 tabular-nums max-w-md">
                            <span>rent value</span><span class="text-right">Rp {{ number_format($periodeTagihan, 0, ',', '.') }}</span>
                            <span>− receivable (unpaid)</span><span class="text-right">Rp {{ number_format($piutangPeriode, 0, ',', '.') }}</span>
                            <span class="font-medium text-gray-800">= already paid <span class="block text-xs font-normal text-gray-400">deposits + settlements from rentals created in this period</span></span><span class="text-right font-medium text-gray-800">Rp {{ number_format($periodeDibayar, 0, ',', '.') }}</span>
                            @if(abs($orderLain) > 0.004)
                                <span>{{ $orderLain > 0 ? '+' : '−' }} late fee / extra charge &amp; other</span><span class="text-right">Rp {{ number_format(abs($orderLain), 0, ',', '.') }}</span>
                            @endif
                            <span class="font-medium text-gray-800">= Recognised Rental Revenue</span><span class="text-right font-medium text-gray-800">Rp {{ number_format($pendapatanDiakuiUpdate, 0, ',', '.') }}</span>
                            <span>+ settlement of earlier rentals</span><span class="text-right">Rp {{ number_format($pelunasanLama, 0, ',', '.') }}</span>
                            <span class="font-medium text-gray-800">= Cash Collected from Rentals</span><span class="text-right font-medium text-gray-800">Rp {{ number_format($rentalRevenue, 0, ',', '.') }}</span>
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-gray-400">Rent value = the rental price of rentals created in this period, including unpaid amounts.</p>
                </div>

                <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Outstanding Rental Receivables</p>
                    <p class="mt-2 text-2xl font-bold {{ $piutangPeriode > 0 ? 'text-amber-700' : 'text-gray-900' }}">Rp {{ number_format($piutangPeriode, 0, ',', '.') }}</p>
                    <p class="mt-1 text-xs text-gray-400">Remaining balance of rentals created {{ \Illuminate\Support\Carbon::parse($dateFrom)->format('d M') }} – {{ \Illuminate\Support\Carbon::parse($dateTo)->format('d M Y') }}. Payments are counted up to {{ \Illuminate\Support\Carbon::parse($dateTo)->format('d M Y') }}.</p>
                    @if($piutangJumlahRental > 0)
                        <div id="piutangBreakdown" class="mt-3 space-y-0.5 text-sm text-gray-600">
                            <p>{{ $periodeRental }} {{ \Illuminate\Support\Str::plural('rental', $periodeRental) }} created in this period: rent value Rp {{ number_format($periodeTagihan, 0, ',', '.') }}</p>
                            <p>already paid Rp {{ number_format($periodeDibayar, 0, ',', '.') }} <span class="text-xs text-gray-400">(deposits + settlements from rentals created in this period, already included in Cash Collected)</span></p>
                            @if($periodeLebihBayar > 0.004)
                                <p>overpaid Rp {{ number_format($periodeLebihBayar, 0, ',', '.') }} <span class="text-xs text-gray-400">(does not reduce the receivable)</span></p>
                            @endif
                            <p class="font-semibold text-gray-800">receivable (rent value − already paid) = Rp {{ number_format($piutangPeriode, 0, ',', '.') }} <span class="font-normal text-xs text-gray-500">&middot; {{ $piutangJumlahRental }} {{ \Illuminate\Support\Str::plural('rental', $piutangJumlahRental) }} not fully paid</span></p>
                        </div>
                    @endif
                </div>
            </div>

            @include('rentals.partials.payment-assign-panel', ['paDateFrom' => $dateFrom, 'paDateTo' => $dateTo])

            <div id="perluDicekList" class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5 mb-4">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Rentals to Review ({{ $perluDicek->count() }})</p>
                    <button type="button" x-data x-on:click="$dispatch('open-modal', 'perlu-dicek-help')" class="text-xs font-medium text-indigo-600 hover:text-indigo-800 underline">How to read</button>
                </div>
                <p class="mt-1 text-xs text-gray-500">Picked automatically: rentals in this period whose figures may differ from the store's manual records. Not necessarily wrong, they just need to be matched.</p>
                <x-modal name="perlu-dicek-help" maxWidth="lg">
                    <div class="p-6 text-sm text-gray-700">
                        <h2 class="text-base font-semibold text-gray-900">How to read "Rentals to Review"</h2>
                        <p class="mt-1 text-gray-500">This list is picked automatically. Not necessarily wrong, it just needs to be matched against the store's records.</p>
                        <dl class="mt-4 space-y-3">
                            <div><dt class="font-medium text-gray-900">Deleted although money was already received</dt><dd>The rental was deleted but has a deposit/settlement. Check whether the money was returned or is still in the store.</dd></div>
                            <div><dt class="font-medium text-gray-900">Re-entered from a deleted rental</dt><dd>The same rental was created again. Check that the amount on the new one matches the old one.</dd></div>
                            <div><dt class="font-medium text-gray-900">Overpayment</dt><dd>The money received is more than the bill. Check whether change was given or an item was added.</dd></div>
                            <div><dt class="font-medium text-gray-900">Returned / picked up but still short</dt><dd>The items are already out but not fully paid. Check whether the settlement payment was forgotten.</dd></div>
                            <div><dt class="font-medium text-gray-900">Entered late</dt><dd>The payment date and the entry date are far apart. Check that the date matches the store's records.</dd></div>
                            <div><dt class="font-medium text-gray-900">Not in any shift</dt><dd>The money was recorded without a shift, so it is not in the closing report. Check who received it.</dd></div>
                            <div><dt class="font-medium text-gray-900">Deposit far from the booking date</dt><dd>The deposit may have been paid at booking but recorded later. It can move to another month.</dd></div>
                            <div><dt class="font-medium text-gray-900">Changed after the period</dt><dd>Items or price were changed after this period, so the rental value can differ from the old records.</dd></div>
                            <div><dt class="font-medium text-gray-900">Different payment history / test data</dt><dd>The "paid" figure on the rental does not match its payment history, or its bill is Rp 0.</dd></div>
                        </dl>
                        <div class="mt-5 text-right">
                            <button type="button" x-on:click="$dispatch('close')" class="text-sm font-medium text-gray-600 hover:text-gray-900">Close</button>
                        </div>
                    </div>
                </x-modal>
                @if($perluDicek->isNotEmpty())
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-gray-400">
                                    <th class="py-1 pr-3 font-semibold">No</th>
                                    <th class="py-1 pr-3 font-semibold">Name</th>
                                    <th class="py-1 pr-3 font-semibold">Invoice</th>
                                    <th class="py-1 pr-3 font-semibold">Created</th>
                                    <th class="py-1 pr-3 font-semibold">To check</th>
                                    <th class="py-1"></th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-700">
                                @foreach($perluDicek as $i => $row)
                                    <tr class="border-t border-gray-100 align-top">
                                        <td class="py-1 pr-3">{{ $i + 1 }}</td>
                                        <td class="py-1 pr-3 whitespace-nowrap">{{ $row['rental']->customer_name }}@if($row['rental']->trashed()) <span class="text-xs text-red-600">(deleted)</span>@endif</td>
                                        <td class="py-1 pr-3 whitespace-nowrap">
                                            @php($r = $row['rental'])
                                            <a href="{{ $r->trashed() ? route('rentals.index', ['deleted' => 1, 'search' => $r->customer_name]) : route('rentals.index', ['edit' => $r->id]) }}" target="_blank" class="font-medium text-indigo-600 hover:text-indigo-800 underline" title="{{ $r->trashed() ? 'Open in Deleted Rents' : 'Open edit popup' }}">#{{ $r->id }}</a>
                                        </td>
                                        <td class="py-1 pr-3 whitespace-nowrap">{{ $row['rental']->created_at?->format('d M') }}</td>
                                        <td class="py-1">
                                            <ul class="list-disc pl-4 space-y-0.5">
                                                @foreach($row['flags'] as $flag)
                                                    <li>{{ $flag }}</li>
                                                @endforeach
                                            </ul>
                                        </td>
                                        <td class="py-1 pl-3 text-right">
                                            <form method="POST" action="{{ route('accounting.perlu-dicek.mark', $r->id) }}">
                                                @csrf
                                                <input type="hidden" name="date_from" value="{{ $dateFrom }}">
                                                <input type="hidden" name="date_to" value="{{ $dateTo }}">
                                                <button type="submit" class="whitespace-nowrap rounded border border-gray-300 px-2 py-0.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Mark as checked</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="mt-3 text-sm text-gray-500">No rentals to review in this period.</p>
                @endif
                @if($sudahDicek->isNotEmpty())
                    <details class="mt-4">
                        <summary class="cursor-pointer text-xs font-medium text-gray-500">Checked ({{ $sudahDicek->count() }})</summary>
                        <ul class="mt-2 divide-y divide-gray-100 text-sm text-gray-600">
                            @foreach($sudahDicek as $row)
                                <li class="flex items-center justify-between gap-3 py-1">
                                    <span>{{ $row['rental']->customer_name }} #{{ $row['rental']->id }} <span class="text-xs text-gray-400">· checked by {{ $row['mark']['by'] }}, {{ \Illuminate\Support\Carbon::parse($row['mark']['at'])->format('d M H:i') }}</span></span>
                                    <form method="POST" action="{{ route('accounting.perlu-dicek.unmark', $row['rental']->id) }}">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="date_from" value="{{ $dateFrom }}">
                                        <input type="hidden" name="date_to" value="{{ $dateTo }}">
                                        <button type="submit" class="text-xs text-indigo-600 hover:text-indigo-800 underline">Undo</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>

            <div id="pelunasanLamaList" class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5 mb-10">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Settlement of Earlier Rentals</p>
                <p class="mt-1 text-xs text-gray-500">Money received {{ \Illuminate\Support\Carbon::parse($dateFrom)->format('d M') }} – {{ \Illuminate\Support\Carbon::parse($dateTo)->format('d M Y') }} for rentals created before {{ \Illuminate\Support\Carbon::parse($dateFrom)->format('d M Y') }}. Subtracted from Cash Collected to get Recognised Rental Revenue.</p>
                @if($pelunasanLamaList->isNotEmpty())
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-gray-400">
                                    <th class="py-1 pr-3 font-semibold">No</th>
                                    <th class="py-1 pr-3 font-semibold">Name</th>
                                    <th class="py-1 pr-3 font-semibold">Invoice</th>
                                    <th class="py-1 pr-3 font-semibold">Created</th>
                                    <th class="py-1 pr-3 font-semibold">Paid</th>
                                    <th class="py-1 text-right font-semibold">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-700">
                                @foreach($pelunasanLamaList as $i => $row)
                                    <tr class="border-t border-gray-100">
                                        <td class="py-1 pr-3">{{ $i + 1 }}</td>
                                        <td class="py-1 pr-3">{{ $row['nama'] }}</td>
                                        <td class="py-1 pr-3">#{{ $row['nota'] }}</td>
                                        <td class="py-1 pr-3">{{ $row['dibooking']?->format('d M') }}</td>
                                        <td class="py-1 pr-3">{{ $row['paid_at']?->format('d M') }}</td>
                                        <td class="py-1 text-right tabular-nums">Rp {{ number_format($row['jumlah'], 0, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                                <tr class="border-t-2 border-gray-300 font-semibold text-gray-900">
                                    <td class="py-1 pr-3" colspan="5">Total settlement of earlier rentals ({{ $pelunasanLamaList->count() }})</td>
                                    <td class="py-1 text-right tabular-nums">Rp {{ number_format($pelunasanLama, 0, ',', '.') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="mt-3 text-sm text-gray-500">No settlements of earlier rentals in this period.</p>
                @endif
            </div>

            <div class="mb-10">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">Reports</h3>
                <div id="accountingDashboardReports" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <a href="{{ route('accounting.reports.trial-balance') }}" class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5 hover:border-indigo-300 hover:shadow transition">
                        <p class="font-semibold text-gray-900">Trial Balance</p>
                        <p class="text-xs text-gray-500 mt-1">Check that debits and credits balance as of a date.</p>
                    </a>
                    <a href="{{ route('accounting.reports.balance-sheet') }}" class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5 hover:border-indigo-300 hover:shadow transition">
                        <p class="font-semibold text-gray-900">Balance Sheet</p>
                        <p class="text-xs text-gray-500 mt-1">Assets vs Liabilities + Equity as of a date.</p>
                    </a>
                    <a href="{{ route('accounting.reports.profit-loss') }}" class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5 hover:border-indigo-300 hover:shadow transition">
                        <p class="font-semibold text-gray-900">Profit &amp; Loss</p>
                        <p class="text-xs text-gray-500 mt-1">Revenue minus expenses over a date range.</p>
                    </a>
                    <a href="{{ route('accounting.reports.general-ledger') }}" class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5 hover:border-indigo-300 hover:shadow transition">
                        <p class="font-semibold text-gray-900">General Ledger</p>
                        <p class="text-xs text-gray-500 mt-1">Go through journals one by one.</p>
                    </a>
                    <a href="{{ route('accounting.reports.fixed-assets') }}" class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5 hover:border-indigo-300 hover:shadow transition">
                        <p class="font-semibold text-gray-900">Fixed Assets</p>
                        <p class="text-xs text-gray-500 mt-1">Equipment &amp; Supplies: purchase price vs how much has been depreciated.</p>
                    </a>
                </div>
            </div>

            <div id="accountingDashboardShiftLookup" class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-10">
                <div class="p-6">
                    <div class="flex flex-wrap items-center gap-3 mb-1">
                        <h3 class="text-lg font-medium text-gray-900">Shift Lookup</h3>
                        <label class="sr-only" for="shiftLookupDate">Date</label>
                        <input type="date" id="shiftLookupDate" class="form-input h-9 py-0 w-full sm:w-[10rem]">
                        <button type="button" id="shiftLookupCheckBtn"
                            class="h-9 px-3 rounded-lg border border-indigo-300 bg-indigo-50 text-sm font-medium text-indigo-800 hover:bg-indigo-100 cursor-pointer">
                            Check shifts
                        </button>
                    </div>
                    <p class="text-xs text-gray-400 mb-3">See who worked, transactions, and revenue for any day, then click through to the full closing report.</p>
                    <div id="shiftLookupResults" class="hidden text-sm text-gray-800"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function wireAccountingShiftLookup() {
            function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
            function run() {
                var dateEl = document.getElementById('shiftLookupDate');
                var out = document.getElementById('shiftLookupResults');
                if (!dateEl || !out) return;
                var date = String(dateEl.value || '');
                if (!date) { out.classList.remove('hidden'); out.innerHTML = '<p class="text-gray-500">Pick a date first.</p>'; return; }
                out.classList.remove('hidden');
                out.innerHTML = '<p class="text-gray-500">Loading…</p>';
                fetch('{{ route('accounting.shifts-by-date') }}?date=' + encodeURIComponent(date), { cache: 'no-store', headers: { 'Accept': 'application/json' } })
                    .then(function(r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
                    .then(function(data) {
                        var rows = Array.isArray(data.shifts) ? data.shifts : [];
                        if (!rows.length) { out.innerHTML = '<p class="text-gray-600">No shifts opened on ' + esc(date) + '.</p>'; return; }
                        var html = '<ul class="space-y-1.5">';
                        rows.forEach(function(s) {
                            var status = s.open ? '<span class="text-emerald-700 font-semibold">open</span>' : 'closed ' + esc(s.closed_at || '—');
                            var totals = s.open
                                ? 'totals at close'
                                : esc(s.transactions == null ? '—' : s.transactions) + ' tx · Rp ' + esc(s.revenue == null ? '—' : Number(s.revenue).toLocaleString('id-ID'));
                            html += '<li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-gray-200 first:border-t-0 pt-1.5 first:pt-0">'
                                + '<span><span class="font-semibold">' + esc(s.admin) + '</span> · ' + esc(s.period) + ' · opened ' + esc(s.opened_at || '—') + ' · ' + status + '</span>'
                                + '<span class="shrink-0">' + totals + ' · <a class="text-indigo-700 underline" href="' + esc(s.report_url) + '">report</a></span>'
                                + '</li>';
                        });
                        html += '</ul>';
                        out.innerHTML = html;
                    })
                    .catch(function() { out.innerHTML = '<p class="text-red-700">Lookup failed.</p>'; });
            }
            document.addEventListener('click', function(e) {
                if (e.target && e.target.closest && e.target.closest('#shiftLookupCheckBtn')) run();
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && e.target && e.target.id === 'shiftLookupDate') { e.preventDefault(); run(); }
            });
        })();
    </script>

    @include('partials.accounting-help-fab', ['tour' => 'module', 'step' => 5, 'endStep' => 9])
</x-app-layout>
