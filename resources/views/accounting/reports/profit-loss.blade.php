<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Profit &amp; Loss
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            @include('partials.accounting-trial-banner')

            <a href="{{ route('accounting.dashboard') }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">&larr; Back to Dashboard</a>

            <div class="flex items-center justify-between mt-4 mb-6 flex-wrap gap-3">
                <form method="GET" class="flex flex-wrap items-center gap-2 text-sm">
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-input text-sm">
                    <span class="text-gray-400">to</span>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-input text-sm">
                    <button type="submit" class="px-3 py-2 bg-gray-900 text-white rounded-md text-sm hover:bg-black cursor-pointer">Filter</button>
                </form>
                <a href="{{ route('accounting.reports.profit-loss.pdf', request()->query()) }}" download
                    class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer">
                    Download PDF
                </a>
            </div>

            <div id="profitLossContent" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900">Profit &amp; Loss ({{ $storeLabel }})</h3>
                        <span class="text-sm text-gray-500">
                            {{ \Illuminate\Support\Carbon::parse($dateFrom)->format('d M Y') }} &ndash; {{ \Illuminate\Support\Carbon::parse($dateTo)->format('d M Y') }}
                        </span>
                    </div>

                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">Revenue</h4>
                    <table class="w-full text-sm mb-4">
                        <tbody>
                            @forelse($revenueRows as $row)
                                <tr>
                                    <td class="py-1.5 text-gray-600">{{ $row['account']->name }}</td>
                                    <td class="py-1.5 text-right tabular-nums text-gray-900">Rp {{ number_format($row['amount'], 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="py-3 text-center text-gray-400">No revenue in this range.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-gray-200">
                                <td class="py-2 font-semibold text-gray-900">Total Revenue</td>
                                <td class="py-2 text-right font-semibold text-emerald-700 tabular-nums">Rp {{ number_format($revenueTotal, 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>

                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">Expenses</h4>
                    <table class="w-full text-sm">
                        <tbody>
                            @forelse($expenseRows as $row)
                                <tr>
                                    <td class="py-1.5 text-gray-600">{{ $row['account']->name }}</td>
                                    <td class="py-1.5 text-right tabular-nums text-gray-900">Rp {{ number_format($row['amount'], 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="py-3 text-center text-gray-400">No expenses in this range.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-gray-200">
                                <td class="py-2 font-semibold text-gray-900">Total Expenses</td>
                                <td class="py-2 text-right font-semibold text-red-700 tabular-nums">Rp {{ number_format($expenseTotal, 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>

                    <div class="mt-6 pt-4 border-t border-gray-200 flex items-center justify-between">
                        <p class="font-semibold text-gray-900">Net Profit (Loss)</p>
                        <p class="text-xl font-bold tabular-nums {{ $netIncome >= 0 ? 'text-emerald-700' : 'text-red-700' }}">
                            Rp {{ number_format($netIncome, 0, ',', '.') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('partials.accounting-help-fab', ['tour' => 'reports', 'step' => 7])
</x-app-layout>
