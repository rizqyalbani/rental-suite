const el = (id) => document.getElementById(id);

function syncAge(iso) {
    const ms = Date.parse(String(iso || ''));
    if (!Number.isFinite(ms)) return '';
    const mins = Math.floor(Math.max(0, Date.now() - ms) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    return `${Math.floor(hrs / 24)}d ago`;
}

function setBadge(text, theme) {
    const b = el('offlineSyncIndicator');
    if (!b) return;
    b.textContent = text || '';
    b.classList.remove('hidden');
    b.className = `inline-flex w-fit justify-self-start md:justify-self-end text-[11px] leading-4 px-2 py-1 rounded-full border whitespace-nowrap ${theme}`;
}

function updateBadge() {
    const api = window.rentalOfflineRentals;
    const b = el('offlineSyncIndicator');
    if (!b) return;
    if (!api || typeof api.meta !== 'function') {
        b.classList.add('hidden');
        return;
    }
    const online = !(navigator && navigator.onLine === false);
    const swCtrl = !!(navigator.serviceWorker && navigator.serviceWorker.controller);
    Promise.all([
        api.meta('rentals_sync_in_progress'),
        api.meta('rentals_last_full_sync_at'),
        api.meta('rentals_full_sync_next_page'),
        api.meta('rentals_full_sync_last_page'),
        api.meta('rentals_full_sync_total'),
    ]).then(([syncingRaw, lastAt, nextPageRaw, lastPageRaw, totalRaw]) => {
        const syncing = syncingRaw === true || syncingRaw === 'true';
        const nextPage = parseInt(nextPageRaw, 10);
        const lastPage = parseInt(lastPageRaw, 10);
        const total = parseInt(totalRaw, 10);
        const parts = ['Offline DB'];
        if (syncing) {
            const done = Number.isFinite(nextPage) ? Math.max(0, nextPage - 1) : 0;
            const lp = Number.isFinite(lastPage) ? lastPage : 0;
            parts.push(`syncing${lp > 0 ? ` ${done}/${lp}` : ''}`);
            if (Number.isFinite(total) && total > 0) parts.push(`${total} rows`);
            if (!online) parts.push('offline');
            if (!swCtrl) parts.push('no-sw');
            setBadge(parts.join(' · '), 'border-amber-300 bg-amber-50 text-amber-950');
            return;
        }
        if (lastAt) {
            parts.push('ready');
            const age = syncAge(lastAt);
            if (age) parts.push(age);
            if (!online) parts.push('offline');
            if (!swCtrl) parts.push('no-sw');
            setBadge(parts.join(' · '), online ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-indigo-200 bg-indigo-50 text-indigo-900');
            return;
        }
        parts.push('not-synced');
        if (!online) parts.push('offline');
        if (!swCtrl) parts.push('no-sw');
        setBadge(parts.join(' · '), 'border-gray-300 bg-gray-50 text-gray-700');
    }).catch(() => {
        setBadge('Offline DB · unknown', 'border-gray-300 bg-gray-50 text-gray-700');
    });
}

function offlineOpts(page, allStoresMode) {
    const search = el('searchInput')?.value || '';
    const perPage = el('perPage')?.value || '10';
    const opts = { search, per_page: perPage, page };
    if (typeof window.rentalDateFrom === 'string' && window.rentalDateFrom) opts.date_from = window.rentalDateFrom;
    if (typeof window.rentalDateTo === 'string' && window.rentalDateTo) opts.date_to = window.rentalDateTo;
    const s = typeof window.currentStatus === 'string' ? window.currentStatus : '';
    if (s === 'overdue') opts.overdue = true;
    else if (s === 'pickup_waiting') opts.pickup_waiting = true;
    else if (s === 'pickup_done') opts.pickup_done = true;
    else if (s === 'payment_down_payment') opts.payment_kind = 'down_payment';
    else if (s === 'payment_paid_off') opts.payment_kind = 'full';
    else if (s) opts.status = s;
    if (allStoresMode) opts.all_stores = true;
    return opts;
}

function offlinePagination(meta) {
    const page = Number(meta?.current_page) || 1;
    const last = Number(meta?.last_page) || 1;
    if (last <= 1) return '';
    let start = Math.max(1, page - 2);
    let end = Math.min(last, page + 2);
    if (end - start < 4) {
        start = Math.max(1, Math.min(start, end - 4));
        end = Math.min(last, Math.max(end, start + 4));
    }
    const btn = (p, label, disabled, active) => `<button type="button" ${disabled ? 'disabled' : ''} onclick="loadRentalsOffline(${p})" class="px-3 py-1.5 text-sm rounded border ${disabled ? 'border-gray-200 text-gray-400 bg-gray-50 cursor-not-allowed' : (active ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white hover:bg-gray-50')}">${label}</button>`;
    let html = '<div class="flex flex-wrap gap-2 justify-end">';
    html += btn(page - 1, 'Prev', page <= 1, false);
    for (let p = start; p <= end; p += 1) html += btn(p, String(p), false, p === page);
    html += btn(page + 1, 'Next', page >= last, false);
    html += '</div>';
    return html;
}

function init() {
    const cards = el('rentalCards');
    const perPageEl = el('perPage');
    const searchEl = el('searchInput');
    const info = el('paginationInfo');
    const links = el('paginationLinks');
    const count = el('resultCount');
    if (!cards || !perPageEl || !searchEl || !info || !links || !count) return;

    const allStoresMode = !!window.allStoresMode;
    const deletedMode = !!window.rentalsDeletedMode;
    let page = 1;
    const urlPage = new URLSearchParams(window.location.search).get('page');
    if (urlPage) page = Math.max(1, parseInt(urlPage, 10) || 1);
    let offline = false;
    let timer = null;

    function renderEmpty() {
        cards.innerHTML = window.rentalsListEmptyHtml || '';
    }

    function loadOffline(nextPage) {
        if (nextPage !== undefined) page = nextPage;
        const api = window.rentalOfflineRentals;
        if (!api || typeof api.search !== 'function') {
            window.showRentalsListToast?.('Offline storage is not available in this browser.', 'error');
            return;
        }
        offline = true;
        const reqSeq = (window.__rentalRentalsListSeq = (window.__rentalRentalsListSeq || 0) + 1);
        api.search(offlineOpts(page, allStoresMode)).then((data) => {
            if (reqSeq !== window.__rentalRentalsListSeq) return;
            cards.innerHTML = '';
            const rows = Array.isArray(data.data) ? data.data : [];
            if (!rows.length) renderEmpty();
            else rows.forEach((r) => { cards.innerHTML += r?.card_html || ''; });
            window.syncOverdueDurationLabels?.();
            links.innerHTML = offlinePagination(data);
            const from = data.from || 0, to = data.to || 0, total = data.total || 0;
            info.textContent = total > 0 ? `Showing ${from}-${to} of ${total} (offline)` : 'Offline (no cached data yet)';
            count.textContent = total > 0 ? `(${total} results)` : '';
            updateBadge();
        }).catch(() => {
            window.showRentalsListToast?.('Offline search failed.', 'error');
        });
    }

    function loadOnline(nextPage) {
        if (nextPage !== undefined) page = nextPage;
        // Responses can land out of order when filters change quickly; only the
        // newest request (shared counter across online/offline loaders) may render.
        const reqSeq = (window.__rentalRentalsListSeq = (window.__rentalRentalsListSeq || 0) + 1);
        const perPage = perPageEl.value;
        const search = searchEl.value;
        const dbg = typeof window.rentalsDebugTodayQuery === 'function' ? window.rentalsDebugTodayQuery() : '';
        let url = `/rentals/search?per_page=${encodeURIComponent(perPage)}&page=${encodeURIComponent(page)}&_=${Date.now()}${dbg}`;
        if (allStoresMode) url += '&all_stores=1';
        if (deletedMode) url += '&deleted=1';
        if (search) url += `&search=${encodeURIComponent(search)}`;
        if (typeof window.rentalDateFrom === 'string' && window.rentalDateFrom) url += `&date_from=${encodeURIComponent(window.rentalDateFrom)}`;
        if (typeof window.rentalDateTo === 'string' && window.rentalDateTo) url += `&date_to=${encodeURIComponent(window.rentalDateTo)}`;
        if (typeof window.rentalCreatedOn === 'string' && window.rentalCreatedOn) url += `&created_on=${encodeURIComponent(window.rentalCreatedOn)}`;
        const s = typeof window.currentStatus === 'string' ? window.currentStatus : '';
        if (s === 'overdue') url += '&overdue=1';
        else if (s === 'pickup_waiting') url += '&pickup_waiting=1';
        else if (s === 'pickup_done') url += '&pickup_done=1';
        else if (s === 'payment_down_payment') url += '&payment_kind=down_payment';
        else if (s === 'payment_paid_off') url += '&payment_kind=full';
        else if (s) url += `&status=${encodeURIComponent(s)}`;
        if (window.__rentalTutorialMode) url += '&tutorial=1';

        fetch(url, { cache: 'no-store' })
            .then((r) => {
                if (!r.ok) throw new Error(String(r.status));
                return r.json();
            })
            .then((data) => {
                if (reqSeq !== window.__rentalRentalsListSeq) return;
                offline = false;
                cards.innerHTML = '';
                const pending = Array.isArray(data.pending_approval_rows) ? data.pending_approval_rows : [];
                pending.forEach((p) => { cards.innerHTML += p?.card_html || ''; });
                if ((data.data || []).length === 0 && pending.length === 0) renderEmpty();
                else if (deletedMode && typeof window.renderDeletedRentalCard === 'function') {
                    (data.data || []).forEach((r) => { cards.innerHTML += window.renderDeletedRentalCard(r); });
                } else (data.data || []).forEach((r) => { cards.innerHTML += r?.card_html || ''; });
                if (deletedMode) window.rentalsDeletedBindSelection?.();
                window.syncOverdueDurationLabels?.();
                links.innerHTML = data.pagination_links_html || '';
                const from = data.from || 0, to = data.to || 0, total = data.total || 0;
                info.textContent = total > 0 ? `Showing ${from}-${to} of ${total}` : '';
                count.textContent = total > 0 ? `(${total} results)` : '';
                window.rentalTryStartPendingApprovalPoll?.();
                // Deleted rows must never enter the offline cache used by the normal list.
                if (!deletedMode) window.rentalOfflineRentals?.upsertMany?.(Array.isArray(data.data) ? data.data : []);
                updateBadge();
                window.refreshShiftControl?.();
            })
            .catch(() => {
                if (reqSeq !== window.__rentalRentalsListSeq) return;
                // Offline cache holds only live rents, so it cannot serve the deleted list.
                if (!deletedMode && window.rentalOfflineRentals) {
                    loadOffline(page);
                    return;
                }
                cards.innerHTML = window.rentalsListLoadErrorHtml || '';
            });
    }

    function goToPage(p) {
        page = p;
        if (offline) loadOffline(p);
        else loadOnline();
    }

    function searchClearVisible() {
        const btn = el('searchInputClear');
        if (!btn) return;
        btn.classList.toggle('hidden', String(searchEl.value || '').trim().length < 1);
    }

    function clearSearch() {
        searchEl.value = '';
        searchClearVisible();
        clearTimeout(timer);
        if (offline) loadOffline(page);
        else loadOnline();
        searchEl.focus();
    }

    searchEl.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            if (offline) loadOffline(page);
            else loadOnline();
        }, 300);
        searchClearVisible();
    });
    searchEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(timer);
            if (offline) loadOffline(page);
            else loadOnline();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            clearSearch();
        }
    });
    el('searchInputClear')?.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        clearSearch();
    });
    perPageEl.addEventListener('change', () => {
        if (offline) loadOffline(1);
        else loadOnline(1);
    });

    window.loadRentals = loadOnline;
    window.loadRentalsOffline = loadOffline;
    window.goToPage = goToPage;

    updateBadge();
    setInterval(updateBadge, 3000);
    window.addEventListener('online', updateBadge);
    window.addEventListener('offline', updateBadge);

    const api = window.rentalOfflineRentals;
    if (api && typeof api.fullSync === 'function' && typeof api.meta === 'function') {
        api.meta('rentals_last_full_sync_at').then((lastAt) => {
            const ms = Date.parse(String(lastAt || ''));
            const should = !Number.isFinite(ms) || (Date.now() - ms) > (10 * 60 * 1000);
            if (!should) return;
            window.showRentalsListToast?.('Syncing offline rentals…', 'warning');
            api.fullSync({
                all_stores: allStoresMode,
                debug_today_query: typeof window.rentalsDebugTodayQuery === 'function' ? window.rentalsDebugTodayQuery() : '',
                onProgress: updateBadge,
            }).then((res) => {
                updateBadge();
                if (res?.ok) {
                    window.showRentalsListToast?.(`Offline rentals updated. ${res.upserted || 0} rows cached.`, 'success');
                    if (offline) loadOffline(page);
                } else {
                    window.showRentalsListToast?.('Offline sync failed (server unreachable).', 'warning');
                }
            }).catch(() => {
                updateBadge();
                window.showRentalsListToast?.('Offline sync failed (server unreachable).', 'warning');
            });
        });
    }

    searchClearVisible();
    loadOnline(page);
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
