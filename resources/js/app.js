import './bootstrap';

import Alpine from 'alpinejs';
import Swal from 'sweetalert2';
import QrScanner from 'qr-scanner';
import QrScannerWorkerPath from 'qr-scanner/qr-scanner-worker.min.js?url';

window.Alpine = Alpine;
window.Swal = Swal;

// ?url import above makes Vite emit the worker as its own built asset and
// hands back its final URL — qr-scanner loads it via a dynamic import at
// scan time, so this has to point at the real built path, not the raw
// node_modules source path.
QrScanner.WORKER_PATH = QrScannerWorkerPath;
window.QrScanner = QrScanner;

Alpine.start();

function rentalDebug(hypothesisId, msg, data) {
    const url = window.__RENTAL_DEBUG_SERVER_URL;
    if (!url) {
        return;
    }
    try {
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                hypothesisId,
                location: 'resources/js/app.js',
                msg: String(msg || ''),
                data: data || {},
                ts: Date.now(),
            }),
        }).catch(() => {});
    } catch {}
}

function rentalRegisterServiceWorker() {
    if (!('serviceWorker' in navigator)) {
        return;
    }
    window.addEventListener('load', () => {
        // #region debug-point C:sw-register
        rentalDebug('C', 'sw register start', { href: window.location.href });
        // #endregion
        navigator.serviceWorker.register('/sw.js')
            .then((reg) => {
                // #region debug-point C:sw-register-ok
                const ctrl = !!navigator.serviceWorker.controller;
                rentalDebug('C', 'sw register ok', {
                    scope: reg?.scope ?? null,
                    hasController: ctrl,
                    controllerState: navigator.serviceWorker.controller?.state ?? null,
                });
                // #endregion
            })
            .catch((err) => {
                // #region debug-point C:sw-register-fail
                rentalDebug('C', 'sw register failed', { err: String(err?.message ?? err) });
                // #endregion
            });
    });
}

function rentalBindPwaInstallPrompt() {
    let deferredPrompt = null;
    const buttons = () => Array.from(document.querySelectorAll('[data-pwa-install]'));

    function setButtonsVisible(visible) {
        buttons().forEach((btn) => btn.classList.toggle('hidden', !visible));
    }

    setButtonsVisible(false);

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        setButtonsVisible(true);
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        setButtonsVisible(false);
    });

    document.addEventListener('click', (e) => {
        const btn = e.target?.closest?.('[data-pwa-install]');
        if (!btn || !deferredPrompt) {
            return;
        }
        deferredPrompt.prompt();
        deferredPrompt.userChoice.finally(() => {
            deferredPrompt = null;
            setButtonsVisible(false);
        });
    });
}

rentalRegisterServiceWorker();
rentalBindPwaInstallPrompt();

function rentalResolveElement(ref) {
    if (!ref) {
        return null;
    }
    if (typeof ref === 'string') {
        return document.getElementById(ref);
    }
    return ref;
}

function rentalBindSearchField(options = {}) {
    const inputEl = rentalResolveElement(options.input);
    const clearButtonEl = rentalResolveElement(options.clearButton);

    if (!inputEl) {
        return null;
    }

    const syncClearVisibility = () => {
        if (!clearButtonEl) {
            return;
        }
        const value = String(inputEl.value || '').trim();
        const show = !inputEl.disabled && value.length > 0;
        clearButtonEl.classList.toggle('hidden', !show);
    };

    const clearValue = () => {
        inputEl.value = '';
        syncClearVisibility();
        if (typeof options.onClear === 'function') {
            options.onClear();
        }
        if (!inputEl.disabled && options.focusOnClear !== false) {
            inputEl.focus();
        }
    };

    inputEl.addEventListener('input', (event) => {
        syncClearVisibility();
        if (typeof options.onInput === 'function') {
            options.onInput(event);
        }
    });

    inputEl.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && options.preventEnter !== false) {
            event.preventDefault();
            if (typeof options.onEnter === 'function') {
                options.onEnter(event);
            }
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            if (typeof options.onEscape === 'function') {
                options.onEscape(event);
                return;
            }
            clearValue();
        }
    });

    if (clearButtonEl) {
        clearButtonEl.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            clearValue();
        });
    }

    syncClearVisibility();
    return { inputEl, clearButtonEl, syncClearVisibility, clearValue };
}

window.rentalBindSearchField = rentalBindSearchField;

function rentalEscapeHtml(text) {
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

window.rentalEscapeHtml = rentalEscapeHtml;

function rentalAlert(message, opts = {}) {
    const text = message != null ? String(message) : '';
    const icon = opts.icon || 'info';
    const lines = text.split('\n').map((s) => s.trim()).filter(Boolean);
    const showDangerList = (icon === 'error') && (opts.asList || lines.length > 1);
    if (showDangerList) {
        return window.Swal.fire({
            icon,
            title: opts.title || 'Notice',
            html: `<ul style="text-align:left;color:#b91c1c;margin:.25rem 0 0 0;padding-left:1.25rem;">${lines.map((line) => `<li style="margin:.2rem 0;">${rentalEscapeHtml(line)}</li>`).join('')}</ul>`,
            confirmButtonText: opts.confirmButtonText || 'OK',
        });
    }
    return window.Swal.fire({
        icon,
        title: opts.title || 'Notice',
        text,
        confirmButtonText: opts.confirmButtonText || 'OK',
    });
}

window.rentalAlert = rentalAlert;

function rentalConfirm(message, opts = {}) {
    const text = message != null ? String(message) : '';
    return window.Swal.fire({
        icon: opts.icon || 'warning',
        title: opts.title || 'Are you sure?',
        text,
        showCancelButton: true,
        confirmButtonText: opts.confirmButtonText || 'Yes',
        cancelButtonText: opts.cancelButtonText || 'Cancel',
        reverseButtons: true,
        focusCancel: true,
    }).then((res) => !!res.isConfirmed);
}

window.rentalConfirm = rentalConfirm;

function rentalConfirmSubmit(event, message, opts = {}) {
    if (!event) {
        return false;
    }
    event.preventDefault();
    const form = event.target;
    if (!form || typeof form.submit !== 'function') {
        return false;
    }
    window.rentalConfirm(message, opts).then((ok) => {
        if (ok) {
            form.submit();
        }
    });
    return false;
}

window.rentalConfirmSubmit = rentalConfirmSubmit;

/**
 * Cool-down approval forms: one fetch per submit, then drop the card from the DOM (no polling).
 */
function rentalCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function rentalApprovalActionPath(action) {
    try {
        return new URL(action, window.location.origin).pathname;
    } catch {
        return '';
    }
}

/** Batches rapid approve/reject into one /rentals/search refresh (same tab). */
let rentalLoadRentalsDebounceTimer = null;

function rentalScheduleLoadRentals() {
    clearTimeout(rentalLoadRentalsDebounceTimer);
    rentalLoadRentalsDebounceTimer = setTimeout(() => {
        rentalLoadRentalsDebounceTimer = null;
        if (typeof window.loadRentals === 'function') {
            window.loadRentals();
        }
    }, 320);
}

function rentalCleanupEmptyApprovalRegions() {
    ['manager-pending-approvals-inline', 'manager-pending-approvals-dock'].forEach((id) => {
        const root = document.getElementById(id);
        if (!root) {
            return;
        }
        root.querySelectorAll('[data-approval-queue-footer]').forEach((el) => el.remove());
        if (root.querySelectorAll('[data-approval-card]').length === 0) {
            // The dock persists (hidden) so the live poll can reuse it when new requests arrive.
            if (id === 'manager-pending-approvals-dock') {
                root.classList.add('hidden');
            } else {
                root.remove();
            }
        }
    });
}

document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }
    const action = form.getAttribute('action') ?? '';
    const path = rentalApprovalActionPath(action);
    if (!/\/approvals\/(rentals|item-removal)\/\d+\/(approve|reject)$/.test(path)) {
        return;
    }
    e.preventDefault();
    const isReject = path.endsWith('/reject');
    const proceed = window.rentalConfirm(
        form.dataset.confirmMessage
            || (isReject ? 'The staff request will be rejected.' : 'This will apply the request immediately.'),
        {
            title: form.dataset.confirmTitle || (isReject ? 'Reject this request?' : 'Approve this request?'),
            icon: form.dataset.confirmIcon || (isReject ? 'warning' : 'question'),
            confirmButtonText: form.dataset.confirmButton || (isReject ? 'Yes, reject' : 'Yes, approve'),
            cancelButtonText: 'Cancel',
        },
    );
    proceed.then((ok) => {
        if (!ok) {
            return;
        }
        const fd = new FormData(form);
        fetch(action, {
            method: 'POST',
            body: fd,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': rentalCsrfToken(),
            },
            credentials: 'same-origin',
        })
            .then((r) => r.json().then((data) => ({ r, data })))
            .then(({ r, data }) => {
                if (!r.ok) {
                    window.rentalAlert(data?.message ?? 'Request failed.', { icon: 'error', title: 'Request failed' });
                    return;
                }
                if (data?.ok && data.approval_id != null) {
                    document.querySelectorAll(`[data-approval-card="${data.approval_id}"]`).forEach((el) => el.remove());
                    document.querySelectorAll(`[data-pending-approval-list-row="${data.approval_id}"]`).forEach((el) => el.remove());
                    document.querySelectorAll('[data-pending-approvals-badge]').forEach((el) => {
                        const n = parseInt(el.textContent.trim(), 10);
                        if (Number.isNaN(n)) {
                            return;
                        }
                        const next = Math.max(0, n - 1);
                        if (next < 1) {
                            el.remove();
                        } else {
                            el.textContent = String(next);
                        }
                    });
                    rentalCleanupEmptyApprovalRegions();
                    // The Edit Requests / Removal Requests pages show their own "N pending"
                    // header + tab-label counters, separate from the sidebar nav badges above.
                    const headerCount = document.getElementById('editRequestsPendingHeaderCount');
                    if (headerCount) {
                        const n = parseInt(headerCount.textContent, 10);
                        if (!Number.isNaN(n)) {
                            headerCount.textContent = `${Math.max(0, n - 1)} pending`;
                        }
                    }
                    const tabCount = document.getElementById('editRequestsPendingTabCount');
                    if (tabCount) {
                        const n = parseInt(tabCount.textContent.replace(/[^\d-]/g, ''), 10);
                        if (!Number.isNaN(n)) {
                            tabCount.textContent = `(${Math.max(0, n - 1)})`;
                        }
                    }
                    document.dispatchEvent(new CustomEvent('rental:approval-changed', { detail: data }));
                    // Debounced: multiple quick decisions → one list fetch (lighter on server).
                    rentalScheduleLoadRentals();
                }
            })
            .catch(() => window.rentalAlert('Network error.', { icon: 'error', title: 'Network error' }));
    });
}, false);

function rentalOpenOfflineDb() {
    if (!('indexedDB' in window)) {
        return Promise.resolve(null);
    }
    return new Promise((resolve) => {
        const req = indexedDB.open('rental_offline', 1);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains('rentals')) {
                db.createObjectStore('rentals', { keyPath: 'id' });
            }
            if (!db.objectStoreNames.contains('meta')) {
                db.createObjectStore('meta', { keyPath: 'key' });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => resolve(null);
    });
}

function rentalDbTxn(db, storeNames, mode, run) {
    if (!db) return Promise.resolve(null);
    return new Promise((resolve) => {
        const tx = db.transaction(storeNames, mode);
        const stores = Array.isArray(storeNames) ? storeNames.map((name) => tx.objectStore(name)) : [tx.objectStore(storeNames)];
        let out = null;
        try {
            out = run(tx, ...stores);
        } catch {
            resolve(null);
            return;
        }
        tx.oncomplete = () => resolve(out);
        tx.onerror = () => resolve(null);
        tx.onabort = () => resolve(null);
    });
}

function rentalDbGet(db, store, key) {
    return rentalDbTxn(db, store, 'readonly', (tx, s) => new Promise((resolve) => {
        const req = s.get(key);
        req.onsuccess = () => resolve(req.result ?? null);
        req.onerror = () => resolve(null);
    }));
}

function rentalDbPut(db, store, value) {
    return rentalDbTxn(db, store, 'readwrite', (tx, s) => new Promise((resolve) => {
        const req = s.put(value);
        req.onsuccess = () => resolve(true);
        req.onerror = () => resolve(false);
    }));
}

function rentalDbGetAll(db, store) {
    return rentalDbTxn(db, store, 'readonly', (tx, s) => new Promise((resolve) => {
        if (typeof s.getAll === 'function') {
            const req = s.getAll();
            req.onsuccess = () => resolve(Array.isArray(req.result) ? req.result : []);
            req.onerror = () => resolve([]);
            return;
        }
        const out = [];
        const cursorReq = s.openCursor();
        cursorReq.onsuccess = () => {
            const cursor = cursorReq.result;
            if (!cursor) {
                resolve(out);
                return;
            }
            out.push(cursor.value);
            cursor.continue();
        };
        cursorReq.onerror = () => resolve(out);
    }));
}

function rentalRentalSearchBlob(r) {
    const parts = [];
    const push = (v) => {
        if (v == null) return;
        const s = String(v).trim();
        if (!s) return;
        parts.push(s);
    };
    push(r.customer_name);
    push(r.phone);
    push(r.instagram);
    push(r.notes ?? r.address);
    push(r.status);
    push(r.start_date);
    push(r.end_date);
    push(r.start_label);
    push(r.end_label);
    push(r.outfit_list);
    if (Array.isArray(r.outfits)) {
        r.outfits.forEach((s) => {
            if (!s) return;
            push(s.code);
            push(s.new_code);
            push(s.description);
        });
    }
    return parts.join(' ').toLowerCase();
}

function rentalNormalizeYmd(v) {
    const s = String(v ?? '');
    return /^\d{4}-\d{2}-\d{2}$/.test(s) ? s : '';
}

function rentalDateInRange(ymd, fromYmd, toYmd) {
    const d = rentalNormalizeYmd(ymd);
    if (!d) return true;
    const f = rentalNormalizeYmd(fromYmd);
    const t = rentalNormalizeYmd(toYmd);
    if (f && d < f) return false;
    if (t && d > t) return false;
    return true;
}

async function rentalOfflineRentalsUpsertMany(rentals) {
    const db = await rentalOpenOfflineDb();
    if (!db) return { ok: false, reason: 'no_db' };
    const list = Array.isArray(rentals) ? rentals : [];
    await rentalDbTxn(db, ['rentals', 'meta'], 'readwrite', (tx, rentalsStore, metaStore) => {
        list.forEach((raw) => {
            const id = raw?.id != null ? Number(raw.id) : null;
            if (!Number.isFinite(id)) return;
            const row = {
                id,
                rental: raw,
                blob: rentalRentalSearchBlob(raw),
                updated_at: new Date().toISOString(),
            };
            rentalsStore.put(row);
        });
        metaStore.put({ key: 'rentals_last_upsert_at', value: new Date().toISOString() });
    });
    return { ok: true, count: list.length };
}

async function rentalOfflineRentalsAll() {
    const db = await rentalOpenOfflineDb();
    if (!db) return [];
    const rows = await rentalDbGetAll(db, 'rentals');
    return Array.isArray(rows) ? rows : [];
}

async function rentalOfflineRentalsMeta(key) {
    const db = await rentalOpenOfflineDb();
    if (!db) return null;
    const row = await rentalDbGet(db, 'meta', key);
    return row && typeof row === 'object' ? row.value ?? null : null;
}

async function rentalOfflineRentalsSetMeta(key, value) {
    const db = await rentalOpenOfflineDb();
    if (!db) return false;
    return rentalDbPut(db, 'meta', { key, value });
}

async function rentalOfflineRentalsSearch(opts = {}) {
    const q = String(opts.search ?? '').trim().toLowerCase();
    const status = String(opts.status ?? '').trim();
    const overdueOnly = !!opts.overdue;
    const pickupWaiting = !!opts.pickup_waiting;
    const pickupDone = !!opts.pickup_done;
    const paymentKind = String(opts.payment_kind ?? '').trim();
    const dateFrom = rentalNormalizeYmd(opts.date_from);
    const dateTo = rentalNormalizeYmd(opts.date_to);
    const perPage = Math.max(1, parseInt(opts.per_page ?? 10, 10) || 10);
    const page = Math.max(1, parseInt(opts.page ?? 1, 10) || 1);

    const rows = await rentalOfflineRentalsAll();
    let list = rows.map((x) => x?.rental).filter(Boolean);
    if (status) {
        list = list.filter((r) => String(r?.status ?? '') === status);
    }
    if (overdueOnly) {
        list = list.filter((r) => !!r?.overdue);
    }
    if (pickupWaiting) {
        list = list.filter((r) => String(r?.status ?? '') === 'active' && !r?.picked_up);
    }
    if (pickupDone) {
        list = list.filter((r) => String(r?.status ?? '') === 'active' && !!r?.picked_up);
    }
    if (paymentKind) {
        list = list.filter((r) => String(r?.payment_kind ?? '') === paymentKind);
    }
    if (dateFrom || dateTo) {
        list = list.filter((r) => {
            const sd = rentalNormalizeYmd(r?.start_date);
            const ed = rentalNormalizeYmd(r?.end_date);
            if (!rentalDateInRange(sd, dateFrom, dateTo) && !rentalDateInRange(ed, dateFrom, dateTo)) {
                if (dateFrom && ed && ed < dateFrom) return false;
                if (dateTo && sd && sd > dateTo) return false;
            }
            return true;
        });
    }
    if (q) {
        const match = (r) => {
            const blob = rentalRentalSearchBlob(r);
            return blob.includes(q);
        };
        list = list.filter(match);
    }

    list.sort((a, b) => {
        const aEnd = String(a?.end_date ?? '');
        const bEnd = String(b?.end_date ?? '');
        if (aEnd !== bEnd) return bEnd.localeCompare(aEnd);
        const aId = Number(a?.id ?? 0);
        const bId = Number(b?.id ?? 0);
        return bId - aId;
    });

    const total = list.length;
    const start = (page - 1) * perPage;
    const end = Math.min(total, start + perPage);
    const slice = start < total ? list.slice(start, end) : [];
    return {
        data: slice,
        total,
        from: total ? (start + 1) : 0,
        to: total ? end : 0,
        current_page: page,
        per_page: perPage,
        last_page: Math.max(1, Math.ceil(total / perPage)),
        offline: true,
    };
}

async function rentalOfflineRentalsFullSync(opts = {}) {
    const allowedPerPage = [10, 25, 50];
    const perPageRaw = parseInt(opts.per_page ?? 50, 10);
    const perPage = allowedPerPage.includes(perPageRaw) ? perPageRaw : 50;
    const baseUrl = String(opts.base_url ?? '/rentals/search');
    const allStores = opts.all_stores ? '&all_stores=1' : '';
    const debugToday = String(opts.debug_today_query ?? '');
    const onProgress = typeof opts.onProgress === 'function' ? opts.onProgress : null;

    let page = 1;
    let last = 1;
    let total = 0;
    let upserted = 0;

    await rentalOfflineRentalsSetMeta('rentals_sync_in_progress', true);
    await rentalOfflineRentalsSetMeta('rentals_sync_started_at', new Date().toISOString());
    await rentalOfflineRentalsSetMeta('rentals_full_sync_per_page', perPage);

    const resumeNextRaw = parseInt(await rentalOfflineRentalsMeta('rentals_full_sync_next_page'), 10);
    const resumeLastRaw = parseInt(await rentalOfflineRentalsMeta('rentals_full_sync_last_page'), 10);
    const resumeTotalRaw = parseInt(await rentalOfflineRentalsMeta('rentals_full_sync_total'), 10);
    const resumePerPageRaw = parseInt(await rentalOfflineRentalsMeta('rentals_full_sync_per_page'), 10);
    const shouldResume = Number.isFinite(resumeNextRaw) && resumeNextRaw > 1
        && Number.isFinite(resumeLastRaw) && resumeLastRaw >= 1
        && Number.isFinite(resumePerPageRaw) && resumePerPageRaw === perPage;

    if (shouldResume) {
        page = resumeNextRaw;
        last = resumeLastRaw;
        total = Number.isFinite(resumeTotalRaw) ? resumeTotalRaw : 0;
    } else {
        const url1 = `${baseUrl}?per_page=${perPage}&page=1${allStores}${debugToday}&_=${Date.now()}`;
        const res1 = await fetch(url1, { cache: 'no-store' }).then((r) => r.ok ? r.json() : null).catch(() => null);
        if (!res1 || !Array.isArray(res1.data)) {
            await rentalOfflineRentalsSetMeta('rentals_sync_in_progress', false);
            return { ok: false, upserted: 0 };
        }
        last = Math.max(1, parseInt(res1.last_page ?? 1, 10) || 1);
        total = Math.max(0, parseInt(res1.total ?? 0, 10) || 0);
        await rentalOfflineRentalsUpsertMany(res1.data);
        upserted += res1.data.length;
        await rentalOfflineRentalsSetMeta('rentals_full_sync_last_page', last);
        await rentalOfflineRentalsSetMeta('rentals_full_sync_total', total);
        await rentalOfflineRentalsSetMeta('rentals_full_sync_next_page', 2);
        if (onProgress) onProgress({ page: 1, last, total, upserted });
        page = 2;
    }

    while (page <= last) {
        const url = `${baseUrl}?per_page=${perPage}&page=${page}${allStores}${debugToday}&_=${Date.now()}`;
        const res = await fetch(url, { cache: 'no-store' }).then((r) => r.ok ? r.json() : null).catch(() => null);
        if (!res || !Array.isArray(res.data)) {
            break;
        }
        await rentalOfflineRentalsUpsertMany(res.data);
        upserted += res.data.length;
        await rentalOfflineRentalsSetMeta('rentals_full_sync_next_page', page + 1);
        if (onProgress) onProgress({ page, last, total, upserted });
        page += 1;
    }

    const ok = page > 1;
    if (ok) {
        await rentalOfflineRentalsSetMeta('rentals_last_full_sync_at', new Date().toISOString());
        await rentalOfflineRentalsSetMeta('rentals_full_sync_next_page', 1);
    }
    await rentalOfflineRentalsSetMeta('rentals_sync_in_progress', false);
    return { ok, upserted };
}

window.rentalOfflineRentals = {
    upsertMany: rentalOfflineRentalsUpsertMany,
    search: rentalOfflineRentalsSearch,
    all: rentalOfflineRentalsAll,
    meta: rentalOfflineRentalsMeta,
    fullSync: rentalOfflineRentalsFullSync,
};
