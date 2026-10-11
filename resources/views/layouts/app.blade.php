<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#4f46e5">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Rental Suite') }}">
        <meta name="mobile-web-app-capable" content="yes">

        <title>{{ config('app.name', 'Rental Suite') }}</title>

        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="icon" href="/favicon.ico">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @if(config('app.debug'))
            {{-- Bug trap: records reload/scroll/submit/navigation causes across reloads.
                 Inspect with rentalBugTrap.dump() in the console; auto-reports after a reload. --}}
            <script>
                (function () {
                    var KEY = '__RENTAL_BUGTRAP';
                    var MAX = 250;
                    var log = [];
                    try { log = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) {}
                    function save() { try { sessionStorage.setItem(KEY, JSON.stringify(log.slice(-MAX))); } catch (e) {} }
                    function stack() { try { return (new Error()).stack.split('\n').slice(2, 6).join(' | '); } catch (e) { return ''; } }
                    function tourStepInfo() {
                        try {
                            var steps = document.querySelectorAll('.rental-tour-step');
                            var idx = -1, title = null;
                            steps.forEach(function (el, i) { if (!el.classList.contains('hidden')) { idx = i; var h3 = el.querySelector('h3'); title = h3 ? h3.textContent : null; } });
                            return idx === -1 ? null : { idx: idx, title: title };
                        } catch (e) { return null; }
                    }
                    function add(type, detail) {
                        log.push({ t: new Date().toISOString().slice(11, 23), page: location.pathname, type: type, detail: detail || null, tourStep: tourStepInfo() });
                        if (log.length > MAX) log = log.slice(-MAX);
                        save();
                    }
                    function describeEl(el) {
                        if (!el) return '';
                        var bits = el.tagName ? el.tagName.toLowerCase() : '';
                        if (el.id) bits += '#' + el.id;
                        if (el.className && typeof el.className === 'string') bits += '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.');
                        var text = (el.textContent || '').trim().slice(0, 30);
                        if (text) bits += ' "' + text + '"';
                        return bits;
                    }
                    function reportToServer(reason) {
                        try {
                            var url = @json(app()->environment('local') && config('app.debug') ? route('debug.bugtrap-report') : null);
                            if (!url) return;
                            var payload = JSON.stringify({ reason: reason, entries: log.slice(-40) });
                            if (navigator.sendBeacon) {
                                var blob = new Blob([payload], { type: 'application/json' });
                                navigator.sendBeacon(url, blob);
                            } else {
                                fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload, keepalive: true }).catch(function () {});
                            }
                        } catch (e) {}
                    }
                    window.rentalBugTrap = {
                        dump: function () { console.table(log); return log; },
                        clear: function () { log = []; save(); return 'cleared'; },
                        add: add,
                        report: reportToServer,
                    };

                    // ── Real clicks, with a description of exactly what was clicked —
                    // this is what actually answers "what did I click right before it
                    // refreshed", since the Next button vs. a real underlying element look
                    // identical in the moment but only one of them should navigate. ──
                    document.addEventListener('click', function (ev) {
                        var el = ev.target && ev.target.closest ? ev.target.closest('button, a, [role="button"], input[type="submit"]') : null;
                        if (!el) return;
                        add('click', { el: describeEl(el), href: el.getAttribute && el.getAttribute('href') || null });
                    }, true);

                    // ── How did this page load? Report what preceded a reload. ──
                    var navEntry = (performance.getEntriesByType && performance.getEntriesByType('navigation')[0]) || null;
                    var navType = navEntry ? navEntry.type : 'unknown';
                    add('page-load', { navType: navType });
                    if (navType === 'reload') {
                        var before = log.slice(-12, -1);
                        console.warn('[bugtrap] This page load was a RELOAD. Events right before it:');
                        console.table(before);
                    }

                    // ── Reload / navigation initiators ──
                    try {
                        var origReload = location.reload.bind(location);
                        window.location.reload = function () { add('location.reload', { stack: stack() }); return origReload.apply(this, arguments); };
                    } catch (e) {}
                    document.addEventListener('submit', function (ev) {
                        var f = ev.target;
                        add('form-submit', { action: (f && f.action) || '', id: (f && f.id) || '' });
                    }, true);
                    window.addEventListener('pagehide', function (ev) {
                        add('pagehide', { persisted: !!ev.persisted });
                        // Auto-report every unintended-looking unload while a tour step
                        // is active, so a sudden refresh during the tutorial gets logged
                        // server-side without needing anyone to open devtools.
                        if (tourStepInfo()) reportToServer('pagehide-during-tour');
                    });
                    window.addEventListener('error', function (ev) {
                        add('js-error', { msg: String(ev.message || '').slice(0, 160) });
                        reportToServer('js-error');
                    });
                    window.addEventListener('unhandledrejection', function (ev) {
                        var reason = ev && ev.reason;
                        var msg = reason && reason.message ? reason.message : String(reason);
                        add('unhandled-rejection', { msg: String(msg || '').slice(0, 160) });
                        reportToServer('unhandled-rejection');
                    });

                    // ── fetch/XHR-based submits (Save/Approve/Reject/Request Approval
                    // buttons all go through fetch, not a real <form> submit) — log
                    // start, and whether it ever resolved, so a click that silently did
                    // nothing (vs. one that's just slow) is distinguishable after the fact. ──
                    var origFetch = window.fetch;
                    if (origFetch) {
                        window.fetch = function (input, init) {
                            var url = typeof input === 'string' ? input : (input && input.url) || '';
                            var method = (init && init.method) || (typeof input === 'object' && input && input.method) || 'GET';
                            var startedAt = Date.now();
                            add('fetch-start', { url: String(url).slice(0, 120), method: method });
                            return origFetch.apply(window, arguments).then(function (r) {
                                var detail = { url: String(url).slice(0, 120), status: r.status, ms: Date.now() - startedAt };
                                // On a non-2xx response to a real mutating request (create/update/approve/
                                // reject), also capture the response body — this is what actually explains
                                // "I clicked it and nothing happened": the request went out and the server
                                // rejected it (e.g. a validation error), but the only visible symptom is the
                                // step just not advancing.
                                if (!r.ok && method !== 'GET') {
                                    r.clone().json().then(function (body) {
                                        detail.body = JSON.stringify(body).slice(0, 400);
                                        add('fetch-done', detail);
                                        reportToServer('fetch-error-response');
                                    }).catch(function () {
                                        add('fetch-done', detail);
                                        reportToServer('fetch-error-response');
                                    });
                                } else {
                                    add('fetch-done', detail);
                                }
                                return r;
                            }, function (err) {
                                add('fetch-fail', { url: String(url).slice(0, 120), msg: String(err && err.message || err).slice(0, 160), ms: Date.now() - startedAt });
                                reportToServer('fetch-fail');
                                throw err;
                            });
                        };
                    }

                    // ── Programmatic scroll initiators (with stacks) ──
                    var origScrollTo = window.scrollTo.bind(window);
                    window.scrollTo = function () {
                        add('scrollTo', { args: [].slice.call(arguments).map(function (a) { return typeof a === 'object' ? JSON.stringify(a) : String(a); }).join(','), stack: stack() });
                        return origScrollTo.apply(window, arguments);
                    };
                    var origSIV = Element.prototype.scrollIntoView;
                    Element.prototype.scrollIntoView = function () {
                        add('scrollIntoView', { el: (this.id || this.className || this.tagName || '').toString().slice(0, 80), stack: stack() });
                        return origSIV.apply(this, arguments);
                    };
                    var origFocus = HTMLElement.prototype.focus;
                    HTMLElement.prototype.focus = function () {
                        var before = window.scrollY;
                        var el = this;
                        var r = origFocus.apply(this, arguments);
                        if (Math.abs(window.scrollY - before) > 50) {
                            add('focus-caused-scroll', { el: el.id || el.tagName, from: before, to: window.scrollY, stack: stack() });
                        }
                        return r;
                    };

                    // ── Unattributed scroll jumps (no recent user input = self-scroll) ──
                    var lastInput = 0;
                    ['wheel', 'touchstart', 'touchmove', 'keydown', 'mousedown'].forEach(function (evName) {
                        window.addEventListener(evName, function () { lastInput = Date.now(); }, { passive: true, capture: true });
                    });
                    var lastY = 0;
                    window.addEventListener('scroll', function () {
                        var y = window.scrollY;
                        var jump = Math.abs(y - lastY);
                        if (jump > 150 && (Date.now() - lastInput) > 500) {
                            add('self-scroll', { from: lastY, to: y });
                            console.warn('[bugtrap] self-scroll detected', lastY, '→', y);
                        }
                        lastY = y;
                    }, { passive: true });
                })();
            </script>
        @endif

        {{-- Vite builds resources/css/app.css (includes .form-input / .form-select, etc.). CDN Tailwind only sees utilities, not @layer components. --}}
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            :where(
                button,
                [role="button"],
                a[href],
                summary,
                [onclick],
                input[type="button"],
                input[type="submit"],
                input[type="reset"]
            ) {
                cursor: pointer;
            }

            :where(
                button:disabled,
                [role="button"][aria-disabled="true"],
                input[type="button"]:disabled,
                input[type="submit"]:disabled,
                input[type="reset"]:disabled
            ) {
                cursor: not-allowed;
            }
        </style>
    </head>
    <body class="font-sans antialiased text-base">
        <div class="min-h-screen bg-gray-100">
            @include('layouts.navigation')
            @include('rentals.partials.activity-log-panel')

            @auth
                <script>
                    (function (w) {
                        w.rentalUpdatePendingApprovalBadges = function (queueTotal) {
                            var n = Math.max(0, parseInt(queueTotal, 10) || 0);
                            document.querySelectorAll('[data-pending-approvals-badge]').forEach(function (el) {
                                if (n < 1) {
                                    el.remove();
                                    return;
                                }
                                el.textContent = String(n);
                            });
                        };
                    })(window);
                </script>
            @endauth

            @php
                $approvalItems = ($pendingRentalApprovals ?? collect());
                $viewerIsMgmt = Auth::check() && (Auth::user()->isManagement() || Auth::user()->isSuperAdmin());
                $showFloatingApprovalDock = $viewerIsMgmt && ! request()->routeIs('rentals.index');
                $approvalDockEmpty = $approvalItems->isEmpty();
            @endphp
            <div class="pt-14 md:pt-0 md:pl-64">
                @if(\App\Support\DebugClock::enabled() && (app()->environment('local') || (Auth::check() && Auth::user()->isSuperAdmin())))
                    @php $fakeActive = \Carbon\Carbon::hasTestNow(); @endphp
                    <div class="{{ $fakeActive ? 'bg-fuchsia-700' : 'bg-gray-800' }} text-white text-xs px-4 py-1.5">
                        <form method="POST" action="{{ route('debug.fake-now') }}" class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1">
                            @csrf
                            <span class="font-semibold">⏰ Debug clock</span>
                            @if($fakeActive)
                                <span>App time is <span class="font-bold tabular-nums">{{ now()->format('d M Y H:i') }}</span> (faked)</span>
                            @else
                                <span class="text-gray-300">Real time</span>
                            @endif
                            <input type="datetime-local" name="fake_now" value="{{ $fakeActive ? now()->format('Y-m-d\TH:i') : '' }}"
                                class="rounded border-0 bg-white/15 px-2 py-0.5 text-xs text-white [color-scheme:dark]">
                            <button type="submit" class="rounded bg-white/20 px-2 py-0.5 font-semibold hover:bg-white/30">Set</button>
                            <button type="submit" name="fake_now" value="" class="rounded bg-white/20 px-2 py-0.5 font-semibold hover:bg-white/30">Reset to real time</button>
                        </form>
                    </div>
                @endif
                @isset($header)
                    <header class="bg-white shadow">
                        <div class="py-6 px-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                @if($showFloatingApprovalDock)
                    {{-- In the page flow on mobile; floats bottom-right on desktop. --}}
                    <div id="manager-pending-approvals-dock" class="{{ $approvalDockEmpty ? 'hidden ' : '' }}mx-4 mt-4 md:m-0 md:fixed md:right-4 md:bottom-4 md:z-[100] md:w-full md:max-w-sm rounded-xl border border-amber-300 bg-amber-50 shadow-lg md:shadow-2xl overflow-hidden" role="region" aria-label="Pending rent approvals" data-dock-empty="{{ $approvalDockEmpty ? '1' : '0' }}">
                        <button type="button" id="manager-pending-approvals-dock-toggle"
                            class="w-full px-4 py-2.5 flex items-center justify-between gap-2 border-b border-amber-200 hover:bg-amber-100/60 transition cursor-pointer"
                            aria-expanded="true" aria-controls="manager-pending-approvals-dock-cards">
                            <span class="flex items-center gap-2 min-w-0">
                                <h3 class="text-sm font-semibold text-amber-950 truncate">Pending cool-down approvals</h3>
                                <span id="manager-pending-approvals-dock-badge" class="inline-flex min-w-[1.25rem] justify-center rounded-full bg-amber-200 px-1.5 py-0.5 text-[11px] font-bold leading-none text-amber-950">{{ (int) ($pendingRentalApprovalsCount ?? 0) }}</span>
                            </span>
                            <svg id="manager-pending-approvals-dock-chevron" class="h-4 w-4 text-amber-900 shrink-0 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                        </button>
                        <div id="manager-pending-approvals-dock-cards" class="p-3 space-y-3 md:max-h-[calc(100vh-9rem)] md:overflow-y-auto">
                            @include('partials.pending-rental-approval-cards', [
                                'approvals' => $approvalItems,
                                'queueTotal' => (int) ($pendingRentalApprovalsCount ?? 0),
                            ])
                        </div>
                    </div>
                    <script>
                        (function () {
                            var KEY = 'rental_approvals_dock_collapsed';
                            var btn = document.getElementById('manager-pending-approvals-dock-toggle');
                            var body = document.getElementById('manager-pending-approvals-dock-cards');
                            var chev = document.getElementById('manager-pending-approvals-dock-chevron');
                            if (!btn || !body) return;
                            function apply(collapsed) {
                                body.classList.toggle('hidden', collapsed);
                                btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                                if (chev) chev.style.transform = collapsed ? 'rotate(-90deg)' : '';
                                btn.classList.toggle('border-b', !collapsed);
                            }
                            var saved = false;
                            try { saved = localStorage.getItem(KEY) === '1'; } catch (e) {}
                            apply(saved);
                            btn.addEventListener('click', function () {
                                var collapsed = !body.classList.contains('hidden');
                                apply(collapsed);
                                try { localStorage.setItem(KEY, collapsed ? '1' : '0'); } catch (e) {}
                            });
                        })();
                    </script>
                @endif

                <main>
                    {{ $slot }}
                </main>
            </div>
        </div>

        @if($showFloatingApprovalDock)
            {{-- Poll snapshot on non–Rentals pages (no visible debug — countdown/jitter UI is Rentals-only). --}}
            <script>
                (function () {
                    if (window.__RENTAL_DOCK_APPROVAL_POLL) return;
                    window.__RENTAL_DOCK_APPROVAL_POLL = true;
                    var snapshotUrl = @json(route('approvals.rentals.pending-snapshot'));
                    var queueFragmentUrl = @json(route('approvals.rentals.queue-fragment'));
                    var timer = null;
                    var last = { count: null, latest_id: null };

                    function schedule(ms) {
                        if (timer) clearTimeout(timer);
                        timer = setTimeout(run, ms);
                    }

                    function run() {
                        timer = null;
                        var dock = document.getElementById('manager-pending-approvals-dock');
                        if (!dock) {
                            return;
                        }
                        fetch(snapshotUrl + '?_=' + Date.now(), {
                            cache: 'no-store',
                            credentials: 'same-origin',
                            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        })
                            .then(function (r) { return r.json(); })
                            .then(function (s) {
                                var count = s && typeof s.count === 'number' ? s.count : 0;
                                var latestId = s.latest_id != null ? Number(s.latest_id) : null;
                                var changed = last.count !== null && (last.count !== count || last.latest_id !== latestId);
                                if (changed) {
                                    var el = document.activeElement;
                                    var tag = el ? (el.tagName || '').toLowerCase() : '';
                                    var interacting = tag === 'input' || tag === 'textarea' || tag === 'select' || (el && el.isContentEditable);
                                    if (interacting) {
                                        schedule(2000);
                                        return;
                                    }
                                }
                                last = { count: count, latest_id: latestId };
                                if (!changed) {
                                    schedule(5000);
                                    return;
                                }
                                fetch(queueFragmentUrl + '?_=' + Date.now(), {
                                    cache: 'no-store',
                                    credentials: 'same-origin',
                                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                })
                                    .then(function (r) {
                                        if (!r.ok) throw new Error('fragment');
                                        return r.json();
                                    })
                                    .then(function (d) {
                                        var dockEl = document.getElementById('manager-pending-approvals-dock');
                                        var cardsHost = document.getElementById('manager-pending-approvals-dock-cards');
                                        var badge = document.getElementById('manager-pending-approvals-dock-badge');
                                        var q = d && typeof d.queue_total === 'number' ? d.queue_total : 0;
                                        if (!dockEl) return;
                                        if (q < 1 || !d.dock_html) {
                                            dockEl.classList.add('hidden');
                                            if (cardsHost) cardsHost.innerHTML = '';
                                        } else {
                                            if (cardsHost) cardsHost.innerHTML = d.dock_html;
                                            if (badge) badge.textContent = String(q);
                                            dockEl.classList.remove('hidden');
                                        }
                                        if (typeof window.rentalUpdatePendingApprovalBadges === 'function') {
                                            window.rentalUpdatePendingApprovalBadges(q);
                                        }
                                        schedule(5000);
                                    })
                                    .catch(function () {
                                        // Avoid reload loops when fragment returns 403/HTML (e.g. session edge cases).
                                        schedule(5000);
                                    });
                            })
                            .catch(function () {
                                schedule(5000);
                            });
                    }

                    schedule(5000);
                    document.addEventListener('visibilitychange', function () {
                        if (document.getElementById('manager-pending-approvals-dock') && !timer) {
                            schedule(5000);
                        }
                    });
                })();
            </script>
        @endif

        <div id="pwa-loading-overlay" class="hidden fixed inset-0 z-[200] bg-black/20 backdrop-blur-sm">
            <div class="absolute inset-0 flex items-center justify-center">
                <div class="flex items-center rounded-lg border bg-white px-4 py-3 shadow-lg">
                    <svg class="mr-3 -ml-1 size-5 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75 fill-indigo-600" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span class="text-sm font-semibold text-black">Loading…</span>
                </div>
            </div>
        </div>

        <script>
            (function () {
                function isStandalone() {
                    try {
                        if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return true;
                        if (window.navigator && window.navigator.standalone) return true;
                    } catch (e) {}
                    return false;
                }

                var standalone = isStandalone();

                var overlay = document.getElementById('pwa-loading-overlay');
                if (!overlay) return;

                var shown = false;
                function show() {
                    if (shown) return;
                    shown = true;
                    overlay.classList.remove('hidden');
                }
                function hide() {
                    shown = false;
                    overlay.classList.add('hidden');
                }

                window.rentalShowPwaLoading = show;
                window.rentalHidePwaLoading = hide;

                window.addEventListener('pageshow', hide);

                try {
                    var params = new URLSearchParams(window.location.search);
                    if (params.get('pwa_loading_test') === '1' || params.get('pwaLoadingTest') === '1') {
                        show();
                        setTimeout(hide, 2000);
                    }
                } catch (e) {}

                document.addEventListener('click', function (e) {
                    if (e.defaultPrevented) return;
                    if (e.button !== 0) return;
                    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
                    if (!e.target || !e.target.closest) return;
                    var a = e.target.closest('a[href]');
                    if (!a) return;
                    if (a.hasAttribute('download')) return;
                    var target = (a.getAttribute('target') || '').trim();
                    if (target && target !== '_self') return;
                    var href = (a.getAttribute('href') || '').trim();
                    if (!href || href === '#' || href.startsWith('#')) return;
                    try {
                        var u = new URL(href, window.location.href);
                        if (u.origin !== window.location.origin) return;
                        if (u.href === window.location.href) return;
                    } catch (err) {
                        return;
                    }
                    e.preventDefault();
                    show();
                    try {
                        requestAnimationFrame(function () {
                            requestAnimationFrame(function () {
                                window.location.href = u.href;
                            });
                        });
                    } catch (e2) {
                        setTimeout(function () {
                            window.location.href = u.href;
                        }, 120);
                    }
                }, true);

                document.addEventListener('submit', function (e) {
                    setTimeout(function () {
                        if (e.defaultPrevented) return;
                        var form = e.target;
                        if (form && form.getAttribute) {
                            var t = (form.getAttribute('target') || '').trim();
                            if (t && t !== '_self') return;
                        }
                        show();
                    }, 0);
                }, true);

                window.addEventListener('beforeunload', function () {
                    show();
                });
            })();
        </script>
        @auth
            @include('partials.tour-engine')
            @include('partials.approval-tour')
            @include('partials.removal-tour')
            @include('partials.late-done-tour')
            @include('partials.my-requests-info-tour')
            @include('partials.activity-log-tour')
            @include('partials.shift-tour')
            @include('partials.role-info-tour')
            @include('partials.store-info-tour')
            @include('partials.store-user-tour')
            @include('partials.accounting-tour')
            @include('partials.financial-reports-tour')
            @include('partials.manual-journal-entry-tour')
            @include('partials.fixed-assets-tour')
            @include('partials.ppn-tour')
            @include('partials.piutang-tour')
            @include('partials.stock-opname-tour')
@include('partials.stock-opname-scan-tour')
            @include('partials.whats-new-accounting')
            @include('partials.whats-new-release')
            @include('partials.readiness-checklist')
        @endauth
    </body>
</html>
