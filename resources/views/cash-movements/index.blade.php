<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Cash In/Out
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

            @php
                // Maps each preset scenario below to the real account IDs in THIS
                // install (codes are stable, IDs aren't) — resolved once here,
                // unconditionally (the script block at the bottom always needs it,
                // even when the form itself is hidden below because $cashAccounts
                // is empty) — so the JS never has to hardcode an id.
                $codeToAccountId = $counterAccounts->pluck('id', 'code');
            @endphp
            <div id="cashMovementAddSection" class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Record Cash In/Out</h3>
                    <p class="text-xs text-gray-500 mb-4">
                        A monthly deposit (till → bank) is a <strong>Cash Out</strong> with the cash account set to
                        your till and the counter account set to Bank.
                    </p>
                    @if($cashAccounts->isEmpty())
                        <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded p-3">
                            No Cash/Bank accounts are set up yet — add one on the
                            <a href="{{ route('accounts.index') }}" class="underline font-medium">Chart of Accounts</a> page first.
                        </p>
                    @else
                        <form method="POST" action="{{ route('cash-movements.store') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @csrf
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Scenario (optional, auto-fills)</label>
                                <select id="cashMovementScenario" class="form-select w-full">
                                    <option value="">— Other / fill in manually —</option>
                                    <option value="deposit_to_bank">Deposit cash to the bank</option>
                                    <option value="withdraw_from_bank">Withdraw from the bank to cash</option>
                                    <option value="owner_capital_in">Capital contributed by the owner</option>
                                    <option value="owner_drawing_out">Owner withdraws money</option>
                                    @if(Auth::user()->mayEditAccountingEntries())
                                        <option value="opening_balance_adjustment">Opening balance adjustment for Store Cash</option>
                                    @endif
                                </select>
                                <p class="text-xs text-gray-400 mt-1">Pick the closest match; the fields below fill in automatically and can still be edited if needed.</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                                <input type="date" name="movement_date" required class="form-input w-full" value="{{ old('movement_date', now()->toDateString()) }}">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Direction</label>
                                <select id="cashMovementDirectionInput" name="direction" required class="form-select w-full">
                                    <option value="in" {{ old('direction') == 'in' ? 'selected' : '' }}>Cash In</option>
                                    <option value="out" {{ old('direction') == 'out' ? 'selected' : '' }}>Cash Out</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Cash/Bank Account</label>
                                <select id="cashMovementCashAccountInput" name="cash_account_id" required class="form-select w-full" data-searchable-select data-searchable-placeholder="Search cash/bank account...">
                                    <option value="">— Select —</option>
                                    @foreach($cashAccounts as $account)
                                        <option value="{{ $account->id }}" {{ old('cash_account_id') == $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Counter Account</label>
                                <select id="cashMovementCounterAccountInput" name="counter_account_id" required class="form-select w-full" data-searchable-select data-searchable-placeholder="Search counter account...">
                                    <option value="">— Select —</option>
                                    @foreach($counterAccounts as $account)
                                        <option value="{{ $account->id }}" {{ old('counter_account_id') == $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rp</span>
                                    <input type="text" inputmode="decimal" id="cashMovementAmount" name="amount" required
                                        class="form-input w-full pl-9" placeholder="e.g. 500.000" value="{{ old('amount') }}">
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Description (optional)</label>
                                <input type="text" name="description" class="form-input w-full" value="{{ old('description') }}">
                            </div>
                            <div class="md:col-span-2">
                                <button type="submit" class="px-4 py-2 bg-indigo-600 rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer">Record Movement</button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>

            <div id="cashMovementHistorySection" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
                        <h3 class="text-lg font-medium text-gray-900">Movement History</h3>
                        <form method="GET" class="flex flex-wrap items-center gap-2 text-sm">
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
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Direction</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cash/Bank</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Counter Account</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Recorded By</th>
                                    @if(Auth::user()->mayEditAccountingEntries())
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($movements as $movement)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">{{ $movement->movement_date->format('d M Y') }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            @if($movement->direction === 'in')
                                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-emerald-100 text-emerald-800">In</span>
                                            @else
                                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Out</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">{{ $movement->cashAccount?->name }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">{{ $movement->counterAccount?->name }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-medium text-gray-900">Rp {{ number_format((float) $movement->amount, 0, ',', '.') }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                            {{ $movement->recordedBy?->name ?? '—' }}
                                            @if($movement->is_tutorial)
                                                <span class="ml-1 px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">Trial</span>
                                            @endif
                                        </td>
                                        @if(Auth::user()->mayEditAccountingEntries())
                                            @php
                                                $movementEditPayload = [
                                                    'id' => $movement->id,
                                                    'movement_date' => $movement->movement_date->toDateString(),
                                                    'direction' => $movement->direction,
                                                    'cash_account_id' => $movement->cash_account_id,
                                                    'counter_account_id' => $movement->counter_account_id,
                                                    'amount' => (float) $movement->amount,
                                                    'description' => $movement->description,
                                                ];
                                            @endphp
                                            <td class="px-4 py-3 whitespace-nowrap text-right text-sm">
                                                <button type="button" onclick='openEditCashMovement(@json($movementEditPayload))' class="text-indigo-600 hover:text-indigo-900 cursor-pointer">Edit</button>
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ Auth::user()->mayEditAccountingEntries() ? 7 : 6 }}" class="px-4 py-6 text-center text-sm text-gray-400">No cash movements recorded in this date range.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">
                        {{ $movements->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(Auth::user()->mayEditAccountingEntries())
        <div id="editCashMovementModal" class="hidden fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50 p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Edit Cash In/Out</h3>
                <p class="text-xs text-gray-500 mb-4">
                    Saving this corrects the ledger — it reverses the original entry and posts a new one for the
                    updated values, rather than silently changing the old one.
                </p>
                <form id="editCashMovementForm" method="POST">
                    @csrf @method('PUT')
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                        <input type="date" name="movement_date" id="editCashMovementDate" required class="form-input w-full">
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Direction</label>
                        <select name="direction" id="editCashMovementDirection" required class="form-select w-full">
                            <option value="in">Cash In</option>
                            <option value="out">Cash Out</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Cash/Bank Account</label>
                        <select name="cash_account_id" id="editCashMovementCashAccount" required class="form-select w-full" data-searchable-select data-searchable-placeholder="Search cash/bank account...">
                            @foreach($cashAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Counter Account</label>
                        <select name="counter_account_id" id="editCashMovementCounterAccount" required class="form-select w-full" data-searchable-select data-searchable-placeholder="Search counter account...">
                            @foreach($counterAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rp</span>
                            <input type="text" inputmode="decimal" id="editCashMovementAmount" name="amount" required class="form-input w-full pl-9">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <input type="text" name="description" id="editCashMovementDescription" class="form-input w-full">
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="closeEditCashMovementModal()" class="px-4 py-2 bg-gray-200 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-300 cursor-pointer">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer">Save Correction</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <script src="{{ asset('js/money-format.js') }}?v={{ filemtime(public_path('js/money-format.js')) }}"></script>
    <script src="{{ asset('js/searchable-select.js') }}?v={{ filemtime(public_path('js/searchable-select.js')) }}"></script>
    <script>
        bindMoneyThousandsInput(document.getElementById('cashMovementAmount'));

        (function () {
            var scenarioSelect = document.getElementById('cashMovementScenario');
            if (!scenarioSelect) return;

            // Account IDs vary per install (production/local have different ids for
            // the same account code) — resolved server-side into this map so the
            // scenario list below only ever refers to stable codes.
            var codeToId = @json($codeToAccountId->map(fn ($id) => (string) $id));

            // null on 'cash' or 'direction' means "leave whatever the admin already
            // picked" — those two genuinely vary per real-world case even within one
            // scenario (e.g. the owner might hand over cash OR deposit straight to
            // the bank), unlike the counter account, which the scenario always fixes.
            var SCENARIOS = {
                deposit_to_bank: { direction: 'out', cashCode: '1100', counterCode: '1200' },
                withdraw_from_bank: { direction: 'in', cashCode: '1100', counterCode: '1200' },
                owner_capital_in: { direction: 'in', cashCode: null, counterCode: '3100' },
                owner_drawing_out: { direction: 'out', cashCode: null, counterCode: '3200' },
                opening_balance_adjustment: { direction: null, cashCode: '1100', counterCode: '3250' }
            };

            var directionInput = document.getElementById('cashMovementDirectionInput');
            var cashAccountInput = document.getElementById('cashMovementCashAccountInput');
            var counterAccountInput = document.getElementById('cashMovementCounterAccountInput');

            scenarioSelect.addEventListener('change', function () {
                var scenario = SCENARIOS[scenarioSelect.value];
                if (!scenario) return;
                if (scenario.direction) directionInput.value = scenario.direction;
                // dispatchEvent('change') after each: cash/counter are wrapped
                // into a searchable combobox (searchable-select.js), whose
                // visible label only re-syncs on the select's own 'change'.
                if (scenario.cashCode && codeToId[scenario.cashCode]) {
                    cashAccountInput.value = codeToId[scenario.cashCode];
                    cashAccountInput.dispatchEvent(new Event('change'));
                }
                if (scenario.counterCode && codeToId[scenario.counterCode]) {
                    counterAccountInput.value = codeToId[scenario.counterCode];
                    counterAccountInput.dispatchEvent(new Event('change'));
                }
            });
        })();
        @if(Auth::user()->mayEditAccountingEntries())
            var editCashMovementAmountInput = document.getElementById('editCashMovementAmount');
            bindMoneyThousandsInput(editCashMovementAmountInput);

            function openEditCashMovement(movement) {
                document.getElementById('editCashMovementForm').action = '/cash-movements/' + movement.id;
                document.getElementById('editCashMovementDate').value = movement.movement_date;
                document.getElementById('editCashMovementDirection').value = movement.direction;
                // dispatchEvent('change') after each: these two are wrapped
                // into a searchable combobox (searchable-select.js), whose
                // visible label only re-syncs on the select's own 'change'.
                var editCashAccountEl = document.getElementById('editCashMovementCashAccount');
                editCashAccountEl.value = movement.cash_account_id;
                editCashAccountEl.dispatchEvent(new Event('change'));
                var editCounterAccountEl = document.getElementById('editCashMovementCounterAccount');
                editCounterAccountEl.value = movement.counter_account_id;
                editCounterAccountEl.dispatchEvent(new Event('change'));
                editCashMovementAmountInput.value = moneyFormatNum(movement.amount);
                document.getElementById('editCashMovementDescription').value = movement.description || '';
                document.getElementById('editCashMovementModal').classList.remove('hidden');
            }
            function closeEditCashMovementModal() {
                document.getElementById('editCashMovementModal').classList.add('hidden');
            }
            document.getElementById('editCashMovementModal').addEventListener('click', function (e) {
                if (e.target === this) closeEditCashMovementModal();
            });
        @endif
    </script>

    @php
    // Same role-shift as expenses/index.blade.php — see the comment there.
    $accHelpOffset = Auth::user()->mayViewAccountingDashboard() ? 0 : 9;
@endphp
@include('partials.accounting-help-fab', ['tour' => 'module', 'step' => 20 - $accHelpOffset, 'endStep' => 22 - $accHelpOffset])
</x-app-layout>
