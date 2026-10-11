<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Shift Transaction Detail
        </h2>
        <p class="text-sm text-gray-500 mt-1">{{ $storeScopeLabel ?? 'Current Store' }}</p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">{{ $selectedShift->periodLabel() }} shift</h3>
                        <p class="text-sm text-gray-500 mt-1">
                            {{ $selectedShift->opened_at?->format('d M Y H:i') ?? '—' }}
                            @if($selectedShift->openedBy)
                                · {{ $selectedShift->openedBy->name }}
                            @endif
                        </p>
                    </div>
                    <a
                        href="{{ route('reports.admin-transactions', request()->query()) }}"
                        class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-md text-sm font-medium hover:bg-gray-50"
                    >
                        Back to shift list
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-5 h-full">
                        <div class="text-xs font-medium text-gray-500 uppercase">Transactions</div>
                        <div class="mt-2 text-2xl font-bold text-gray-800">{{ $shiftReport['transaction_count'] ?? 0 }}</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-5 h-full">
                        <div class="text-xs font-medium text-gray-500 uppercase">Fund</div>
                        <div class="mt-2 text-2xl font-bold text-emerald-600">Rp {{ number_format((float) ($shiftReport['total_paid'] ?? 0), 0, ',', '.') }}</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-5 h-full">
                        <div class="text-xs font-medium text-gray-500 uppercase">Filter Range</div>
                        <div class="mt-2 text-sm font-medium text-gray-800">{{ $filters['date_from'] ?? '-' }} to {{ $filters['date_to'] ?? '-' }}</div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Transaction detail</h3>
                    @if($shiftReport && collect($shiftReport['rows'] ?? [])->isNotEmpty())
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Activity date</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Activity</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Transaction date</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Original shift</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Items</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Paid in shift</th>
                                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Remaining</th>
                                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total due</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($shiftReport['rows'] as $report)
                                        @php
                                            $rental = $report['rental'];
                                            $financial = $report['financial'];
                                            $originalShift = $rental->shiftSession;
                                        @endphp
                                        <tr class="align-top">
                                            <td class="px-3 py-3 whitespace-nowrap text-gray-700">
                                                {{ $report['activity_at']?->format('d M Y H:i') ?? '—' }}
                                            </td>
                                            <td class="px-3 py-3 whitespace-nowrap">
                                                <span class="inline-flex rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800">
                                                    {{ $report['activity_label'] }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-3 whitespace-nowrap text-gray-900 font-medium">{{ $rental->customer_name }}</td>
                                            <td class="px-3 py-3 whitespace-nowrap text-gray-700">
                                                {{ $rental->created_at?->format('d M Y H:i') ?? '—' }}
                                            </td>
                                            <td class="px-3 py-3 text-gray-700">
                                                @if($originalShift)
                                                    <div>{{ $originalShift->periodLabel() }} shift</div>
                                                    <div class="text-xs text-gray-500">
                                                        {{ $originalShift->opened_at?->format('d M Y H:i') ?? '—' }}
                                                        @if($originalShift->openedBy)
                                                            · {{ $originalShift->openedBy->name }}
                                                        @endif
                                                    </div>
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="px-3 py-3 text-xs text-gray-700 max-w-sm">{{ $financial['items_display'] }}</td>
                                            <td class="px-3 py-3 whitespace-nowrap">
                                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full {{ $rental->status === 'active' ? 'bg-green-100 text-green-800' : ($rental->status === 'returned' ? 'bg-gray-100 text-gray-800' : 'bg-red-100 text-red-800') }}">
                                                    {{ ucfirst($rental->status) }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-3 whitespace-nowrap text-right font-semibold text-emerald-700">Rp {{ number_format((float) ($report['paid_in_shift'] ?? 0), 0, ',', '.') }}</td>
                                            <td class="px-3 py-3 whitespace-nowrap text-right text-amber-700">Rp {{ number_format((float) ($report['outstanding'] ?? 0), 0, ',', '.') }}</td>
                                            <td class="px-3 py-3 whitespace-nowrap text-right text-gray-900 font-medium">Rp {{ number_format((float) ($financial['net_incl_tax'] ?? 0), 0, ',', '.') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-6 py-8 text-center text-sm text-gray-500">
                            No transactions found in this shift for the selected filter.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
