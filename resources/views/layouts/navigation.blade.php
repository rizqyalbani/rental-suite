<nav x-data="{ open: false }">
    @php
        $linkBase = 'flex items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition';
        $linkActive = 'bg-indigo-100 text-indigo-900';
        $linkIdle = 'text-gray-700 hover:bg-gray-100';
        $isMgmt = Auth::user()->isManagement() || Auth::user()->isSuperAdmin();
        $mayApproveItemRemoval = Auth::user()->mayApproveItemRemoval();
    @endphp

    <aside class="hidden md:flex md:fixed md:inset-y-0 md:left-0 md:w-64 md:flex-col border-r border-gray-200 bg-white">
        <div class="px-4 py-4 border-b border-gray-200">
            <a href="{{ route('dashboard') }}" class="text-xl font-bold text-gray-800">Rental Suite</a>
            <p class="text-xs text-gray-500 mt-1">
                {{ Auth::user()->isSuperAdmin() ? 'Super Admin' : (Auth::user()->isManagement() ? 'Management' : (Auth::user()->isSupervisor() ? 'Supervisor' : (Auth::user()->isAccounting() ? 'Accounting' : 'Admin'))) }}
            </p>
        </div>

        <div class="p-3 space-y-1 overflow-y-auto">
            <a href="{{ route('dashboard') }}" class="{{ $linkBase }} {{ request()->routeIs('dashboard') ? $linkActive : $linkIdle }}">Dashboard</a>
            <a id="rentalTourNavRents" href="{{ route('rentals.index') }}" class="{{ $linkBase }} {{ request()->routeIs('rentals.*') ? $linkActive : $linkIdle }}">
                <span>Rents</span>
                @if($isMgmt && ($pendingRentalApprovalsCount ?? 0) > 0)
                    <span data-pending-approvals-badge class="inline-flex min-w-[1.25rem] justify-center rounded-md border border-amber-400 bg-amber-100 px-1.5 py-0.5 text-xs font-bold leading-none text-amber-900">{{ $pendingRentalApprovalsCount }}</span>
                @endif
            </a>
            @php
                $mayAccounting = Auth::user()->mayViewAccountingDashboard() || Auth::user()->mayManageChartOfAccounts() || Auth::user()->mayRecordAccountingEntries();
                $accountingActive = request()->routeIs('accounting.*') || request()->routeIs('accounts.*') || request()->routeIs('expenses.*') || request()->routeIs('cash-movements.*');
                $inventoryActive = request()->routeIs('outfits.*') || request()->routeIs('stock-opname.*');
            @endphp
            <div x-data="{ inventoryOpen: {{ $inventoryActive ? 'true' : 'false' }} }">
                <button type="button" id="rentalTourNavInventoryToggle" @click="inventoryOpen = !inventoryOpen"
                    class="{{ $linkBase }} w-full cursor-pointer {{ $inventoryActive ? $linkActive : $linkIdle }}">
                    <span>Inventory</span>
                    <svg class="h-4 w-4 transition-transform shrink-0" :class="{ 'rotate-90': inventoryOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
                <div x-show="inventoryOpen" x-cloak style="display:none;" class="pl-3 mt-1 space-y-1">
                    <a href="{{ route('outfits.index') }}" class="{{ $linkBase }} {{ request()->routeIs('outfits.*') ? $linkActive : $linkIdle }}">Items</a>
                    @if(Auth::user()->mayParticipateInStockOpname())
                        <a id="rentalTourNavStockOpname" href="{{ route('stock-opname.index') }}" class="{{ $linkBase }} {{ request()->routeIs('stock-opname.*') ? $linkActive : $linkIdle }}">Stock Opname</a>
                    @endif
                </div>
            </div>
            @if($mayAccounting)
                <div x-data="{ accountingOpen: {{ $accountingActive ? 'true' : 'false' }} }">
                    <button type="button" id="rentalTourNavAccountingToggle" @click="accountingOpen = !accountingOpen"
                        class="{{ $linkBase }} w-full cursor-pointer {{ $accountingActive ? $linkActive : $linkIdle }}">
                        <span>Accounting</span>
                        <svg class="h-4 w-4 transition-transform shrink-0" :class="{ 'rotate-90': accountingOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                    <div x-show="accountingOpen" x-cloak style="display:none;" class="pl-3 mt-1 space-y-1">
                        @if(Auth::user()->mayViewAccountingDashboard())
                            <a id="rentalTourNavAccountingDashboard" href="{{ route('accounting.dashboard') }}" class="{{ $linkBase }} {{ request()->routeIs('accounting.dashboard') ? $linkActive : $linkIdle }}">Dashboard</a>
                        @endif
                        @if(Auth::user()->mayViewChartOfAccounts())
                            <a id="rentalTourNavChartOfAccounts" href="{{ route('accounts.index') }}" class="{{ $linkBase }} {{ request()->routeIs('accounts.*') ? $linkActive : $linkIdle }}">Chart of Accounts</a>
                        @endif
                        @if(Auth::user()->mayRecordAccountingEntries())
                            <a id="rentalTourNavExpenses" href="{{ route('expenses.index') }}" class="{{ $linkBase }} {{ request()->routeIs('expenses.*') ? $linkActive : $linkIdle }}">Expenses</a>
                            <a id="rentalTourNavCashMovements" href="{{ route('cash-movements.index') }}" class="{{ $linkBase }} {{ request()->routeIs('cash-movements.*') ? $linkActive : $linkIdle }}">Cash In/Out</a>
                        @endif
                        @if(Auth::user()->mayViewAccountingDashboard())
                            <p class="px-3 mt-2 text-[10px] font-semibold uppercase tracking-wide text-gray-400">Reports</p>
                            <a id="rentalTourNavTrialBalance" href="{{ route('accounting.reports.trial-balance') }}" class="{{ $linkBase }} {{ request()->routeIs('accounting.reports.trial-balance') ? $linkActive : $linkIdle }}">Trial Balance</a>
                            <a id="rentalTourNavBalanceSheet" href="{{ route('accounting.reports.balance-sheet') }}" class="{{ $linkBase }} {{ request()->routeIs('accounting.reports.balance-sheet') ? $linkActive : $linkIdle }}">Balance Sheet</a>
                            <a id="rentalTourNavProfitLoss" href="{{ route('accounting.reports.profit-loss') }}" class="{{ $linkBase }} {{ request()->routeIs('accounting.reports.profit-loss') ? $linkActive : $linkIdle }}">Profit &amp; Loss</a>
                            <a id="rentalTourNavGeneralLedger" href="{{ route('accounting.reports.general-ledger') }}" class="{{ $linkBase }} {{ request()->routeIs('accounting.reports.general-ledger*') ? $linkActive : $linkIdle }}">General Ledger</a>
                            <a id="rentalTourNavFixedAssets" href="{{ route('accounting.reports.fixed-assets') }}" class="{{ $linkBase }} {{ request()->routeIs('accounting.reports.fixed-assets') ? $linkActive : $linkIdle }}">Fixed Assets</a>
                            <a id="rentalTourNavPpn" href="{{ route('accounting.reports.ppn') }}" class="{{ $linkBase }} {{ request()->routeIs('accounting.reports.ppn') ? $linkActive : $linkIdle }}">PPN (Preview)</a>
                            <a id="rentalTourNavPiutang" href="{{ route('accounting.reports.piutang') }}" class="{{ $linkBase }} {{ request()->routeIs('accounting.reports.piutang') ? $linkActive : $linkIdle }}">Receivables</a>
                        @endif
                    </div>
                </div>
            @endif
            @if($isMgmt)
                <a id="rentalTourNavUsers" href="{{ route('users.index') }}" class="{{ $linkBase }} {{ request()->routeIs('users.*') ? $linkActive : $linkIdle }}">Users</a>
            @endif
            <a id="rentalTourNavMyRequests" href="{{ route('my-requests.index') }}" class="{{ $linkBase }} {{ request()->routeIs('my-requests.*') ? $linkActive : $linkIdle }}">My Requests</a>
            @if($mayApproveItemRemoval)
                <a id="rentalTourNavEditRequests" href="{{ route('approvals.edit-requests.index') }}" class="{{ $linkBase }} {{ request()->routeIs('approvals.edit-requests.*') ? $linkActive : $linkIdle }}">
                    <span>Edit Requests</span>
                    @if(($pendingEditRequestsCount ?? 0) > 0)
                        <span data-pending-approvals-badge class="inline-flex min-w-[1.25rem] justify-center rounded-md border border-amber-400 bg-amber-100 px-1.5 py-0.5 text-xs font-bold leading-none text-amber-900">{{ $pendingEditRequestsCount }}</span>
                    @endif
                </a>
                <a id="rentalTourNavRemovalRequests" href="{{ route('approvals.item-removal.index') }}" class="{{ $linkBase }} {{ request()->routeIs('approvals.item-removal.*') ? $linkActive : $linkIdle }}">
                    <span>Removal Requests</span>
                    @if(($pendingItemRemovalCount ?? 0) > 0)
                        <span data-pending-approvals-badge class="inline-flex min-w-[1.25rem] justify-center rounded-md border border-amber-400 bg-amber-100 px-1.5 py-0.5 text-xs font-bold leading-none text-amber-900">{{ $pendingItemRemovalCount }}</span>
                    @endif
                </a>
            @endif
            <a id="rentalTourNavActivityLog" href="{{ route('rentals.activity-log.index') }}" class="{{ $linkBase }} {{ request()->routeIs('rentals.activity-log.*') ? $linkActive : $linkIdle }}">Activity Log</a>
            <a href="{{ route('help.index') }}" class="{{ $linkBase }} {{ request()->routeIs('help.*') ? $linkActive : $linkIdle }}">Help</a>
            @if(Auth::user()->isSuperAdmin())
                <a href="{{ route('db.explorer') }}" class="{{ $linkBase }} {{ request()->routeIs('db.*') ? $linkActive : $linkIdle }}">DB</a>
            @endif
            @if(!$isMgmt)
                <a href="{{ route('discounts.my-status') }}" class="{{ $linkBase }} {{ request()->routeIs('discounts.my-status') ? $linkActive : $linkIdle }}">My Discounts</a>
            @endif
            @if($isMgmt)
                <a id="rentalTourNavStores" href="{{ route('stores.index') }}" class="{{ $linkBase }} {{ request()->routeIs('stores.*') ? $linkActive : $linkIdle }}">Stores</a>
                <a href="{{ route('settings.index') }}" class="{{ $linkBase }} {{ request()->routeIs('settings.*') ? $linkActive : $linkIdle }}">Settings</a>
                <a href="{{ route('reports.manager') }}" class="{{ $linkBase }} {{ request()->routeIs('reports.*') ? $linkActive : $linkIdle }}">Reports</a>
                <a href="{{ route('discounts.index') }}" class="{{ $linkBase }} {{ request()->routeIs('discounts.index') ? $linkActive : $linkIdle }}">Discounts</a>
                <a href="{{ route('rentals.index', ['deleted' => 1]) }}" class="{{ $linkBase }} {{ request()->routeIs('rentals.index') && request()->boolean('deleted') ? $linkActive : $linkIdle }}">Deleted Rents</a>
            @endif
        </div>

        <div class="mt-auto p-3 border-t border-gray-200 space-y-2">
            @if(isset($availableStores) && $availableStores->count() > 1)
                <form id="storeSwitcherForm" method="POST" action="{{ route('stores.switch') }}">
                    @csrf
                    <input type="hidden" name="redirect_to" value="{{ request()->getRequestUri() }}">
                    <label class="block text-xs text-gray-500 mb-1">Store</label>
                    <select id="storeSwitcherSelect" name="store_id" onchange="this.form.submit()" class="w-full text-sm border-gray-300 rounded-md">
                        @foreach($availableStores as $store)
                            <option value="{{ $store->id }}" {{ (int) ($currentStoreId ?? 0) === (int) $store->id ? 'selected' : '' }}>
                                {{ $store->name }}
                            </option>
                        @endforeach
                    </select>
                </form>
            @endif
            <div class="text-sm">
                <p class="font-medium text-gray-900">{{ Auth::user()->name }}</p>
                <p class="text-gray-500">{{ Auth::user()->email }}</p>
            </div>
            <button type="button" data-pwa-install class="hidden w-full {{ $linkBase }} {{ $linkIdle }}">
                Install App
            </button>
            <a href="{{ route('profile.edit') }}" class="{{ $linkBase }} {{ request()->routeIs('profile.*') ? $linkActive : $linkIdle }}">Profile</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full text-left {{ $linkBase }} {{ $linkIdle }}">Log Out</button>
            </form>
        </div>
    </aside>

    <div class="md:hidden fixed top-0 left-0 right-0 z-50 h-14 border-b border-gray-200 bg-white flex items-center justify-between px-4">
        <button @click="open = true" class="inline-flex items-center justify-center rounded-md p-2 text-gray-600 hover:bg-gray-100" aria-label="Open menu">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>
        <a href="{{ route('dashboard') }}" class="text-lg font-bold text-gray-800">Rental Suite</a>
        @if(Auth::user()->mayParticipateInStockOpname())
            <button type="button" id="rentalGlobalScanBtn" class="inline-flex items-center justify-center rounded-md p-2 text-indigo-600 hover:bg-indigo-50" aria-label="Scan item QR">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM17 17h4v4h-4zM14 21h3M21 14h0"/></svg>
            </button>
        @else
            <div class="w-8"></div>
        @endif
    </div>

    <div x-show="open" x-cloak class="md:hidden fixed inset-0 z-[60]" style="display:none;">
        <div class="absolute inset-0 bg-black/40" @click="open = false"></div>
        <div class="absolute inset-y-0 left-0 w-72 bg-white shadow-xl p-3 overflow-y-auto">
            <div class="flex items-center justify-between mb-3">
                <p class="font-semibold text-gray-900">Menu</p>
                <button @click="open = false" class="rounded-md p-2 text-gray-600 hover:bg-gray-100" aria-label="Close menu">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="space-y-1">
                <a href="{{ route('dashboard') }}" class="{{ $linkBase }} {{ request()->routeIs('dashboard') ? $linkActive : $linkIdle }}">Dashboard</a>
                <a href="{{ route('rentals.index') }}" class="{{ $linkBase }} {{ request()->routeIs('rentals.*') ? $linkActive : $linkIdle }}">
                    <span>Rents</span>
                    @if($isMgmt && ($pendingRentalApprovalsCount ?? 0) > 0)
                        <span data-pending-approvals-badge class="inline-flex min-w-[1.25rem] justify-center rounded-md border border-amber-400 bg-amber-100 px-1.5 py-0.5 text-xs font-bold leading-none text-amber-900">{{ $pendingRentalApprovalsCount }}</span>
                    @endif
                </a>
                <div x-data="{ inventoryOpen: {{ $inventoryActive ? 'true' : 'false' }} }">
                    <button type="button" @click="inventoryOpen = !inventoryOpen"
                        class="{{ $linkBase }} w-full cursor-pointer {{ $inventoryActive ? $linkActive : $linkIdle }}">
                        <span>Inventory</span>
                        <svg class="h-4 w-4 transition-transform shrink-0" :class="{ 'rotate-90': inventoryOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                    <div x-show="inventoryOpen" x-cloak style="display:none;" class="pl-3 mt-1 space-y-1">
                        <a href="{{ route('outfits.index') }}" class="{{ $linkBase }} {{ request()->routeIs('outfits.*') ? $linkActive : $linkIdle }}">Items</a>
                        @if(Auth::user()->mayParticipateInStockOpname())
                            <a href="{{ route('stock-opname.index') }}" class="{{ $linkBase }} {{ request()->routeIs('stock-opname.*') ? $linkActive : $linkIdle }}">Stock Opname</a>
                        @endif
                    </div>
                </div>
                @if($mayAccounting)
                    <div x-data="{ accountingOpen: {{ $accountingActive ? 'true' : 'false' }} }">
                        <button type="button" @click="accountingOpen = !accountingOpen"
                            class="{{ $linkBase }} w-full cursor-pointer {{ $accountingActive ? $linkActive : $linkIdle }}">
                            <span>Accounting</span>
                            <svg class="h-4 w-4 transition-transform shrink-0" :class="{ 'rotate-90': accountingOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                        <div x-show="accountingOpen" x-cloak style="display:none;" class="pl-3 mt-1 space-y-1">
                            @if(Auth::user()->mayViewAccountingDashboard())
                                <a href="{{ route('accounting.dashboard') }}" class="{{ $linkBase }} {{ request()->routeIs('accounting.dashboard') ? $linkActive : $linkIdle }}">Dashboard</a>
                            @endif
                            @if(Auth::user()->mayViewChartOfAccounts())
                                <a href="{{ route('accounts.index') }}" class="{{ $linkBase }} {{ request()->routeIs('accounts.*') ? $linkActive : $linkIdle }}">Chart of Accounts</a>
                            @endif
                            @if(Auth::user()->mayRecordAccountingEntries())
                                <a href="{{ route('expenses.index') }}" class="{{ $linkBase }} {{ request()->routeIs('expenses.*') ? $linkActive : $linkIdle }}">Expenses</a>
                                <a href="{{ route('cash-movements.index') }}" class="{{ $linkBase }} {{ request()->routeIs('cash-movements.*') ? $linkActive : $linkIdle }}">Cash In/Out</a>
                            @endif
                            @if(Auth::user()->mayViewAccountingDashboard())
                                <p class="px-3 mt-2 text-[10px] font-semibold uppercase tracking-wide text-gray-400">Reports</p>
                                <a href="{{ route('accounting.reports.trial-balance') }}" class="{{ $linkBase }} {{ request()->routeIs('accounting.reports.trial-balance') ? $linkActive : $linkIdle }}">Trial Balance</a>
                                <a href="{{ route('accounting.reports.balance-sheet') }}" class="{{ $linkBase }} {{ request()->routeIs('accounting.reports.balance-sheet') ? $linkActive : $linkIdle }}">Balance Sheet</a>
                                <a href="{{ route('accounting.reports.profit-loss') }}" class="{{ $linkBase }} {{ request()->routeIs('accounting.reports.profit-loss') ? $linkActive : $linkIdle }}">Profit &amp; Loss</a>
                                <a href="{{ route('accounting.reports.general-ledger') }}" class="{{ $linkBase }} {{ request()->routeIs('accounting.reports.general-ledger*') ? $linkActive : $linkIdle }}">General Ledger</a>
                                <a href="{{ route('accounting.reports.fixed-assets') }}" class="{{ $linkBase }} {{ request()->routeIs('accounting.reports.fixed-assets') ? $linkActive : $linkIdle }}">Fixed Assets</a>
                                <a href="{{ route('accounting.reports.ppn') }}" class="{{ $linkBase }} {{ request()->routeIs('accounting.reports.ppn') ? $linkActive : $linkIdle }}">PPN (Preview)</a>
                                <a href="{{ route('accounting.reports.piutang') }}" class="{{ $linkBase }} {{ request()->routeIs('accounting.reports.piutang') ? $linkActive : $linkIdle }}">Receivables</a>
                            @endif
                        </div>
                    </div>
                @endif
                @if($isMgmt)
                    <a href="{{ route('users.index') }}" class="{{ $linkBase }} {{ request()->routeIs('users.*') ? $linkActive : $linkIdle }}">Users</a>
                @endif
                <a href="{{ route('my-requests.index') }}" class="{{ $linkBase }} {{ request()->routeIs('my-requests.*') ? $linkActive : $linkIdle }}">My Requests</a>
                @if($mayApproveItemRemoval)
                    <a href="{{ route('approvals.edit-requests.index') }}" class="{{ $linkBase }} {{ request()->routeIs('approvals.edit-requests.*') ? $linkActive : $linkIdle }}">
                        <span>Edit Requests</span>
                        @if(($pendingEditRequestsCount ?? 0) > 0)
                            <span data-pending-approvals-badge class="inline-flex min-w-[1.25rem] justify-center rounded-md border border-amber-400 bg-amber-100 px-1.5 py-0.5 text-xs font-bold leading-none text-amber-900">{{ $pendingEditRequestsCount }}</span>
                        @endif
                    </a>
                    <a href="{{ route('approvals.item-removal.index') }}" class="{{ $linkBase }} {{ request()->routeIs('approvals.item-removal.*') ? $linkActive : $linkIdle }}">
                        <span>Removal Requests</span>
                        @if(($pendingItemRemovalCount ?? 0) > 0)
                            <span data-pending-approvals-badge class="inline-flex min-w-[1.25rem] justify-center rounded-md border border-amber-400 bg-amber-100 px-1.5 py-0.5 text-xs font-bold leading-none text-amber-900">{{ $pendingItemRemovalCount }}</span>
                        @endif
                    </a>
                @endif
                <a href="{{ route('rentals.activity-log.index') }}" class="{{ $linkBase }} {{ request()->routeIs('rentals.activity-log.*') ? $linkActive : $linkIdle }}">Activity Log</a>
                <a href="{{ route('help.index') }}" class="{{ $linkBase }} {{ request()->routeIs('help.*') ? $linkActive : $linkIdle }}">Help</a>
                @if(Auth::user()->isSuperAdmin())
                    <a href="{{ route('db.explorer') }}" class="{{ $linkBase }} {{ request()->routeIs('db.*') ? $linkActive : $linkIdle }}">DB</a>
                @endif
                @if(!$isMgmt)
                    <a href="{{ route('discounts.my-status') }}" class="{{ $linkBase }} {{ request()->routeIs('discounts.my-status') ? $linkActive : $linkIdle }}">My Discounts</a>
                @endif
                @if($isMgmt)
                    <a href="{{ route('stores.index') }}" class="{{ $linkBase }} {{ request()->routeIs('stores.*') ? $linkActive : $linkIdle }}">Stores</a>
                    <a href="{{ route('settings.index') }}" class="{{ $linkBase }} {{ request()->routeIs('settings.*') ? $linkActive : $linkIdle }}">Settings</a>
                    <a href="{{ route('reports.manager') }}" class="{{ $linkBase }} {{ request()->routeIs('reports.*') ? $linkActive : $linkIdle }}">Reports</a>
                    <a href="{{ route('discounts.index') }}" class="{{ $linkBase }} {{ request()->routeIs('discounts.index') ? $linkActive : $linkIdle }}">Discounts</a>
                    <a href="{{ route('rentals.index', ['deleted' => 1]) }}" class="{{ $linkBase }} {{ request()->routeIs('rentals.index') && request()->boolean('deleted') ? $linkActive : $linkIdle }}">Deleted Rents</a>
                @endif
            </div>

            <div class="mt-4 pt-3 border-t border-gray-200 space-y-2">
                @if(isset($availableStores) && $availableStores->count() > 1)
                    <form method="POST" action="{{ route('stores.switch') }}">
                        @csrf
                        <input type="hidden" name="redirect_to" value="{{ request()->getRequestUri() }}">
                        <label class="block text-xs text-gray-500 mb-1">Store</label>
                        <select name="store_id" onchange="this.form.submit()" class="w-full text-sm border-gray-300 rounded-md">
                            @foreach($availableStores as $store)
                                <option value="{{ $store->id }}" {{ (int) ($currentStoreId ?? 0) === (int) $store->id ? 'selected' : '' }}>
                                    {{ $store->name }}
                                </option>
                            @endforeach
                        </select>
                    </form>
                @endif
                <button type="button" data-pwa-install class="hidden w-full {{ $linkBase }} {{ $linkIdle }}">
                    Install App
                </button>
                <a href="{{ route('profile.edit') }}" class="{{ $linkBase }} {{ request()->routeIs('profile.*') ? $linkActive : $linkIdle }}">Profile</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full text-left {{ $linkBase }} {{ $linkIdle }}">Log Out</button>
                </form>
            </div>
        </div>
    </div>

    @if(Auth::user()->mayParticipateInStockOpname())
        {{-- Global scanner, reachable from anywhere (not just the Stock
             Opname page) — unlike that page's own scanner, this one has no
             active-session context to auto-mark against, so a decode just
             navigates to the item's own page (now login/PIN-gated — see
             outfits/status.blade.php), the same as scanning the tag with any
             other camera app would. --}}
        <div id="rentalGlobalScanModal" class="hidden fixed inset-0 z-[210] bg-black/70 flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                    <p class="text-sm font-semibold text-gray-900">Scan Item QR</p>
                    <button type="button" id="rentalGlobalScanClose" class="text-gray-400 hover:text-gray-600 text-xl leading-none cursor-pointer">&times;</button>
                </div>
                <div class="relative bg-black aspect-square">
                    <video id="rentalGlobalScanVideo" class="w-full h-full object-cover" muted playsinline></video>
                </div>
                <div id="rentalGlobalScanStatus" class="px-4 py-3 text-sm text-gray-600 min-h-[3.5rem] flex items-center justify-center text-center leading-snug">
                    Point the camera at the QR code attached to the item.
                </div>
            </div>
        </div>

        <script>
        (function () {
            var btn = document.getElementById('rentalGlobalScanBtn');
            var modal = document.getElementById('rentalGlobalScanModal');
            var video = document.getElementById('rentalGlobalScanVideo');
            var status = document.getElementById('rentalGlobalScanStatus');
            var closeBtn = document.getElementById('rentalGlobalScanClose');
            if (!btn) return;
            var scanner = null;

            function setStatus(text, isError) {
                status.textContent = text;
                status.className = 'px-4 py-3 text-sm min-h-[3.5rem] flex items-center justify-center text-center leading-snug ' + (isError ? 'text-red-600' : 'text-gray-600');
            }

            function closeModal() {
                if (scanner) scanner.stop();
                modal.classList.add('hidden');
            }

            btn.addEventListener('click', function () {
                if (!window.QrScanner) {
                    window.Swal && window.Swal.fire({ icon: 'error', title: 'Scanner not ready', text: 'Please try again in a moment.' });
                    return;
                }
                modal.classList.remove('hidden');
                setStatus('Point the camera at the QR code attached to the item.', false);
                if (!scanner) {
                    scanner = new window.QrScanner(video, function (result) {
                        // The printed QR encodes an absolute .../i/{token} URL
                        // (see OutfitController::scanByToken) — this scanner has
                        // no session to mark against, so it just follows that
                        // link, same as any other camera app would.
                        var match = /^https?:\/\/[^/]+\/i\/[A-Za-z0-9]{32,64}(?:[/?#]|$)/.exec(result.data || '');
                        if (!match) {
                            setStatus('QR code not recognised: this is not an item QR.', true);
                            return;
                        }
                        scanner.stop();
                        setStatus('Item found, opening its page…', false);
                        window.location.href = result.data;
                    }, { highlightScanRegion: true, highlightCodeOutline: true });
                }
                scanner.start().catch(function () {
                    setStatus('Cannot access the camera: check the camera permission in your browser.', true);
                });
            });

            closeBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeModal();
            });
        })();
        </script>
    @endif
</nav>
