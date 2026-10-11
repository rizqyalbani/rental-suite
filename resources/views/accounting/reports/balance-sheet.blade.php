<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Balance Sheet
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            @include('partials.accounting-trial-banner')

            <a href="{{ route('accounting.dashboard') }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">&larr; Back to Dashboard</a>

            <div class="flex items-center justify-between mt-4 mb-6 flex-wrap gap-3">
                <form method="GET" class="flex flex-wrap items-center gap-2 text-sm">
                    <label for="as_of" class="text-gray-500">As of</label>
                    <input type="date" id="as_of" name="as_of" value="{{ $asOf }}" class="form-input text-sm">
                    <button type="submit" class="px-3 py-2 bg-gray-900 text-white rounded-md text-sm hover:bg-black cursor-pointer">Filter</button>
                </form>
                <a href="{{ route('accounting.reports.balance-sheet.pdf', request()->query()) }}" download
                    class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer">
                    Download PDF
                </a>
            </div>

            <div class="flex items-center justify-between mb-2">
                <h3 class="text-sm font-semibold text-gray-500">Balance Sheet ({{ $storeLabel }})</h3>
                <span class="text-sm text-gray-500">Per {{ \Illuminate\Support\Carbon::parse($asOf)->format('d M Y') }}</span>
            </div>

            <div id="balanceSheetContent" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200">
                    <div class="p-5">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">Assets</h4>
                        <table class="w-full text-sm">
                            <tbody>
                                @forelse($assetRows as $row)
                                    <tr>
                                        <td class="py-1.5 text-gray-600">{{ $row['account']->name }}</td>
                                        <td class="py-1.5 text-right tabular-nums text-gray-900">Rp {{ number_format($row['balance'], 0, ',', '.') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="2" class="py-3 text-center text-gray-400">No assets yet.</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-gray-200">
                                    <td class="py-2 font-semibold text-gray-900">Total Assets</td>
                                    <td class="py-2 text-right font-semibold text-gray-900 tabular-nums">Rp {{ number_format($assetTotal, 0, ',', '.') }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="flex flex-col gap-6">
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200">
                        <div class="p-5">
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">Liabilities</h4>
                            <table class="w-full text-sm">
                                <tbody>
                                    @forelse($liabilityRows as $row)
                                        <tr>
                                            <td class="py-1.5 text-gray-600">{{ $row['account']->name }}</td>
                                            <td class="py-1.5 text-right tabular-nums text-gray-900">Rp {{ number_format($row['balance'], 0, ',', '.') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="2" class="py-3 text-center text-gray-400">No liabilities yet.</td></tr>
                                    @endforelse
                                </tbody>
                                <tfoot>
                                    <tr class="border-t border-gray-200">
                                        <td class="py-2 font-semibold text-gray-900">Total Liabilities</td>
                                        <td class="py-2 text-right font-semibold text-gray-900 tabular-nums">Rp {{ number_format($liabilityTotal, 0, ',', '.') }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200">
                        <div class="p-5">
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">Equity</h4>
                            <table class="w-full text-sm">
                                <tbody>
                                    @forelse($equityRows as $row)
                                        <tr>
                                            <td class="py-1.5 text-gray-600">{{ $row['account']->name }}</td>
                                            <td class="py-1.5 text-right tabular-nums text-gray-900">Rp {{ number_format($row['balance'], 0, ',', '.') }}</td>
                                        </tr>
                                    @empty
                                    @endforelse
                                    <tr>
                                        <td class="py-1.5 text-gray-600 italic">Current Year Profit (Loss) (calculated automatically)</td>
                                        <td class="py-1.5 text-right tabular-nums text-gray-900 italic">Rp {{ number_format($currentEarnings, 0, ',', '.') }}</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t border-gray-200">
                                        <td class="py-2 font-semibold text-gray-900">Total Equity</td>
                                        <td class="py-2 text-right font-semibold text-gray-900 tabular-nums">Rp {{ number_format($equityTotal, 0, ',', '.') }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            @php $liabPlusEquity = $liabilityTotal + $equityTotal; @endphp
            <div class="mt-6 bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5 flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Total Assets</p>
                    <p class="text-lg font-bold text-gray-900 tabular-nums">Rp {{ number_format($assetTotal, 0, ',', '.') }}</p>
                </div>
                <span class="text-gray-300 text-2xl">=</span>
                <div>
                    <p class="text-sm text-gray-500">Total Liabilities + Equity</p>
                    <p class="text-lg font-bold text-gray-900 tabular-nums">Rp {{ number_format($liabPlusEquity, 0, ',', '.') }}</p>
                </div>
                <span class="text-sm font-medium {{ abs($assetTotal - $liabPlusEquity) < 0.01 ? 'text-emerald-700' : 'text-red-700' }}">
                    {{ abs($assetTotal - $liabPlusEquity) < 0.01 ? '✓ Balanced' : '⚠ Not balanced' }}
                </span>
            </div>
        </div>
    </div>

    @include('partials.accounting-help-fab', ['tour' => 'reports', 'step' => 5])
</x-app-layout>
