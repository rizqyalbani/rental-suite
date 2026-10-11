<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            General Ledger
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            @include('partials.accounting-trial-banner')

            <a href="{{ route('accounting.dashboard') }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">&larr; Back to Dashboard</a>

            <div class="flex items-center justify-between mt-4 mb-6 flex-wrap gap-3">
                <form method="GET" id="glFilterForm" class="flex items-center gap-2 text-sm flex-wrap">
                    <div class="relative">
                        <input type="text" name="search" id="glSearchInput" value="{{ $search }}" placeholder="Search customer name or #rental…"
                            class="form-input text-sm w-56 pr-8" autocomplete="off">
                        <svg id="glSearchSpinner" class="hidden animate-spin absolute right-2 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </div>
                    <select name="account_id" class="form-select text-sm" data-searchable-select data-searchable-placeholder="All accounts...">
                        <option value="">All accounts</option>
                        @foreach($accounts as $account)
                            <option value="{{ $account->id }}" {{ (int) $accountId === $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                        @endforeach
                    </select>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-input text-sm" {{ $search !== '' ? 'disabled' : '' }}>
                    <span class="text-gray-400">to</span>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-input text-sm" {{ $search !== '' ? 'disabled' : '' }}>
                    <button type="submit" class="px-3 py-2 bg-gray-900 text-white rounded-md text-sm hover:bg-black cursor-pointer">Filter</button>
                    @if($search !== '' || $accountId)
                        <a href="{{ route('accounting.reports.general-ledger') }}" class="text-xs text-gray-400 hover:text-gray-600">Reset</a>
                    @endif
                </form>
                <p class="text-xs text-gray-400 w-full {{ $search === '' ? 'hidden' : '' }}" id="glSearchDateNote">
                    Searching for "{{ $search }}": the date range is ignored and all periods are searched.
                </p>
            </div>

            <div id="generalLedgerContent" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900">General Ledger ({{ $storeLabel }})</h3>
                    </div>
                    <div id="glResults">
                        @include('accounting.reports.partials.general-ledger-rows')
                    </div>
                </div>
            </div>

            <script>
            (function () {
                var input = document.getElementById('glSearchInput');
                var form = document.getElementById('glFilterForm');
                var spinner = document.getElementById('glSearchSpinner');
                var results = document.getElementById('glResults');
                var dateNote = document.getElementById('glSearchDateNote');
                if (!input || !form || !results) return;

                var timer = null;
                var activeController = null;

                // Carries whatever the OTHER filters (account/date) currently
                // hold — a live search shouldn't silently drop them.
                function buildUrl(extra) {
                    var url = new URL(form.action || window.location.href, window.location.origin);
                    var params = new URLSearchParams(new FormData(form));
                    if (extra) {
                        Object.keys(extra).forEach(function (k) { params.set(k, extra[k]); });
                    }
                    url.search = params.toString();
                    return url;
                }

                function load(url, pushState) {
                    if (activeController) activeController.abort();
                    activeController = new AbortController();
                    spinner.classList.remove('hidden');
                    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: activeController.signal })
                        .then(function (res) { return res.text(); })
                        .then(function (html) {
                            results.innerHTML = html;
                            if (pushState) window.history.pushState({}, '', url);
                            var q = new URLSearchParams(url.search).get('search') || '';
                            if (dateNote) dateNote.classList.toggle('hidden', q === '');
                        })
                        .catch(function (err) {
                            if (err.name !== 'AbortError') {
                                results.innerHTML = '<p class="text-sm text-red-500 p-4">Failed to load, please try again.</p>';
                            }
                        })
                        .finally(function () {
                            spinner.classList.add('hidden');
                        });
                }

                input.addEventListener('input', function () {
                    clearTimeout(timer);
                    timer = setTimeout(function () { load(buildUrl(), true); }, 400);
                });

                // Only pagination links (inside <nav>) get the live swap —
                // the "#1234" journal links in each row are real navigation
                // to a different page and must NOT be intercepted here.
                results.addEventListener('click', function (e) {
                    var link = e.target.closest('a');
                    if (!link || !link.closest('nav')) return;
                    e.preventDefault();
                    load(new URL(link.href), true);
                });

                window.addEventListener('popstate', function () {
                    load(new URL(window.location.href), false);
                });
            })();
            </script>
        </div>
    </div>

    <script src="{{ asset('js/searchable-select.js') }}?v={{ filemtime(public_path('js/searchable-select.js')) }}"></script>
    @include('partials.accounting-help-fab', ['tour' => 'reports', 'step' => 9, 'endStep' => 11])
</x-app-layout>
