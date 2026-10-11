<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Fixed Assets
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            @include('partials.accounting-trial-banner')

            <a href="{{ route('accounting.dashboard') }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">&larr; Back to Dashboard</a>

            @if(session('success'))
                <div class="mt-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative text-sm">
                    {{ session('success') }}
                </div>
            @endif
            @if($errors->any())
                <div class="mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative text-sm">
                    <ul class="list-disc list-inside">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex items-center justify-between mt-4 mb-6 flex-wrap gap-3">
                <form method="GET" class="flex flex-wrap items-center gap-2 text-sm">
                    <label for="as_of" class="text-gray-500">As of</label>
                    <input type="date" id="as_of" name="as_of" value="{{ $asOf }}" class="form-input text-sm">
                    <button type="submit" class="px-3 py-2 bg-gray-900 text-white rounded-md text-sm hover:bg-black cursor-pointer">Filter</button>
                </form>
            </div>

            <div id="fixedAssetsContent" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-lg font-medium text-gray-900">Fixed Assets Summary ({{ $storeLabel }})</h3>
                        <span class="text-sm text-gray-500">Per {{ \Illuminate\Support\Carbon::parse($asOf)->format('d M Y') }}</span>
                    </div>
                    <p class="text-xs text-gray-500 mb-4">
                        Per account, not per item. Purchase Price = the total ever recorded into this account
                        (purchases through Expenses/Cash In-Out); this value stays intact and is not overwritten by depreciation.
                        Total Depreciated = the sum of depreciation of all items in this category (from the "Depreciate" button
                        in the Item List). Current Book Value = Purchase Price minus Total Depreciated.
                    </p>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Code</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Account</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Purchase Price</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Total Depreciated</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Current Book Value</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($rows as $row)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $row['account']->code }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">{{ $row['account']->name }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-right tabular-nums text-gray-900">Rp {{ number_format($row['purchased'], 0, ',', '.') }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-right tabular-nums text-gray-900">
                                            Rp {{ number_format($row['depreciated'], 0, ',', '.') }}
                                            @if($row['otherCredits'] > 0.01)
                                                <p class="text-xs text-gray-400 whitespace-normal">
                                                    Rp {{ number_format($row['otherCredits'], 0, ',', '.') }} is not item depreciation; it is what remains of corrections/adjustments of older transactions in this account.
                                                </p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-right tabular-nums font-medium text-gray-900">Rp {{ number_format($row['bookValue'], 0, ',', '.') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-400">No activity up to this date yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot class="bg-gray-50">
                                <tr>
                                    <td colspan="2" class="px-4 py-3 text-sm font-semibold text-gray-900">Total</td>
                                    <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900 tabular-nums">Rp {{ number_format($totalPurchased, 0, ',', '.') }}</td>
                                    <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900 tabular-nums">Rp {{ number_format($totalDepreciated, 0, ',', '.') }}</td>
                                    <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900 tabular-nums">Rp {{ number_format($totalBookValue, 0, ',', '.') }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div id="fixedAssetFormSection" class="bg-white overflow-hidden shadow-sm sm:rounded-lg mt-6">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Add Item</h3>
                    <p class="text-xs text-gray-500 mb-4">
                        Adding an item here also records its purchase transaction (real money going out),
                        just like Expenses, except it lands in an asset account (Equipment/Supplies) instead of an Expense.
                    </p>
                    <form id="fixedAssetForm" method="POST" action="{{ route('accounting.fixed-assets.store') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @csrf
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Item Name</label>
                            <input type="text" id="fixedAssetName" name="name" required maxlength="255" class="form-input w-full" placeholder="e.g. Broom, Hanger Batch Sep 2026, Wardrobe Display" value="{{ old('name') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Purchase Date</label>
                            <input type="date" name="purchase_date" required class="form-input w-full" value="{{ old('purchase_date', now()->toDateString()) }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Purchase Price</label>
                            <input type="text" inputmode="decimal" id="fixedAssetPrice" name="purchase_price" required class="form-input w-full" placeholder="e.g. 50.000" value="{{ old('purchase_price') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Category (account)</label>
                            <select id="fixedAssetAccountInput" name="account_id" required class="form-select w-full" data-searchable-select data-searchable-placeholder="Search category...">
                                <option value="">— Select —</option>
                                @foreach($assetAccounts as $account)
                                    <option value="{{ $account->id }}" {{ old('account_id') == $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Paid from</label>
                            <select id="fixedAssetPaymentInput" name="payment_account_id" required class="form-select w-full" data-searchable-select data-searchable-placeholder="Search cash/bank account...">
                                <option value="">— Select —</option>
                                @foreach($paymentAccounts as $account)
                                    <option value="{{ $account->id }}" {{ old('payment_account_id') == $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                            <input type="text" name="notes" maxlength="500" class="form-input w-full" value="{{ old('notes') }}">
                        </div>
                        <div class="md:col-span-2">
                            <button type="submit" id="fixedAssetSubmitBtn" class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer">Add Item</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="fixedAssetListSection" class="bg-white overflow-hidden shadow-sm sm:rounded-lg mt-6">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Item List</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Item Name</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Category</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Purchase Date</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Purchase Price</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Total Depreciated</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Current Book Value</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($assets as $row)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-sm text-gray-900">
                                            <span class="whitespace-nowrap">{{ $row['asset']->name }}</span>
                                            @if($row['asset']->notes)
                                                <p class="text-xs text-gray-400 whitespace-nowrap">{{ $row['asset']->notes }}</p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $row['asset']->account->code }} — {{ $row['asset']->account->name }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $row['asset']->purchase_date->format('d M Y') }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-right tabular-nums text-gray-900">Rp {{ number_format((float) $row['asset']->purchase_price, 0, ',', '.') }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-right tabular-nums text-gray-900">Rp {{ number_format($row['depreciated'], 0, ',', '.') }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-right tabular-nums font-medium {{ $row['bookValue'] < 0 ? 'text-red-700' : 'text-gray-900' }}">Rp {{ number_format($row['bookValue'], 0, ',', '.') }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-right text-sm">
                                            <div class="flex justify-end gap-2">
                                                <button type="button" data-riwayat-trigger
                                                    onclick='openHistoryModal(@json(["name" => $row["asset"]->name, "entries" => $row["history"]]))'
                                                    class="px-3 py-1.5 bg-white border border-gray-300 text-gray-700 rounded-md text-xs font-medium hover:bg-gray-50 cursor-pointer">
                                                    History
                                                </button>
                                                <button type="button" data-susutkan-trigger
                                                    onclick='openDepreciateModal(@json(["id" => $row["asset"]->id, "name" => $row["asset"]->name]))'
                                                    class="px-3 py-1.5 bg-gray-900 text-white rounded-md text-xs font-medium hover:bg-black cursor-pointer">
                                                    Depreciate
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-400">No items registered yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-4 text-xs text-gray-500">
                        Click "Depreciate" to record the drop in value of one item; there is no need to fill in Debit/Credit manually.
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- z-[201]: above the accounting tour's own spotlight/backdrop layers
         (z-[198]/z-[199]/z-[200], see fixed-assets-tour.blade.php) — this
         modal is real page UI the guided tour opens ON TOP of itself
         (the "Depreciate" practice step), and needs to render above the tour's dark
         overlay instead of getting visually dimmed underneath it. --}}
    <div id="depreciateModal" class="hidden fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-[201] p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-1">Depreciate Item</h3>
            <p id="depreciateModalItemName" class="text-sm text-gray-600 mb-4"></p>
            <p class="text-xs text-gray-500 mb-4">
                Enter how much this item's value dropped. The system automatically records the Depreciation Expense,
                so you do not need to choose any account.
            </p>
            <form id="depreciateForm" method="POST">
                @csrf
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="date" id="depreciateDate" required class="form-input w-full" value="{{ now()->toDateString() }}">
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Depreciation Amount</label>
                    <input type="text" inputmode="decimal" id="depreciateAmount" name="amount" required class="form-input w-full" placeholder="e.g. 10.000">
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                    <input type="text" name="note" id="depreciateNote" maxlength="500" class="form-input w-full" placeholder="e.g. Annual depreciation">
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeDepreciateModal()" class="px-4 py-2 bg-gray-200 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-300 cursor-pointer">Cancel</button>
                    <button type="submit" id="depreciateSubmitBtn" class="px-4 py-2 bg-indigo-600 rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer">Record Depreciation</button>
                </div>
            </form>
        </div>
    </div>

    {{-- z-[201]: same reasoning as depreciateModal above — the tour's own
         "Try the History" step opens this on top of itself. --}}
    <div id="historyModal" class="hidden fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-[201] p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-lg w-full mx-4 p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-1">Depreciation History</h3>
            <p id="historyModalItemName" class="text-sm text-gray-600 mb-4"></p>
            <div class="overflow-y-auto max-h-96">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                        </tr>
                    </thead>
                    <tbody id="historyModalRows" class="bg-white divide-y divide-gray-200"></tbody>
                </table>
            </div>
            <div class="flex justify-end mt-4">
                <button type="button" onclick="closeHistoryModal()" class="px-4 py-2 bg-gray-200 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-300 cursor-pointer">Close</button>
            </div>
        </div>
    </div>

    <script src="{{ asset('js/money-format.js') }}?v={{ filemtime(public_path('js/money-format.js')) }}"></script>
    <script src="{{ asset('js/searchable-select.js') }}?v={{ filemtime(public_path('js/searchable-select.js')) }}"></script>
    <script>
        bindMoneyThousandsInput(document.getElementById('fixedAssetPrice'));

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function openHistoryModal(payload) {
            document.getElementById('historyModalItemName').textContent = payload.name;
            var rows = document.getElementById('historyModalRows');
            if (!payload.entries.length) {
                rows.innerHTML = '<tr><td colspan="3" class="px-3 py-6 text-center text-sm text-gray-400">Never depreciated yet.</td></tr>';
            } else {
                rows.innerHTML = payload.entries.map(function (e) {
                    return '<tr>'
                        + '<td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500">' + escapeHtml(e.date) + '</td>'
                        + '<td class="px-3 py-2 whitespace-nowrap text-sm text-right tabular-nums text-gray-900">' + escapeHtml(e.amount) + '</td>'
                        + '<td class="px-3 py-2 text-sm text-gray-500">' + (e.note ? escapeHtml(e.note) : '<span class="text-gray-300">—</span>') + '</td>'
                        + '</tr>';
                }).join('');
            }
            document.getElementById('historyModal').classList.remove('hidden');
        }
        function closeHistoryModal() {
            document.getElementById('historyModal').classList.add('hidden');
        }
        document.getElementById('historyModal').addEventListener('click', function (e) {
            if (e.target === this) closeHistoryModal();
        });

        var depreciateAmountInput = document.getElementById('depreciateAmount');
        bindMoneyThousandsInput(depreciateAmountInput);

        function openDepreciateModal(asset) {
            document.getElementById('depreciateForm').action = '/accounting/reports/fixed-assets/' + asset.id + '/depreciate';
            document.getElementById('depreciateModalItemName').textContent = asset.name;
            document.getElementById('depreciateDate').value = new Date().toISOString().slice(0, 10);
            depreciateAmountInput.value = '';
            document.getElementById('depreciateNote').value = '';
            document.getElementById('depreciateModal').classList.remove('hidden');
        }
        function closeDepreciateModal() {
            document.getElementById('depreciateModal').classList.add('hidden');
        }
        document.getElementById('depreciateModal').addEventListener('click', function (e) {
            if (e.target === this) closeDepreciateModal();
        });
    </script>
    @include('partials.accounting-help-fab', ['tour' => 'fixed-assets', 'step' => 3, 'endStep' => 9])
</x-app-layout>
