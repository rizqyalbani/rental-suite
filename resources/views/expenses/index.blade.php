<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Expenses
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

            <div id="expenseAddSection" class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Record Expense</h3>
                    @if($categoryAccounts->isEmpty() || $paymentAccounts->isEmpty())
                        <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded p-3">
                            No expense categories or cash/bank accounts are set up yet — add them on the
                            <a href="{{ route('accounts.index') }}" class="underline font-medium">Chart of Accounts</a> page first.
                        </p>
                    @else
                        <form id="expenseForm" method="POST" action="{{ route('expenses.store') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @csrf
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                                <input type="date" name="expense_date" required class="form-input w-full" value="{{ old('expense_date', now()->toDateString()) }}">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rp</span>
                                    <input type="text" inputmode="decimal" id="expenseAmount" name="amount" required
                                        class="form-input w-full pl-9" placeholder="e.g. 50.000" value="{{ old('amount') }}">
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                                <select id="expenseCategoryInput" name="account_id" required class="form-select w-full" data-searchable-select data-searchable-placeholder="Search category...">
                                    <option value="">— Select —</option>
                                    @foreach($categoryAccounts as $account)
                                        <option value="{{ $account->id }}" {{ old('account_id') == $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Paid From</label>
                                <select id="expensePaymentAccountInput" name="payment_account_id" required class="form-select w-full" data-searchable-select data-searchable-placeholder="Search cash/bank account...">
                                    <option value="">— Select —</option>
                                    @foreach($paymentAccounts as $account)
                                        <option value="{{ $account->id }}" {{ old('payment_account_id') == $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Description (optional)</label>
                                <input id="expenseDescriptionInput" type="text" name="description" class="form-input w-full" value="{{ old('description') }}">
                            </div>
                            <div class="md:col-span-2">
                                <button id="expenseSubmitBtn" type="submit" class="px-4 py-2 bg-indigo-600 rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer">Record Expense</button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>

            <div id="expenseHistorySection" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
                        <h3 class="text-lg font-medium text-gray-900">Expense History</h3>
                        <form method="GET" class="flex flex-wrap items-center gap-2 text-sm">
                            <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-input text-sm">
                            <span class="text-gray-400">to</span>
                            <input type="date" name="date_to" value="{{ $dateTo }}" class="form-input text-sm">
                            @if($mayViewAllStores)
                                <label class="flex items-center gap-1 cursor-pointer ml-2">
                                    <input type="hidden" name="all_stores" value="0">
                                    <input type="checkbox" name="all_stores" value="1" {{ $allStoresMode ? 'checked' : '' }}
                                        onchange="this.form.submit()"
                                        class="h-4 w-4 rounded border-gray-400 text-indigo-600 shadow-none focus:ring-indigo-500 cursor-pointer">
                                    <span>All stores</span>
                                </label>
                                @if($allStoresMode)
                                    <select name="expense_store_id" onchange="this.form.submit()" class="form-select text-sm">
                                        <option value="0">All stores (combined)</option>
                                        @foreach($filterStores as $store)
                                            <option value="{{ $store->id }}" {{ $storeFilterId === (int) $store->id ? 'selected' : '' }}>{{ $store->name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            @endif
                            <button type="submit" class="px-3 py-2 bg-gray-900 text-white rounded-md text-sm hover:bg-black cursor-pointer">Filter</button>
                        </form>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    @if($allStoresMode)
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Store</th>
                                    @endif
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Paid From</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Recorded By</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                    @if(Auth::user()->mayEditAccountingEntries())
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($expenses as $expense)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">{{ $expense->expense_date->format('d M Y') }}</td>
                                        @if($allStoresMode)
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">{{ $expense->store?->name ?? '—' }}</td>
                                        @endif
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">{{ $expense->account?->name }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">{{ $expense->paymentAccount?->name }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-medium text-gray-900">Rp {{ number_format((float) $expense->amount, 0, ',', '.') }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $expense->recordedBy?->name ?? '—' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-500">
                                            {{ $expense->description ?: '—' }}
                                            @if($expense->is_tutorial)
                                                <span class="ml-1 px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">Trial</span>
                                            @endif
                                        </td>
                                        @if(Auth::user()->mayEditAccountingEntries())
                                            @php
                                                $expenseEditPayload = [
                                                    'id' => $expense->id,
                                                    'expense_date' => $expense->expense_date->toDateString(),
                                                    'account_id' => $expense->account_id,
                                                    'payment_account_id' => $expense->payment_account_id,
                                                    'amount' => (float) $expense->amount,
                                                    'description' => $expense->description,
                                                ];
                                            @endphp
                                            <td class="px-4 py-3 whitespace-nowrap text-right text-sm">
                                                <button type="button" onclick='openEditExpense(@json($expenseEditPayload))' class="text-indigo-600 hover:text-indigo-900 cursor-pointer">Edit</button>
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    @php
                                        $expensesEmptyColspan = 6 + ($allStoresMode ? 1 : 0) + (Auth::user()->mayEditAccountingEntries() ? 1 : 0);
                                    @endphp
                                    <tr>
                                        <td colspan="{{ $expensesEmptyColspan }}" class="px-4 py-6 text-center text-sm text-gray-400">No expenses recorded in this date range.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">
                        {{ $expenses->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(Auth::user()->mayEditAccountingEntries())
        <div id="editExpenseModal" class="hidden fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50 p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Edit Expense</h3>
                <p class="text-xs text-gray-500 mb-4">
                    Saving this corrects the ledger — it reverses the original entry and posts a new one for the
                    updated values, rather than silently changing the old one.
                </p>
                <form id="editExpenseForm" method="POST">
                    @csrf @method('PUT')
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                        <input type="date" name="expense_date" id="editExpenseDate" required class="form-input w-full">
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rp</span>
                            <input type="text" inputmode="decimal" id="editExpenseAmount" name="amount" required class="form-input w-full pl-9">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                        <select name="account_id" id="editExpenseAccount" required class="form-select w-full" data-searchable-select data-searchable-placeholder="Search category...">
                            @foreach($categoryAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Paid From</label>
                        <select name="payment_account_id" id="editExpensePaymentAccount" required class="form-select w-full" data-searchable-select data-searchable-placeholder="Search cash/bank account...">
                            @foreach($paymentAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <input type="text" name="description" id="editExpenseDescription" class="form-input w-full">
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="closeEditExpenseModal()" class="px-4 py-2 bg-gray-200 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-300 cursor-pointer">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer">Save Correction</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <script src="{{ asset('js/money-format.js') }}?v={{ filemtime(public_path('js/money-format.js')) }}"></script>
    <script src="{{ asset('js/searchable-select.js') }}?v={{ filemtime(public_path('js/searchable-select.js')) }}"></script>
    <script>
        bindMoneyThousandsInput(document.getElementById('expenseAmount'));
        @if(Auth::user()->mayEditAccountingEntries())
            var editExpenseAmountInput = document.getElementById('editExpenseAmount');
            bindMoneyThousandsInput(editExpenseAmountInput);

            function openEditExpense(expense) {
                document.getElementById('editExpenseForm').action = '/expenses/' + expense.id;
                document.getElementById('editExpenseDate').value = expense.expense_date;
                editExpenseAmountInput.value = moneyFormatNum(expense.amount);
                // dispatchEvent('change') after each: these two are wrapped
                // into a searchable combobox (searchable-select.js), whose
                // visible label only re-syncs on the select's own 'change'.
                var editExpenseAccountEl = document.getElementById('editExpenseAccount');
                editExpenseAccountEl.value = expense.account_id;
                editExpenseAccountEl.dispatchEvent(new Event('change'));
                var editExpensePaymentAccountEl = document.getElementById('editExpensePaymentAccount');
                editExpensePaymentAccountEl.value = expense.payment_account_id;
                editExpensePaymentAccountEl.dispatchEvent(new Event('change'));
                document.getElementById('editExpenseDescription').value = expense.description || '';
                document.getElementById('editExpenseModal').classList.remove('hidden');
            }
            function closeEditExpenseModal() {
                document.getElementById('editExpenseModal').classList.add('hidden');
            }
            document.getElementById('editExpenseModal').addEventListener('click', function (e) {
                if (e.target === this) closeEditExpenseModal();
            });
        @endif
    </script>

    @php
    // Admin reaches this page but not Dashboard/Chart of Accounts (mayViewAccountingDashboard()
    // is false for Admin), so accounting-tour.blade.php skips both of those step blocks (9 steps:
    // its own @if(mayViewAccountingDashboard()) and @if(mayViewChartOfAccounts()) sections) for
    // them — shifting every step after that back by 9. Keep this offset in sync if either block's
    // step count in accounting-tour.blade.php changes.
    $accHelpOffset = Auth::user()->mayViewAccountingDashboard() ? 0 : 9;
@endphp
@include('partials.accounting-help-fab', ['tour' => 'module', 'step' => 14 - $accHelpOffset, 'endStep' => 18 - $accHelpOffset])
</x-app-layout>
