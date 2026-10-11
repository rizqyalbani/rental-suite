<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Legacy Pricing Anomalies
        </h2>
        <p class="text-sm text-gray-500 mt-1">Investigating old rentals affected by the new 3-day block rule.</p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Affected Transactions</h3>
                    <p class="text-sm text-gray-500 mb-2">
                        These are historical rentals where the old `/day` pricing formula and the new `3-day block` formula calculate different final totals.
                    </p>
                    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-blue-700">
                                    <strong>How to fix these:</strong> Do not auto-guess. Please check the actual physical receipt or shop records for each transaction. Then open the Edit page for that rental and manually adjust the Discount or Additional Price until the system matches the physical receipt exactly.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID / Customer</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dates</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Legacy Receipt Total</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">New System Total</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Paid in System</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Difference</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($anomalies as $row)
                                    @php
                                        $oldNet = (float) $row->old_net;
                                        $newNet = (float) $row->new_net;
                                        $paid = (float) $row->amount_paid;
                                        $gap = $oldNet - $newNet;
                                        
                                        $color = 'text-amber-700 bg-amber-50';
                                        if ($gap > 0) {
                                            $color = 'text-indigo-700 bg-indigo-50';
                                        } elseif ($gap < 0) {
                                            $color = 'text-emerald-700 bg-emerald-50';
                                        }
                                    @endphp
                                    <tr class="align-top hover:bg-gray-50">
                                        <td class="px-3 py-3 whitespace-nowrap">
                                            <div class="font-medium text-gray-900">#{{ $row->id }}</div>
                                            <div class="text-gray-500">{{ $row->customer_name }}</div>
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap text-gray-700">
                                            {{ $row->start_date }} <br>to {{ $row->end_date }}
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap text-right font-medium text-gray-900">
                                            Rp {{ number_format($oldNet, 0, ',', '.') }}
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap text-right font-medium text-red-600">
                                            Rp {{ number_format($newNet, 0, ',', '.') }}
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap text-right font-medium text-gray-900">
                                            Rp {{ number_format($paid, 0, ',', '.') }}
                                            <div class="text-xs text-gray-500">{{ $row->payment_kind }}</div>
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $color }}">
                                                @if($gap > 0)
                                                    Legacy is higher by Rp {{ number_format(abs($gap), 0, ',', '.') }}
                                                @elseif($gap < 0)
                                                    Legacy is lower by Rp {{ number_format(abs($gap), 0, ',', '.') }}
                                                @else
                                                    Matches
                                                @endif
                                            </span>
                                            <div class="text-xs text-gray-500 mt-1">Please verify physical receipt before editing</div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-8 text-center text-gray-500">No anomalies found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
