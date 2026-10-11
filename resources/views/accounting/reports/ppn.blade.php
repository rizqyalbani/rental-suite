<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            VAT (Preview)
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            @include('partials.accounting-trial-banner')

            <a href="{{ route('accounting.dashboard') }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">&larr; Back to Dashboard</a>

            <div class="mt-4 bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg text-sm">
                This is only an <strong>estimate</strong>, calculated from existing data. Nothing is recorded or
                posted to the books from this page. It is safe to open any time, and its figures do not change
                any other report.
            </div>

            <div class="flex items-center justify-between mt-4 mb-6 flex-wrap gap-3">
                <form id="ppnFilterForm" method="GET" class="flex items-center gap-2 text-sm flex-wrap">
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-input text-sm">
                    <span class="text-gray-400">to</span>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-input text-sm">
                    <button type="submit" id="ppnFilterBtn" class="px-3 py-2 bg-gray-900 text-white rounded-md text-sm hover:bg-black cursor-pointer">Filter</button>
                </form>
            </div>

            <div id="ppnPreviewContent" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-lg font-medium text-gray-900">Estimated VAT Remittance ({{ $storeLabel }})</h3>
                        <span class="text-sm text-gray-500">{{ \Illuminate\Support\Carbon::parse($dateFrom)->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($dateTo)->format('d M Y') }}</span>
                    </div>

                    @if($taxPercent <= 0)
                        <p class="text-sm text-gray-500 mt-4">
                            The tax rate has not been enabled in Settings (Pricing Tax %), so nothing can be
                            calculated yet. Set the rate in Settings first if you want to see this estimate.
                        </p>
                    @else
                        <p class="text-xs text-gray-500 mb-4 mt-1">
                            Calculated from Rental Revenue (4100) in this date range, using the {{ rtrim(rtrim(number_format($taxPercent, 2, ',', '.'), '0'), ',') }}% rate
                            that is active in Settings ({{ $pricesIncludeTax ? 'prices include tax' : 'prices exclude tax' }}).
                        </p>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <tbody class="divide-y divide-gray-100">
                                    <tr>
                                        <td class="px-4 py-3 text-sm text-gray-600">Rental Revenue (gross)</td>
                                        <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-900">Rp {{ number_format($grossRevenue, 0, ',', '.') }}</td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-3 text-sm text-gray-600">Taxable Base (DPP)</td>
                                        <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-900">Rp {{ number_format($dpp, 0, ',', '.') }}</td>
                                    </tr>
                                    <tr class="bg-gray-50">
                                        <td class="px-4 py-3 text-sm font-semibold text-gray-900">Estimated VAT to Remit</td>
                                        <td class="px-4 py-3 text-sm text-right tabular-nums font-semibold text-gray-900">Rp {{ number_format($ppn, 0, ',', '.') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @include('partials.accounting-help-fab', ['tour' => 'ppn', 'step' => 3, 'endStep' => 5])
</x-app-layout>
