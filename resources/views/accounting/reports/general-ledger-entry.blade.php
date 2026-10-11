<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Journal #{{ $entry->id }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            @include('partials.accounting-trial-banner')

            <a href="{{ route('accounting.reports.general-ledger') }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">&larr; Back to General Ledger</a>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mt-4">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-lg font-medium text-gray-900">{{ $entry->memo ?: 'Journal #'.$entry->id }}</h3>
                        @if($entry->is_tutorial)
                            <span class="px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">Trial</span>
                        @endif
                    </div>
                    <p class="text-sm text-gray-500 mb-4">
                        {{ $entry->entry_date->format('d M Y') }}
                        @if($entry->reference_type)
                            · {{ ucfirst(str_replace('_', ' ', $entry->reference_type)) }} #{{ $entry->reference_id }}
                        @endif
                        @if($entry->createdBy)
                            · by {{ $entry->createdBy->name }}
                        @endif
                    </p>

                    @if($entry->is_reversal && $reverses)
                        <p class="text-sm bg-amber-50 border border-amber-200 rounded p-3 mb-4 text-amber-800">
                            This is the reversing journal of
                            <a href="{{ route('accounting.reports.general-ledger.entry', $reverses->id) }}" class="underline font-medium">Journal #{{ $reverses->id }}</a>.
                        </p>
                    @endif
                    @if($reversedBy)
                        <p class="text-sm bg-amber-50 border border-amber-200 rounded p-3 mb-4 text-amber-800">
                            This journal has been reversed by
                            <a href="{{ route('accounting.reports.general-ledger.entry', $reversedBy->id) }}" class="underline font-medium">Journal #{{ $reversedBy->id }}</a>.
                        </p>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Debit</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($entry->lines as $line)
                                    <tr>
                                        <td class="px-4 py-3 text-sm text-gray-900">
                                            {{ $line->account->code }} — {{ $line->account->name }}
                                            @if($line->line_memo)
                                                <p class="text-xs text-gray-400">{{ $line->line_memo }}</p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right text-sm tabular-nums text-gray-900">{{ (float) $line->debit > 0 ? 'Rp '.number_format((float) $line->debit, 0, ',', '.') : '—' }}</td>
                                        <td class="px-4 py-3 text-right text-sm tabular-nums text-gray-900">{{ (float) $line->credit > 0 ? 'Rp '.number_format((float) $line->credit, 0, ',', '.') : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
