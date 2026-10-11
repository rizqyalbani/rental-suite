<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Receivables (Accounts Receivable)
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            @include('partials.accounting-trial-banner')

            <a href="{{ route('accounting.dashboard') }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">&larr; Back to Dashboard</a>

            @if($missingAccount)
                <div class="mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded text-sm">
                    Account "1300 Rental Receivable" was not found in the Chart of Accounts, so this report cannot be calculated.
                </div>
            @else
                <div id="piutangContent" class="bg-white overflow-hidden shadow-sm sm:rounded-lg mt-4">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-lg font-medium text-gray-900">Rentals Still Owing ({{ $storeLabel }})</h3>
                            <span class="text-sm text-gray-500">{{ $rows->count() }} rental</span>
                        </div>
                        <p class="text-xs text-gray-500 mb-4">
                            The remaining amount still to be collected from customers, from rentals that have received a
                            deposit (DP) but are not fully paid. A row disappears from the list automatically once it is
                            fully paid.
                        </p>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Customer</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Rental</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Rental Start</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Still Owed</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @forelse($rows as $row)
                                        @php $rental = $row['rental']; @endphp
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 text-sm text-gray-900">
                                                {{ $rental->customer_name }}
                                                @if($rental->phone)
                                                    <p class="text-xs text-gray-400">{{ $rental->phone }}</p>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                                <a href="{{ route('rentals.index', ['id' => $rental->id]) }}" class="text-indigo-600 hover:text-indigo-900">#{{ $rental->id }}</a>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $rental->start_date->format('d M Y') }}</td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right tabular-nums font-medium text-gray-900">Rp {{ number_format($row['net_piutang'], 0, ',', '.') }}</td>
                                        </tr>
                                    @empty
                                        <tr class="piutang-empty-message">
                                            <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-400">Nothing is outstanding: all rentals are fully paid.</td>
                                        </tr>
                                        {{-- Revealed only by piutang-tour.blade.php while its "Read the list" step
                                             is active — replaces the message above (never stacks under it) — a
                                             viewer just browsing this page normally never sees these, only someone
                                             actually running the tutorial with nothing real to point at. --}}
                                        <tr class="hidden piutang-example-row">
                                            <td colspan="4" class="px-4 py-3 text-center text-xs text-gray-400 bg-gray-50">
                                                Here is an example of what this looks like when there are receivables (not real data):
                                            </td>
                                        </tr>
                                        <tr class="hidden piutang-example-row text-gray-400 italic">
                                            <td class="px-4 py-3 text-sm">Made Wirawan</td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm">#128</td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm">3 Sep 2026</td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right tabular-nums">Rp 350.000</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                                @if($rows->isNotEmpty())
                                    <tfoot class="bg-gray-50">
                                        <tr>
                                            <td colspan="3" class="px-4 py-3 text-sm font-semibold text-gray-900">Total Receivables</td>
                                            <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900 tabular-nums">Rp {{ number_format($total, 0, ',', '.') }}</td>
                                        </tr>
                                    </tfoot>
                                @endif
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @include('partials.accounting-help-fab', ['tour' => 'piutang', 'step' => 3, 'endStep' => 4])
</x-app-layout>
