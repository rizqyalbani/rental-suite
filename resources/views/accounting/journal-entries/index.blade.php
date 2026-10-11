<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Manual Journal Entry
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            @include('partials.accounting-trial-banner')
            @if(session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative text-sm">
                    {{ session('success') }}
                </div>
            @endif
            @if($errors->any())
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative text-sm">
                    <ul class="list-disc list-inside">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div id="journalEntryFormSection" class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Record Manual Journal Entry</h3>
                    <p class="text-xs text-gray-500 mb-4">
                        For adjustments that <strong>do not involve cash/bank</strong>, e.g. depreciation
                        (Debit Expense, Credit an Asset account). If money actually moves, use
                        <a href="{{ route('cash-movements.index') }}" class="underline font-medium">Cash In/Out</a>
                        or <a href="{{ route('expenses.index') }}" class="underline font-medium">Expenses</a>
                        instead of this page.
                    </p>
                    <form id="journalEntryForm" method="POST" action="{{ route('accounting.journal-entries.store') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @csrf
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                            <input type="date" name="entry_date" required class="form-input w-full" value="{{ old('entry_date', now()->toDateString()) }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                            <input type="text" inputmode="decimal" id="journalEntryAmount" name="amount" required class="form-input w-full" placeholder="e.g. 25.000" value="{{ old('amount') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Debit (increase)</label>
                            <select id="journalEntryDebitInput" name="debit_account_id" required class="form-select w-full" data-searchable-select data-searchable-placeholder="Search debit account...">
                                <option value="">— Select —</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}" {{ old('debit_account_id') == $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Credit (source)</label>
                            <select id="journalEntryCreditInput" name="credit_account_id" required class="form-select w-full" data-searchable-select data-searchable-placeholder="Search credit account...">
                                <option value="">— Select —</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}" {{ old('credit_account_id') == $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Asset being depreciated (optional)</label>
                            <select id="journalEntryFixedAssetInput" name="fixed_asset_id" class="form-select w-full" data-searchable-select data-searchable-placeholder="Search asset...">
                                <option value="">— Not tied to a specific asset —</option>
                                @foreach($fixedAssets as $fixedAsset)
                                    <option value="{{ $fixedAsset->id }}" {{ old('fixed_asset_id') == $fixedAsset->id ? 'selected' : '' }}>{{ $fixedAsset->name }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">
                                Fill this in if this entry is depreciation for one specific item in the
                                <a href="{{ route('accounting.reports.fixed-assets') }}" class="underline font-medium">Fixed Assets</a> list,
                                so that item's "Total Depreciated" is counted on its own, separate from other items.
                            </p>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Memo (required, explain the reason)</label>
                            <input type="text" id="journalEntryMemo" name="memo" required maxlength="500" class="form-input w-full" placeholder="e.g. Depreciation of hangers — Sep 2026" value="{{ old('memo') }}">
                        </div>
                        <div class="md:col-span-2">
                            <button type="submit" id="journalEntrySubmitBtn" class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer">Record Journal Entry</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="journalEntryHistorySection" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
                        <h3 class="text-lg font-medium text-gray-900">Manual Entry History</h3>
                        <form method="GET" class="flex items-center gap-2 text-sm">
                            <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-input text-sm">
                            <span class="text-gray-400">to</span>
                            <input type="date" name="date_to" value="{{ $dateTo }}" class="form-input text-sm">
                            <button type="submit" class="px-3 py-2 bg-gray-900 text-white rounded-md text-sm hover:bg-black cursor-pointer">Filter</button>
                        </form>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Memo</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Debit</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Credit</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @if(session('tutorialPracticePreview'))
                                    @php
                                        $preview = session('tutorialPracticePreview');
                                    @endphp
                                    <tr class="bg-indigo-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ \Illuminate\Support\Carbon::parse($preview['entry_date'])->format('d M Y') }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-900">
                                            {{ $preview['memo'] }}
                                            <span class="ml-1 px-1.5 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800">Practice (preview)</span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $preview['debit_label'] }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $preview['credit_label'] }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-right tabular-nums text-gray-900">Rp {{ number_format((float) $preview['amount'], 0, ',', '.') }}</td>
                                    </tr>
                                @endif
                                @forelse($entries as $entry)
                                    @php
                                        $debitLine = $entry->lines->firstWhere('debit', '>', 0);
                                        $creditLine = $entry->lines->firstWhere('credit', '>', 0);
                                    @endphp
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $entry->entry_date->format('d M Y') }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-900">
                                            {{ $entry->memo }}
                                            @if($entry->is_reversal)
                                                <span class="ml-1 px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">Reversal</span>
                                            @endif
                                            @if($entry->fixedAsset)
                                                <span class="ml-1 px-1.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">{{ $entry->fixedAsset->name }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $debitLine?->account?->code }} — {{ $debitLine?->account?->name }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $creditLine?->account?->code }} — {{ $creditLine?->account?->name }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-right tabular-nums text-gray-900">Rp {{ number_format((float) ($debitLine?->debit ?? 0), 0, ',', '.') }}</td>
                                    </tr>
                                @empty
                                    @unless(session('tutorialPracticePreview'))
                                        <tr>
                                            <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-400">No manual entries in this range yet.</td>
                                        </tr>
                                    @endunless
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">
                        {{ $entries->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="{{ asset('js/money-format.js') }}?v={{ filemtime(public_path('js/money-format.js')) }}"></script>
    <script src="{{ asset('js/searchable-select.js') }}?v={{ filemtime(public_path('js/searchable-select.js')) }}"></script>
    <script>
        bindMoneyThousandsInput(document.getElementById('journalEntryAmount'));
    </script>
    @include('partials.accounting-help-fab', ['tour' => 'journal-entry', 'step' => 3, 'endStep' => 6])
</x-app-layout>
