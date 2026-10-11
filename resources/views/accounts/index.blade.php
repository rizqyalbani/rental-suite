<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Chart of Accounts
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

            @if(Auth::user()->mayManageChartOfAccounts())
            <div id="accountAddSection" class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Add Account</h3>
                    <form method="POST" action="{{ route('accounts.store') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        @csrf
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Code</label>
                            <input type="text" name="code" required class="form-input w-full" placeholder="e.g. 5950" value="{{ old('code') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                            <input type="text" name="name" required class="form-input w-full" placeholder="e.g. Parking Fees" value="{{ old('name') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Parent (optional)</label>
                            <select name="parent_id" class="form-select w-full" data-searchable-select>
                                <option value="">None (top-level)</option>
                                @foreach($parents as $id => $name)
                                    <option value="{{ $id }}" {{ old('parent_id') == $id ? 'selected' : '' }}>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                            <select name="type" required class="form-select w-full">
                                <option value="asset" {{ old('type') == 'asset' ? 'selected' : '' }}>Asset</option>
                                <option value="liability" {{ old('type') == 'liability' ? 'selected' : '' }}>Liability</option>
                                <option value="equity" {{ old('type') == 'equity' ? 'selected' : '' }}>Equity</option>
                                <option value="revenue" {{ old('type') == 'revenue' ? 'selected' : '' }}>Revenue</option>
                                <option value="expense" {{ old('type') == 'expense' ? 'selected' : '' }}>Expense</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Normal Balance</label>
                            <select name="normal_balance" required class="form-select w-full">
                                <option value="debit" {{ old('normal_balance') == 'debit' ? 'selected' : '' }}>Debit</option>
                                <option value="credit" {{ old('normal_balance') == 'credit' ? 'selected' : '' }}>Credit</option>
                            </select>
                            <p class="text-xs text-gray-400 mt-1">Usually matches Type (asset/expense = debit, liability/equity/revenue = credit), except contra accounts like "Discount Given" or "Owner's Drawings".</p>
                        </div>
                        <div class="flex items-center gap-4 pt-6">
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="is_cash_equivalent" value="1" {{ old('is_cash_equivalent') ? 'checked' : '' }}>
                                <span class="text-sm text-gray-700">Cash/Bank account</span>
                            </label>
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="is_active" value="1" checked>
                                <span class="text-sm text-gray-700">Active</span>
                            </label>
                        </div>
                        <div class="md:col-span-3">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Description (optional)</label>
                            <input type="text" name="description" class="form-input w-full" value="{{ old('description') }}">
                        </div>
                        <div class="md:col-span-3">
                            <button type="submit" class="px-4 py-2 bg-indigo-600 rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer">Create</button>
                        </div>
                    </form>
                </div>
            </div>
            @endif

            <div id="accountsTableSection" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Accounts ({{ $accounts->count() }})</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Normal Balance</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Flags</th>
                                    @if(Auth::user()->mayManageChartOfAccounts())
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($topLevel as $top)
                                    <tr class="bg-gray-50">
                                        <td class="px-4 py-2 whitespace-nowrap text-sm font-semibold text-gray-900">{{ $top->code }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm font-semibold text-gray-900">{{ $top->name }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500 capitalize">{{ $top->type }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500 capitalize">{{ $top->normal_balance }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm">
                                            @if($top->is_tutorial)
                                                <span class="px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">Trial</span>
                                            @endif
                                        </td>
                                        @if(Auth::user()->mayManageChartOfAccounts())
                                            <td class="px-4 py-2 whitespace-nowrap text-right text-sm">
                                                <button type="button" onclick='openEditAccount(@json($top))' class="text-indigo-600 hover:text-indigo-900 cursor-pointer">Edit</button>
                                                @if($top->can_delete)
                                                    <form method="POST" action="{{ route('accounts.destroy', $top) }}" class="inline"
                                                        onsubmit="return window.rentalConfirmSubmit(event, {{ \Illuminate\Support\Js::from('Delete '.$top->name.'? This cannot be undone.') }}, { title: 'Delete account?', confirmButtonText: 'Delete', cancelButtonText: 'Cancel' })">
                                                        @csrf @method('DELETE')
                                                        <button class="ml-3 text-red-600 hover:text-red-900 cursor-pointer">Delete</button>
                                                    </form>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                    @foreach($accounts->where('parent_id', $top->id) as $child)
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700 pl-8">{{ $child->code }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700">{{ $child->name }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500 capitalize">{{ $child->type }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500 capitalize">{{ $child->normal_balance }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm">
                                                @if($child->is_cash_equivalent)
                                                    <span class="px-1.5 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800">Cash/Bank</span>
                                                @endif
                                                @if(!$child->is_active)
                                                    <span class="px-1.5 py-0.5 rounded text-xs font-medium bg-gray-200 text-gray-700">Inactive</span>
                                                @endif
                                                @if($child->is_tutorial)
                                                    <span class="px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">Trial</span>
                                                @endif
                                            </td>
                                            @if(Auth::user()->mayManageChartOfAccounts())
                                                <td class="px-4 py-2 whitespace-nowrap text-right text-sm">
                                                    <button type="button" onclick='openEditAccount(@json($child))' class="text-indigo-600 hover:text-indigo-900 cursor-pointer">Edit</button>
                                                    @if($child->can_delete)
                                                        <form method="POST" action="{{ route('accounts.destroy', $child) }}" class="inline"
                                                            onsubmit="return window.rentalConfirmSubmit(event, {{ \Illuminate\Support\Js::from('Delete '.$child->name.'? This cannot be undone.') }}, { title: 'Delete account?', confirmButtonText: 'Delete', cancelButtonText: 'Cancel' })">
                                                            @csrf @method('DELETE')
                                                            <button class="ml-3 text-red-600 hover:text-red-900 cursor-pointer">Delete</button>
                                                        </form>
                                                    @endif
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(Auth::user()->mayManageChartOfAccounts())
    <div id="editAccountModal" class="hidden fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Edit Account</h3>
            <form id="editAccountForm" method="POST">
                @csrf @method('PUT')
                <div class="mb-3 grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Code</label>
                        <input type="text" name="code" id="editAccountCode" required class="form-input w-full">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                        <input type="text" name="name" id="editAccountName" required class="form-input w-full">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Parent</label>
                    <select name="parent_id" id="editAccountParent" class="form-select w-full" data-searchable-select>
                        <option value="">None (top-level)</option>
                        @foreach($parents as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3 grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                        <select name="type" id="editAccountType" required class="form-select w-full">
                            <option value="asset">Asset</option>
                            <option value="liability">Liability</option>
                            <option value="equity">Equity</option>
                            <option value="revenue">Revenue</option>
                            <option value="expense">Expense</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Normal Balance</label>
                        <select name="normal_balance" id="editAccountNormalBalance" required class="form-select w-full">
                            <option value="debit">Debit</option>
                            <option value="credit">Credit</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3 flex items-center gap-4">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="is_cash_equivalent" id="editAccountCashEquivalent" value="1">
                        <span class="text-sm text-gray-700">Cash/Bank account</span>
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="is_active" id="editAccountActive" value="1">
                        <span class="text-sm text-gray-700">Active</span>
                    </label>
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <input type="text" name="description" id="editAccountDescription" class="form-input w-full">
                </div>
                <p id="editAccountLockedNote" class="hidden text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2 mb-3">
                    This account already has real journal history: code, type, and normal balance can no longer be changed.
                </p>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeAccountModal()" class="px-4 py-2 bg-gray-200 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-300 cursor-pointer">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer">Update</button>
                </div>
            </form>
        </div>
    </div>

    <script src="{{ asset('js/searchable-select.js') }}?v={{ filemtime(public_path('js/searchable-select.js')) }}"></script>
    <script>
        function openEditAccount(account) {
            document.getElementById('editAccountForm').action = '/accounts/' + account.id;
            document.getElementById('editAccountName').value = account.name;
            // dispatchEvent('change'): this select is wrapped into a
            // searchable combobox (searchable-select.js), whose visible
            // label only re-syncs on the select's own 'change' event.
            var editAccountParentEl = document.getElementById('editAccountParent');
            editAccountParentEl.value = account.parent_id || '';
            editAccountParentEl.dispatchEvent(new Event('change'));
            document.getElementById('editAccountCashEquivalent').checked = !!account.is_cash_equivalent;
            document.getElementById('editAccountActive').checked = !!account.is_active;
            document.getElementById('editAccountDescription').value = account.description || '';

            var codeInput = document.getElementById('editAccountCode');
            var typeInput = document.getElementById('editAccountType');
            var normalBalanceInput = document.getElementById('editAccountNormalBalance');
            codeInput.value = account.code;
            typeInput.value = account.type;
            normalBalanceInput.value = account.normal_balance;
            // Locked once real (non-trial) journal lines exist against this
            // account — same rule the server enforces in update(); disabled
            // fields never get submitted at all, matching that a locked
            // account's code/type/normal_balance aren't in the request rules.
            var locked = !!account.has_history;
            codeInput.disabled = locked;
            typeInput.disabled = locked;
            normalBalanceInput.disabled = locked;
            document.getElementById('editAccountLockedNote').classList.toggle('hidden', !locked);

            document.getElementById('editAccountModal').classList.remove('hidden');
        }
        function closeAccountModal() {
            document.getElementById('editAccountModal').classList.add('hidden');
        }
        document.getElementById('editAccountModal').addEventListener('click', function (e) {
            if (e.target === this) closeAccountModal();
        });
    </script>
    @endif

    @include('partials.accounting-help-fab', ['tour' => 'module', 'step' => 11, 'endStep' => 12])
</x-app-layout>
