<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Trial Balance
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
                <a href="{{ route('accounting.reports.trial-balance.pdf', request()->query()) }}" download
                    class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer">
                    Download PDF
                </a>
            </div>

            <div id="trialBalanceContent" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900">Trial Balance ({{ $storeLabel }})</h3>
                        <span class="text-sm text-gray-500">Per {{ \Illuminate\Support\Carbon::parse($asOf)->format('d M Y') }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Debit</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($rows as $row)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $row['account']->code }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">{{ $row['account']->name }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-right tabular-nums text-gray-900">{{ $row['debit'] > 0 ? 'Rp '.number_format($row['debit'], 0, ',', '.') : '—' }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-right tabular-nums text-gray-900">{{ $row['credit'] > 0 ? 'Rp '.number_format($row['credit'], 0, ',', '.') : '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-400">No activity up to this date yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot class="bg-gray-50">
                                <tr>
                                    <td colspan="2" class="px-4 py-3 text-sm font-semibold text-gray-900">Total</td>
                                    <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900 tabular-nums">Rp {{ number_format($totalDebit, 0, ',', '.') }}</td>
                                    <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900 tabular-nums">Rp {{ number_format($totalCredit, 0, ',', '.') }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="mt-4 text-sm {{ abs($totalDebit - $totalCredit) < 0.01 ? 'text-emerald-700' : 'text-red-700' }} font-medium">
                        {{ abs($totalDebit - $totalCredit) < 0.01 ? '✓ Balanced: total debit equals total credit.' : '⚠ Not balanced, report it to the developer.' }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    @include('partials.accounting-help-fab', ['tour' => 'reports', 'step' => 3])
</x-app-layout>
