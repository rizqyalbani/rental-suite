<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Memo</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Debit</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Journal</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($lines as $line)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $line->journalEntry->entry_date->format('d M Y') }}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">{{ $line->account->code }} — {{ $line->account->name }}</td>
                    <td class="px-4 py-3 text-sm text-gray-500">
                        {{ $line->line_memo ?: $line->journalEntry->memo }}
                        @if($search !== '' && $line->journalEntry->store)
                            <span class="ml-1 px-1.5 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800">{{ $line->journalEntry->store->name }}</span>
                        @endif
                        @if($line->journalEntry->is_reversal)
                            <span class="ml-1 px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">Reversal</span>
                        @endif
                        @if($line->journalEntry->is_tutorial)
                            <span class="ml-1 px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">Trial</span>
                        @endif
                        @if($line->journalEntry->fixedAsset)
                            <span class="ml-1 px-1.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">{{ $line->journalEntry->fixedAsset->name }}</span>
                        @elseif((float) $line->credit > 0 && in_array($line->account->code, $fixedAssetAccountCodes, true))
                            <span class="ml-1 px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">Not linked to any asset</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-right tabular-nums text-gray-900">{{ (float) $line->debit > 0 ? 'Rp '.number_format((float) $line->debit, 0, ',', '.') : '—' }}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-right tabular-nums text-gray-900">{{ (float) $line->credit > 0 ? 'Rp '.number_format((float) $line->credit, 0, ',', '.') : '—' }}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-right text-sm">
                        <a href="{{ route('accounting.reports.general-ledger.entry', $line->journal_entry_id) }}" class="text-indigo-600 hover:text-indigo-900">#{{ $line->journal_entry_id }}</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-400">
                        {{ $search !== '' ? 'Nothing found — there may never have been a payment recorded for this.' : 'No journal entries in this range.' }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">
    {{ $lines->links() }}
</div>
