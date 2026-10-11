{{-- "System readiness test": a floating A-to-Z readiness checklist the owner runs after
     each upload. Started from Help (button data-start-readiness) or ?smoke=1,
     stopped with the x button or ?smoke=0.

     Data safety: this partial NEVER talks to the server and writes no table. The
     ticks, notes and caught errors live in this browser's localStorage only (per
     user). The "look only" steps read real data without changing it; the write
     steps go through the existing practice tours (Help page), whose records are
     tagged is_tutorial and kept out of every real list, ledger and shift. Anything
     that moves real money (Pay, Make free, Cool-down swap, date change, Approve on
     a real rent) is listed as sandbox-only and has no tick box.

     Error catch: while the checklist is ON it wraps fetch/XHR as a pass-through and
     listens for window errors, to log JS errors, HTTP 5xx/419 and network failures.
     A full-page server error (HTML error page) has no panel, so the owner marks the
     step "Failed" by hand. Nothing is patched while the checklist is OFF.

     Edit the lists below to change the checklist; progress of removed items is ignored. --}}
@if(Auth::check() && in_array(Auth::user()->normalizedRole(), ['admin', 'management', 'super_admin'], true))
@php
    $readinessItems = [
        ['id' => 'p1', 'group' => 'look', 'title' => 'Dashboard opens', 'how' => 'Open the Dashboard.', 'expect' => 'The page shows, with no red error box.', 'links' => [['Dashboard', url('/dashboard')]]],
        ['id' => 'p2', 'group' => 'look', 'title' => 'Rents page', 'how' => 'Open the Rents page, then press Ctrl+Shift+R.', 'expect' => 'The rental list shows, with no red error.', 'links' => [['Rents', url('/rentals')]]],
        ['id' => 'p3', 'group' => 'look', 'title' => 'Edit modal (view only)', 'how' => 'Open Edit on one rental, then press Cancel. Do not press Save.', 'expect' => 'The total in the modal is the same as the total on the rental card.', 'links' => [['Rents', url('/rentals')]]],
        ['id' => 'p4', 'group' => 'look', 'title' => 'Shift card', 'how' => 'On the Rents page open the shift card and "Rent detail this shift".', 'expect' => 'The Paid figure looks reasonable and the rows open.', 'links' => [['Rents', url('/rentals')]]],
        ['id' => 'p5', 'group' => 'look', 'title' => 'Shift report and PDF', 'how' => 'Press "Full report & print", then download the PDF.', 'expect' => 'The report opens and the PDF downloads.', 'links' => [['Rents', url('/rentals')]]],
        ['id' => 'p6', 'group' => 'look', 'title' => 'Accounting reports', 'how' => 'Open the Balance Sheet, Profit & Loss, and General Ledger.', 'expect' => 'The Balance Sheet balances and all three pages open.', 'links' => [['Balance Sheet', url('/accounting/reports/balance-sheet')], ['Profit & Loss', url('/accounting/reports/profit-loss')], ['General Ledger', url('/accounting/reports/general-ledger')]]],
        ['id' => 'p7', 'group' => 'look', 'title' => 'Request pages', 'how' => 'Open Edit Requests and My Requests.', 'expect' => 'Both pages open.', 'links' => [['Edit Requests', url('/approvals/edit-requests')], ['My Requests', url('/my-requests')]]],
        ['id' => 'p8', 'group' => 'look', 'title' => 'Users menu (view only)', 'how' => 'Open the Users list.', 'expect' => 'The list of accounts is as expected.', 'links' => [['Users', url('/users')]]],
        ['id' => 'p9', 'group' => 'look', 'title' => 'Forbidden pages are closed', 'how' => 'In a private window (not logged in) open the addresses /register and /delete-all.', 'expect' => 'There is no sign-up form and it is not an action page (404 or redirect to login).', 'links' => []],
        ['id' => 'l1', 'group' => 'look', 'title' => 'Rentals reach the ledger (view only)', 'how' => 'Open Settings and find the rental ledger sync card. Do not press the sync button.', 'expect' => 'It says 0 payments not yet synced and there is no list of rentals whose totals differ.', 'links' => [['Settings', url('/settings')]]],
        ['id' => 'w1', 'group' => 'practice', 'title' => 'Guide "How approvals work"', 'how' => 'In Help press Start on this guide and follow it to the end.', 'expect' => 'Finishes without errors; the practice request can be approved.', 'links' => [['Help', url('/help')]]],
        ['id' => 'w2', 'group' => 'practice', 'title' => 'Guide "Open, close, and shift report"', 'how' => 'In Help press Start on this guide and follow it to the end.', 'expect' => 'Finishes without errors.', 'links' => [['Help', url('/help')]]],
        ['id' => 'w3', 'group' => 'practice', 'title' => 'Guide "Forgot to click pickup / return?"', 'how' => 'In Help press Start on this guide and follow it to the end.', 'expect' => 'Finishes without errors; the practice pickup report is saved.', 'links' => [['Help', url('/help')]]],
        ['id' => 'w4', 'group' => 'practice', 'title' => 'Guide "How item removal works"', 'how' => 'In Help press Start on this guide and follow it to the end (Skip if it is not shown for your role).', 'expect' => 'Finishes without errors.', 'links' => [['Help', url('/help')]]],
        ['id' => 'w6', 'group' => 'practice', 'title' => 'Practice rental: create it through to fully paid', 'how' => 'In Help run the guide "How approvals work" up to the step "Create the rental", choose Deposit (DP), then create. When the practice rental list shows, open Edit on that rental, press Pay, then confirm.', 'expect' => 'The practice rental becomes fully paid (Amount paid equals Total due), without errors.', 'links' => [['Help', url('/help')]]],
        ['id' => 'w7', 'group' => 'practice', 'title' => 'Practice expense', 'how' => 'Open the Expenses page, press the "?" button at the bottom right, press Next (or "Skip this step") until the step "Try recording one yourself (practice)", then press Record Expense. Needs Trial Mode ON.', 'expect' => 'The practice expense is saved without errors.', 'links' => [['Help', url('/help')], ['Expenses', url('/expenses')]]],
        ['id' => 'w8', 'group' => 'practice', 'title' => 'Practice manual journal', 'how' => 'Open Manual Journal Entry, press the "?" button at the bottom right, and follow the guide up to the save button. Needs Trial Mode ON.', 'expect' => 'The practice journal is saved without errors.', 'links' => [['Manual Journal Entry', url('/accounting/journal-entries')]]],
        ['id' => 'w9', 'group' => 'practice', 'title' => 'Practice fixed asset and depreciation', 'how' => 'Open Fixed Assets, press the "?" button at the bottom right, go up to the step "Try recording one yourself (practice)", then save. The "Record Depreciation" step only appears if a real asset is already registered; if it does not appear, skip it. Needs Trial Mode ON.', 'expect' => 'The practice asset is saved without errors. If the depreciation step appears and you record it: the real asset\'s Total Depreciated and Book Value do not change, and the real asset\'s History still says "Not depreciated yet" (correct: the practice depreciation is recorded separately).', 'links' => [['Help', url('/help')], ['Fixed Assets', url('/accounting/reports/fixed-assets')]]],
        ['id' => 'w10', 'group' => 'practice', 'title' => 'Practice Stock Opname: start a session', 'how' => 'In Help run the guide "How Stock Opname works" until the practice session starts. Do not complete the opname at this stage.', 'expect' => 'The practice session opens without errors.', 'links' => [['Help', url('/help')], ['Stock Opname', url('/stock-opname')]]],
        ['id' => 'w5', 'group' => 'practice', 'title' => 'Practice data does not leak', 'how' => 'Go back to the Rents list and search for the practice customer name from earlier. Also open the Balance Sheet and Profit & Loss.', 'expect' => 'There is no practice rental in the real rentals list; the Balance Sheet and Profit & Loss figures are the same as before the practice test.', 'links' => [['Rents', url('/rentals')], ['Balance Sheet', url('/accounting/reports/balance-sheet')], ['Profit & Loss', url('/accounting/reports/profit-loss')]]],
    ];
    $readinessSandboxOnly = [
        'Pay / settlement on a real rental',
        'Make free',
        'Cool-down outfit swap on a fully paid rental',
        'Change the dates of a deposit (DP) or fully paid rental',
        'Approve a request on a real rental',
        'Close the shift during the practice test',
    ];
@endphp
<script>
(function () {
    var STORE_KEY = 'rental_readiness_v1_' + @json(Auth::id());
    var ITEMS = @json($readinessItems);
    var SANDBOX_ONLY = @json($readinessSandboxOnly);
    var GROUPS = {
        look: 'View only (real data, do not change)',
        practice: 'Write tests through the practice guides (separate practice data)'
    };
    var state = load();
    var captureInstalled = false;
    var unloading = false;
    var panel = null;
    // While a practice tour card is open the panel shrinks to its title bar in
    // the bottom-left corner (it would cover the top of the card on narrow
    // screens) and comes back by itself afterwards. Runtime only: the saved
    // open/minimised choice (state.min) and position (state.pos) are untouched.
    var tourRunning = false;
    var tourPeek = false;
    var watchingTours = false;

    function autoMin() { return tourRunning && !tourPeek; }

    function tourActive() {
        return !!document.querySelector('[id^="rental"][id$="TourOverlay"]:not(.hidden)');
    }

    function watchTours() {
        if (watchingTours) { return; }
        watchingTours = true;
        setInterval(function () {
            var now = tourActive();
            if (now === tourRunning) { return; }
            tourRunning = now;
            tourPeek = false;
            render();
        }, 400);
    }

    function blank() {
        return { on: false, min: false, pos: null, res: {}, errs: [] };
    }

    function load() {
        try {
            var raw = window.localStorage.getItem(STORE_KEY);
            var s = raw ? JSON.parse(raw) : null;
            if (s && typeof s === 'object') {
                var b = blank();
                Object.keys(b).forEach(function (k) { if (s[k] === undefined) { s[k] = b[k]; } });
                return s;
            }
        } catch (e) { /* storage blocked: run without persistence */ }
        return blank();
    }

    function save() {
        try { window.localStorage.setItem(STORE_KEY, JSON.stringify(state)); } catch (e) { /* ignore */ }
    }

    function logError(kind, msg) {
        if (!state.on) { return; }
        state.errs.push({ t: Date.now(), page: location.pathname, kind: kind, msg: String(msg).slice(0, 200) });
        if (state.errs.length > 50) { state.errs.shift(); }
        save();
        // A render failure must not log itself again (would loop through window 'error').
        if (panel) { try { render(); } catch (e) { /* ignore */ } }
    }

    function installCapture() {
        if (captureInstalled) { return; }
        captureInstalled = true;

        window.addEventListener('pagehide', function () { unloading = true; });
        window.addEventListener('beforeunload', function () { unloading = true; });
        window.addEventListener('error', function (e) {
            logError('JS', (e.message || 'error') + (e.filename ? ' @' + e.filename.split('/').pop() + ':' + e.lineno : ''));
        });
        window.addEventListener('unhandledrejection', function (e) {
            logError('Promise', (e.reason && e.reason.message) || e.reason || 'rejected');
        });

        function bad(status) { return status >= 500 || status === 419; }

        if (window.fetch) {
            var origFetch = window.fetch;
            window.fetch = function () {
                var args = arguments;
                var target = typeof args[0] === 'string' ? args[0] : ((args[0] && args[0].url) || '');
                return origFetch.apply(this, args).then(function (res) {
                    if (bad(res.status)) { logError('HTTP ' + res.status, target); }
                    return res;
                }, function (err) {
                    if (!unloading && !(err && err.name === 'AbortError')) { logError('Network', target); }
                    throw err;
                });
            };
        }

        if (window.XMLHttpRequest) {
            var origOpen = XMLHttpRequest.prototype.open;
            var origSend = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method, url) {
                this.__drcUrl = url;
                return origOpen.apply(this, arguments);
            };
            XMLHttpRequest.prototype.send = function () {
                this.addEventListener('loadend', function () {
                    if (bad(this.status)) { logError('HTTP ' + this.status, this.__drcUrl); }
                });
                return origSend.apply(this, arguments);
            };
        }
    }

    function start() {
        state.on = true;
        state.min = false;
        save();
        installCapture();
        watchTours();
        render();
    }

    function stop() {
        state.on = false;
        save();
        if (panel) { panel.remove(); panel = null; }
    }

    function counts() {
        var c = { pass: 0, fail: 0, skip: 0, total: ITEMS.length };
        ITEMS.forEach(function (it) {
            var r = state.res[it.id];
            if (r && c[r.s] !== undefined) { c[r.s]++; }
        });
        c.done = c.pass + c.fail + c.skip;
        return c;
    }

    function report() {
        var lines = ['System readiness test - ' + new Date().toLocaleString('en-GB') + ' - ' + location.host];
        var c = counts();
        lines.push('Passed ' + c.pass + ', Failed ' + c.fail + ', Skipped ' + c.skip + ', Not done ' + (c.total - c.done) + ', Errors detected ' + state.errs.length);
        ITEMS.forEach(function (it) {
            var r = state.res[it.id];
            var mark = !r ? '[ ]' : (r.s === 'pass' ? '[OK]' : (r.s === 'fail' ? '[FAILED]' : '[SKIPPED]'));
            lines.push(mark + ' ' + it.title + (r && r.n ? ' - note: ' + r.n : ''));
        });
        state.errs.forEach(function (e) {
            lines.push('ERROR ' + new Date(e.t).toLocaleTimeString('en-GB') + ' ' + e.kind + ' ' + e.page + ' ' + e.msg);
        });
        return lines.join('\n');
    }

    function copyReport() {
        var text = report();
        function fallback() {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;left:-9999px;top:0;';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (e) { /* ignore */ }
            ta.remove();
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).catch(fallback);
        } else {
            fallback();
        }
        var btn = panel && panel.querySelector('[data-drc-copy]');
        if (btn) {
            btn.textContent = 'Copied';
            setTimeout(function () { if (btn) { btn.textContent = 'Copy report'; } }, 1500);
        }
    }

    function el(tag, css, text) {
        var node = document.createElement(tag);
        if (css) { node.style.cssText = css; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    function button(label, css, handler) {
        var b = el('button', 'cursor:pointer;border:1px solid #d1d5db;background:#fff;color:#374151;border-radius:6px;padding:2px 8px;font-size:12px;line-height:18px;' + (css || ''), label);
        b.type = 'button';
        b.addEventListener('click', handler);
        return b;
    }

    function setResult(id, s) {
        var cur = state.res[id];
        if (cur && cur.s === s) {
            delete state.res[id];
        } else {
            state.res[id] = { s: s, n: cur && cur.n ? cur.n : '' };
        }
        save();
        render();
    }

    function itemNode(it) {
        var r = state.res[it.id];
        var box = el('div', 'padding:8px 0;border-top:1px solid #f3f4f6;');
        var color = !r ? '#111827' : (r.s === 'pass' ? '#047857' : (r.s === 'fail' ? '#b91c1c' : '#6b7280'));
        box.appendChild(el('div', 'font-weight:600;color:' + color + ';', it.title));
        box.appendChild(el('div', 'color:#4b5563;margin-top:2px;', it.how));
        box.appendChild(el('div', 'color:#6b7280;margin-top:2px;', 'Passes if: ' + it.expect));

        var row = el('div', 'display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;align-items:center;');
        it.links.forEach(function (l) {
            var a = el('a', 'font-size:12px;color:#4f46e5;text-decoration:underline;margin-right:4px;', l[0] + ' →');
            a.href = l[1];
            row.appendChild(a);
        });
        [['pass', 'Pass', '#047857'], ['fail', 'Fail', '#b91c1c'], ['skip', 'Skip', '#6b7280']].forEach(function (o) {
            var active = r && r.s === o[0];
            row.appendChild(button(o[1], active ? 'background:' + o[2] + ';color:#fff;border-color:' + o[2] + ';' : '', function () { setResult(it.id, o[0]); }));
        });
        box.appendChild(row);

        if (r && r.s === 'fail') {
            var note = el('input', 'margin-top:6px;width:100%;box-sizing:border-box;border:1px solid #fca5a5;border-radius:6px;padding:4px 6px;font-size:12px;');
            note.type = 'text';
            note.placeholder = 'What does not match?';
            note.value = r.n || '';
            note.addEventListener('change', function () { r.n = note.value; save(); });
            box.appendChild(note);
        }
        return box;
    }

    function clamp() {
        if (!panel) { return; }
        var w = panel.offsetWidth;
        var h = panel.offsetHeight;
        if (autoMin()) {
            panel.style.left = '12px';
            panel.style.top = Math.max(4, window.innerHeight - h - 12) + 'px';
            return;
        }
        var p = state.pos || { x: 12, y: window.innerHeight - h - 12 };
        p.x = Math.max(4, Math.min(p.x, window.innerWidth - w - 4));
        p.y = Math.max(4, Math.min(p.y, window.innerHeight - h - 4));
        panel.style.left = p.x + 'px';
        panel.style.top = p.y + 'px';
        state.pos = p;
    }

    function makeDraggable(handle) {
        var drag = null;
        handle.style.touchAction = 'none';
        handle.addEventListener('pointerdown', function (e) {
            if (e.target.closest('button') || autoMin()) { return; }
            drag = { dx: e.clientX - panel.offsetLeft, dy: e.clientY - panel.offsetTop };
            handle.setPointerCapture(e.pointerId);
        });
        handle.addEventListener('pointermove', function (e) {
            if (!drag) { return; }
            state.pos = { x: e.clientX - drag.dx, y: e.clientY - drag.dy };
            clamp();
        });
        function end() { if (drag) { drag = null; save(); } }
        handle.addEventListener('pointerup', end);
        handle.addEventListener('pointercancel', end);
    }

    function render() {
        if (!state.on) { return; }
        if (!panel) {
            panel = el('div', 'position:fixed;z-index:1050;width:min(340px,calc(100vw - 8px));background:#fff;border:1px solid #d1d5db;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.18);font:13px/1.45 system-ui,-apple-system,sans-serif;color:#111827;');
            panel.id = 'drc-panel';
            document.body.appendChild(panel);
        }
        // Compact bar while a tour card is open, so it does not hide the card's step dots.
        panel.style.width = autoMin() ? 'fit-content' : 'min(340px,calc(100vw - 8px))';
        while (panel.firstChild) { panel.removeChild(panel.firstChild); }

        var c = counts();
        // During a tour the "Open"/"Minimise" button only peeks at the panel; the saved choice stays.
        var min = tourRunning ? !tourPeek : state.min;
        var head = el('div', 'display:flex;align-items:center;gap:8px;padding:8px 10px;cursor:move;background:#eef2ff;border-radius:10px 10px ' + (min ? '10px 10px' : '0 0') + ';user-select:none;');
        head.appendChild(el('strong', 'flex:1;', 'Readiness test ' + c.done + '/' + c.total + (state.errs.length ? ' · ' + state.errs.length + (state.errs.length === 1 ? ' error' : ' errors') : '')));
        head.appendChild(button(min ? 'Open' : 'Minimise', '', function () {
            if (tourRunning) { tourPeek = !tourPeek; } else { state.min = !state.min; save(); }
            render();
        }));
        head.appendChild(button('×', '', function () { stop(); }));
        panel.appendChild(head);
        makeDraggable(head);

        if (!min) {
            var body = el('div', 'max-height:min(60vh,520px);overflow:auto;padding:4px 10px 8px;');
            if (c.done === c.total) {
                var ok = c.fail === 0 && state.errs.length === 0;
                body.appendChild(el('div', 'margin:6px 0;padding:6px 8px;border-radius:6px;font-weight:600;background:' + (ok ? '#ecfdf5' : '#fef2f2') + ';color:' + (ok ? '#047857' : '#b91c1c') + ';',
                    ok ? 'Done: everything matches, no errors detected.' : 'Done: ' + c.fail + ' failed, ' + state.errs.length + ' errors detected.'));
            }
            Object.keys(GROUPS).forEach(function (g) {
                body.appendChild(el('div', 'margin-top:8px;font-size:11px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;color:#6b7280;', GROUPS[g]));
                ITEMS.filter(function (it) { return it.group === g; }).forEach(function (it) { body.appendChild(itemNode(it)); });
            });

            var sandbox = el('details', 'margin-top:8px;border-top:1px solid #f3f4f6;padding-top:6px;');
            sandbox.appendChild(el('summary', 'cursor:pointer;color:#b45309;font-weight:600;', 'Do not do this on real data (sandbox only)'));
            SANDBOX_ONLY.forEach(function (t) { sandbox.appendChild(el('div', 'color:#4b5563;margin-top:2px;', '• ' + t)); });
            body.appendChild(sandbox);

            var errs = el('details', 'margin-top:6px;');
            errs.appendChild(el('summary', 'cursor:pointer;font-weight:600;color:' + (state.errs.length ? '#b91c1c' : '#047857') + ';', 'Errors detected: ' + state.errs.length));
            state.errs.forEach(function (e) {
                errs.appendChild(el('div', 'color:#4b5563;margin-top:2px;word-break:break-word;', new Date(e.t).toLocaleTimeString('en-GB') + ' ' + e.kind + ' ' + e.page + ' ' + e.msg));
            });
            body.appendChild(errs);

            var foot = el('div', 'display:flex;gap:6px;margin-top:8px;');
            var copy = button('Copy report', '', copyReport);
            copy.setAttribute('data-drc-copy', '');
            foot.appendChild(copy);
            foot.appendChild(button('Start over', '', function () {
                if (window.confirm('Delete all ticks and recorded errors?')) {
                    state.res = {};
                    state.errs = [];
                    save();
                    render();
                }
            }));
            body.appendChild(foot);
            panel.appendChild(body);
        }
        clamp();
        save();
    }

    try {
        var flag = new URLSearchParams(location.search).get('smoke');
        if (flag === '1') { state.on = true; state.min = false; save(); }
        if (flag === '0') { state.on = false; save(); }
    } catch (e) { /* ignore */ }

    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('[data-start-readiness]')) { start(); }
    });
    window.addEventListener('resize', clamp);
    window.rentalReadiness = { start: start, stop: stop };

    if (state.on) {
        installCapture();
        watchTours();
        render();
    }
})();
</script>
@endif
