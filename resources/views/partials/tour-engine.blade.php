{{-- Shared spotlight/coachmark tour engine (rentalCreateTour) — extracted out of
     approval-tour.blade.php so it loads unconditionally, before every other tour
     partial. It used to live inside approval-tour.blade.php's own
     @if($tourIsAdmin || $tourIsDecider) block; any role outside that gate (e.g.
     Accounting) never got the block rendered at all, so `rentalCreateTour` was
     undefined the moment a role-appropriate tour partial (accounting-tour,
     financial-reports-tour, activity-log-tour, ...) tried to call it — a
     ReferenceError that aborted that script tag before it ever reached the
     `document.querySelectorAll(cfg.triggerSelector)` binding, leaving every
     "Start"/"View" trigger on the page inert with no click handler at all.
     This partial must be @include'd first, with no role condition around it. --}}
<script>
// Reusable engine — see the bottom of this file for the main tour's own
// instantiation. removal-tour.blade.php calls this a second time with its
// own config (different element id prefix, step class, storage keys, and
// tutorial-seed map) so the Removal Requests walkthrough gets its own
// independent, real-spotlight-capable tutorial instead of duplicating this
// entire ~700-line positioning/state engine a second time.
function rentalCreateTour(cfg) {
    var STORAGE_KEY = cfg.storageKey;
    var RESUME_KEY = cfg.resumeKey;
    // Carries a scoped tour's upper bound (a FAB's "just this page" range,
    // e.g. Expenses' 5 steps out of the full walkthrough) across a REAL page
    // reload — needed since a scoped step can now trigger one itself (the
    // practice expense's real form submit). Without this, resuming after
    // that reload falls back to the full, unscoped tour and Next can wander
    // into unrelated pages (Cash In/Out) the FAB never promised.
    var SCOPE_KEY = RESUME_KEY + '_scope';
    function persistResumeState(stepIdx) {
        try {
            sessionStorage.setItem(RESUME_KEY, String(stepIdx));
            if (scopeEnd !== null) sessionStorage.setItem(SCOPE_KEY, String(scopeEnd));
            else sessionStorage.removeItem(SCOPE_KEY);
        } catch (e) {}
    }
    // Set fresh by render() whenever a data-tutorial-seed step is active —
    // see the real-click handler in render()'s targetEl branch for why the
    // click needs to wait on this instead of firing immediately.
    var tutorialSeedPromise = null;
    // Shared by render()'s data-tutorial-seed handling and
    // reseedAndResumeAt() (Back's recovery path) — one map of "which
    // practice data this kind of step depends on" to "how to (re)create it",
    // so both call sites stay in sync instead of each hardcoding their own
    // endpoint. Provided per-instance via cfg — the main tour only ever seeds
    // edit-requests, the removal tour only ever seeds item-removal.
    var tutorialSeedConfig = cfg.tutorialSeedConfig || {};
    var overlay = document.getElementById(cfg.idPrefix + 'Overlay');
    var spotlight = document.getElementById(cfg.idPrefix + 'Spotlight');
    var clickBlocker = document.getElementById(cfg.idPrefix + 'ClickBlocker');

    // Shows the full-page click blocker with a rectangular hole punched out
    // at the given rect (or no hole at all when rect is null, for centered
    // steps with nothing real to interact with). Same padding as the visual
    // spotlight so the clickable area always matches what's highlighted.
    function setClickBlockerHole(rect, pad) {
        clickBlocker.classList.remove('hidden');
        if (!rect) {
            clickBlocker.style.clipPath = 'none';
            return;
        }
        var p = pad || 0;
        var l = Math.max(0, rect.left - p), t = Math.max(0, rect.top - p);
        var r = Math.min(window.innerWidth, rect.right + p), b = Math.min(window.innerHeight, rect.bottom + p);
        var w = window.innerWidth, h = window.innerHeight;
        clickBlocker.style.clipPath = 'polygon(evenodd, 0 0, ' + w + 'px 0, ' + w + 'px ' + h + 'px, 0 ' + h + 'px, 0 0, '
            + l + 'px ' + t + 'px, ' + l + 'px ' + b + 'px, ' + r + 'px ' + b + 'px, ' + r + 'px ' + t + 'px, ' + l + 'px ' + t + 'px)';
    }
    var stepEls = Array.prototype.slice.call(document.querySelectorAll('.' + cfg.stepClass));
    var total = stepEls.length;
    var current = 0;
    // Set only when opened via a per-page "?" help shortcut (open(startStep, endStep))
    // — bounds Back/Next to that page's own steps instead of the whole tour, so
    // clicking Next never wanders into another page's navigation steps. Cleared
    // (both null) on a normal full-tour open.
    var scopeStart = null;
    var scopeEnd = null;
    function scopeFloor() { return scopeStart !== null ? scopeStart : 0; }
    function isLastStep() { return current >= (scopeEnd !== null ? scopeEnd : total - 1); }
    var dotsWrap = document.getElementById(cfg.idPrefix + 'Dots');
    var backBtn = document.getElementById(cfg.idPrefix + 'Back');
    var nextBtn = document.getElementById(cfg.idPrefix + 'Next');
    var skipBtn = document.getElementById(cfg.idPrefix + 'Skip');
    var targetClickHandler = null;
    var requireListeners = [];

    for (var i = 0; i < total; i++) {
        var dot = document.createElement('span');
        dot.className = 'h-1.5 w-1.5 rounded-full bg-gray-300';
        dotsWrap.appendChild(dot);
    }

    function clearTargetHandler() {
        if (targetClickHandler) {
            targetClickHandler.el.removeEventListener('click', targetClickHandler.fn);
            targetClickHandler = null;
        }
    }

    // data-require: a step can demand a real, non-mutating action (fill in dates,
    // add an item, pick a payment method, enter customer info) before Next unlocks —
    // checked live as the user types/selects in the real form fields.
    var requireValidators = {
        dates: function () {
            var sd = document.getElementById('rsd');
            var ed = document.getElementById('red');
            return !!(sd && ed && sd.value && ed.value);
        },
        items: function () {
            var cart = document.getElementById('cartItems');
            return !!(cart && cart.children.length > 0);
        },
        payment: function () {
            var method = document.getElementById('createPaymentMethod');
            if (!method || !method.value) return false;
            var amount = document.getElementById('amountPaid');
            return !amount || amount.value.trim() !== '';
        },
        customer: function () {
            var name = document.getElementById('rentCustomerName');
            var phone = document.getElementById('rentPhone');
            return !!(name && phone && name.value.trim() !== '' && phone.value.trim() !== '');
        },
    };
    var requireWatchFields = {
        dates: ['rsd', 'red'],
        items: [],
        payment: ['createPaymentMethod', 'amountPaid'],
        customer: ['rentCustomerName', 'rentPhone'],
    };

    function clearRequireListeners() {
        requireListeners.forEach(function (l) { l.el.removeEventListener(l.evt, l.fn); });
        requireListeners = [];
    }

    function setNextEnabled(enabled) {
        nextBtn.disabled = !enabled;
        nextBtn.classList.toggle('opacity-50', !enabled);
        nextBtn.classList.toggle('cursor-not-allowed', !enabled);
        nextBtn.classList.toggle('cursor-pointer', enabled);
    }

    function applyRequire(requireKey) {
        var validate = requireValidators[requireKey];
        if (!validate) { setNextEnabled(true); return; }

        function check() { setNextEnabled(validate()); }
        check();

        (requireWatchFields[requireKey] || []).forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('input', check);
            el.addEventListener('change', check);
            requireListeners.push({ el: el, evt: 'input', fn: check });
            requireListeners.push({ el: el, evt: 'change', fn: check });
        });

        if (requireKey === 'items') {
            var cart = document.getElementById('cartItems');
            if (cart) {
                var mo = new MutationObserver(check);
                mo.observe(cart, { childList: true });
                requireListeners.push({ el: { removeEventListener: function () { mo.disconnect(); } }, evt: '', fn: function () {} });
            }
        }
    }

    function drawAtRect(rect, preferLeft) {
        // centerDialog() sets an inline max-width to keep the box from overflowing
        // past the sidebar-adjusted content area — clear it here so it doesn't leak
        // into a spotlighted step's positioning if the previous step was centered.
        overlay.style.maxWidth = '';
        var pad = 6;
        spotlight.style.top = (rect.top - pad) + 'px';
        spotlight.style.left = (rect.left - pad) + 'px';
        spotlight.style.width = (rect.width + pad * 2) + 'px';
        spotlight.style.height = (rect.height + pad * 2) + 'px';
        spotlight.classList.remove('hidden');
        setClickBlockerHole(rect, pad);

        var dialogWidth = Math.min(overlay.offsetWidth || 448, window.innerWidth - 32);
        var spaceRight = window.innerWidth - rect.right;
        var spaceLeft = rect.left;
        var left, top;
        // preferLeft: for a target with a lot of content BELOW it that the step
        // wants left visible (e.g. the edit modal's item detail cards under the
        // "Selected items" header) — the default right/below placement would land
        // the dialog on top of exactly that content. Put it to the left instead
        // when there's room, before falling through to the normal logic.
        if (preferLeft && spaceLeft > dialogWidth + 24) {
            left = rect.left - dialogWidth - 16;
            top = Math.max(16, Math.min(rect.top, window.innerHeight - (overlay.offsetHeight || 260) - 16));
        } else if (spaceRight > dialogWidth + 24) {
            left = rect.right + 16;
            top = Math.max(16, Math.min(rect.top, window.innerHeight - (overlay.offsetHeight || 260) - 16));
        } else {
            left = Math.max(16, Math.min(rect.left, window.innerWidth - dialogWidth - 16));
            var dialogH = overlay.offsetHeight || 260;
            top = rect.bottom + 16;
            if (top + dialogH > window.innerHeight - 16) {
                top = rect.top - dialogH - 16;
                // A target taller than the viewport (a long list scrolled to its
                // top) has no usable "below" (rect.bottom is off-screen) and often
                // no usable "above" either (rect.top sits close to the viewport
                // top) — clamping to 16 here would still overlap the target's own
                // top edge, hiding exactly the first rows the step is pointing at.
                // Anchor to the viewport BOTTOM instead: for a long list the
                // visible portion is mostly "more of the same list" regardless of
                // where the dialog sits, so trading the bottom of the current
                // viewport is far less disruptive than covering the first rows.
                if (top < 16) {
                    top = window.innerHeight - dialogH - 16;
                }
            }
        }
        // Final safety clamp: every branch above computes `top` from
        // overlay.offsetHeight, which can be stale (still the PREVIOUS step's
        // height) on the very first measureAndDraw() call of a step, before the
        // new text has actually laid out and changed the box's real height. If
        // the real height ends up taller than assumed, the branch's math can
        // still leave the box extending past the viewport bottom with no later
        // correction (only rect-shift changes retrigger a redraw, not height
        // changes). Re-clamp against the CURRENT offsetHeight right before
        // painting so the box can never render partially off-screen.
        var dialogHNow = overlay.offsetHeight || 260;
        top = Math.min(top, window.innerHeight - dialogHNow - 16);
        top = Math.max(16, top);
        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
    }

    // positionToken guards against a fast Next/Back starting a new positioning pass
    // before an older one's poller has stopped, so the older pass can't land last
    // and paint the wrong step's box.
    var positionToken = 0;
    // Any target — small (a single button) or tall (a whole form section) — can end
    // up flush against the top edge when centered exactly, especially near the top
    // of a page. The dialog itself can also be taller than the target (long step
    // text), so a fixed minimum isn't enough — base the needed clearance on the
    // dialog's actual rendered height, capped to what the viewport can give
    // without pushing the target off-screen entirely.
    function requiredTopClearance() {
        var dialogH = overlay.offsetHeight || 260;
        return Math.min(dialogH + 32, window.innerHeight - 80);
    }

    // Targets keep moving after we first measure them — not just from their own
    // size changing (AJAX-loaded rental cards), but from content ABOVE them
    // resizing too (e.g. the Shift Control card's numbers filling in after an
    // async load), which shifts the target's position without the target's own
    // size ever changing. Rather than chase every possible cause with observers
    // (fragile — easy to miss a case), just re-measure on a plain interval for as
    // long as this step stays active: simple, and correct regardless of *why*
    // something moved. If the target briefly isn't laid out yet (zero-size rect),
    // skip that tick instead of drawing a degenerate box.
    var positionPollTimer = null;
    function stopPositionPoll() {
        if (positionPollTimer) { clearInterval(positionPollTimer); positionPollTimer = null; }
    }

    function positionNearTarget(targetEl, scrollBlock, preferLeft, targetSelector, useFirstChild) {
        var myToken = ++positionToken;
        stopPositionPoll();
        var lastScrollAttemptTop = null;
        // Centering a short target (a button, a field) makes sense — centering a
        // long list just lands somewhere in its middle, which looks like it
        // scrolled to a random spot rather than "to the list". data-scroll-block
        // lets a step ask for 'start' instead, landing at the list's actual top.
        var block = scrollBlock || 'center';

        // Drawing must never be gated on a scroll actually happening — some
        // targets (fixed-position sidebar links) don't move no matter how much
        // the page scrolls at all. And this app scrolls an INNER container, not
        // the window — window.scrollY stays 0 throughout, so window.scrollBy is a
        // silent no-op and window.scrollY can't be used to detect "did that do
        // anything". targetEl.scrollIntoView() is the one thing that reliably
        // finds and scrolls whatever ancestor actually scrolls, so re-use it for
        // the clearance nudge too, instead of manual window-relative math. Progress
        // is judged by whether the target's own rect.top actually moved.
        function measureAndDraw(allowScroll) {
            // A background refresh of the target's own container (e.g. the
            // rentals list re-polling for pending-approval badges) replaces
            // its innerHTML wholesale, detaching the exact node captured when
            // this step started. getBoundingClientRect() on a detached node
            // silently returns all-zero, which used to just freeze the
            // spotlight at its last good position forever (the 0x0 guard
            // below returns early with nothing re-resolving it). Re-query the
            // original selector fresh whenever that happens.
            if (!targetEl.isConnected && targetSelector) {
                var fresh = document.querySelector(targetSelector);
                if (fresh && useFirstChild && fresh.children.length) fresh = fresh.children[0];
                if (fresh) targetEl = fresh;
            }
            var rect = targetEl.getBoundingClientRect();
            if (rect.width === 0 && rect.height === 0) return;
            drawAtRect(rect, preferLeft);
            if (!allowScroll) return;
            var clearance = requiredTopClearance();
            var offscreen = rect.top < 0 || rect.bottom > window.innerHeight;
            if (rect.top < clearance || offscreen) {
                if (lastScrollAttemptTop !== null && Math.abs(lastScrollAttemptTop - rect.top) < 1) return; // last attempt moved nothing — give up nudging
                lastScrollAttemptTop = rect.top;
                targetEl.scrollIntoView({ block: block, behavior: 'smooth' });
            }
        }

        targetEl.scrollIntoView({ block: block, behavior: 'smooth' });
        measureAndDraw(true);
        // A fresh page load (resuming a step after a real navigation) can still be
        // settling layout — competing synchronous init elsewhere on the page — in
        // the instant this first runs. A couple of quick extra measurements a
        // frame apart catch that without waiting for the slower interval below.
        requestAnimationFrame(function () { if (myToken === positionToken) measureAndDraw(true); });
        requestAnimationFrame(function () { requestAnimationFrame(function () { if (myToken === positionToken) measureAndDraw(true); }); });
        // Only the initial burst above is allowed to actually scroll. Some targets
        // (e.g. the rentals list) sit inside a container that keeps refreshing
        // itself in the background (live polling for pending approvals) — each
        // refresh replaces that list's HTML and resets its scroll position, which
        // made this poll re-trigger scrollIntoView every ~200ms forever, fighting
        // the list's own refresh in a visible tug-of-war. Once positioned, just
        // keep the spotlight box accurate if the target's rect shifts — never
        // scroll again on this step's behalf.
        positionPollTimer = setInterval(function () {
            if (myToken !== positionToken) { stopPositionPoll(); return; }
            measureAndDraw(false);
        }, 200);
    }

    // Centering against the full viewport width looks visually off-center on
    // desktop — the fixed left sidebar (md:aside, ~288px) eats into that space
    // without a matching gap on the right, so a viewport-centered dialog reads as
    // shifted left relative to the actual content area. Center against the content
    // area (viewport minus the sidebar) instead, when the sidebar is actually
    // visible (it's hidden below the md: breakpoint, where this doesn't apply).
    function contentAreaLeftOffset() {
        var aside = document.querySelector('aside.md\\:fixed');
        if (!aside) return 0;
        var cs = window.getComputedStyle(aside);
        if (cs.display === 'none' || cs.position !== 'fixed') return 0;
        return aside.getBoundingClientRect().width;
    }

    function centerDialog() {
        spotlight.classList.add('hidden');
        setClickBlockerHole(null);
        var rootFontPx = parseFloat(window.getComputedStyle(document.documentElement).fontSize) || 16;
        var classCapRem = overlay.classList.contains('max-w-4xl') ? 56 : 32;
        var classCapPx = classCapRem * rootFontPx;
        overlay.style.maxWidth = Math.min(classCapPx, Math.max(200, window.innerWidth - 32)) + 'px';
        var dialogWidth = overlay.offsetWidth || 448;
        overlay.style.left = Math.max(16, (window.innerWidth - dialogWidth) / 2) + 'px';
        overlay.style.top = Math.max(16, (window.innerHeight - (overlay.offsetHeight || 260)) / 2) + 'px';
    }

    // A centered step (no real target to poll against) used to be positioned with
    // one single centerDialog() call and nothing after it — fine if the page is
    // already settled, but a step reached right after a real async action (e.g.
    // "Submitted!" right after a real submit) can still be mid-reflow:
    // the real success handler's own loadRentals() is itself an async fetch that
    // can finish well after this step starts showing, shifting the page's
    // scrollbar/width again with nothing to correct the now-stale centering.
    // Give it the same short self-correcting poll the target-based steps already
    // have, instead of a single guess at how long to wait.
    function startCenterDialogPoll() {
        var myToken = ++positionToken;
        stopPositionPoll();
        positionPollTimer = setInterval(function () {
            if (myToken !== positionToken) { stopPositionPoll(); return; }
            centerDialog();
        }, 200);
        setTimeout(function () {
            if (myToken === positionToken) stopPositionPoll();
        }, 2000);
    }

    // Tags the real create/edit forms with a hidden tutorial_practice flag for as
    // long as a step with data-tutorial-form is active, so the real rental/approval
    // the tour causes gets marked is_tutorial server-side and stays out of real
    // lists/reports — without this, practice actions mix into production data
    // exactly like the leftover test rentals/approvals from earlier in this
    // session that had to be manually found and cancelled.
    var rentalFieldsetOriginalDisabled = null;
    function applyTutorialFormFlag(activeFormId) {
        // expenseForm/journalEntryForm/fixedAssetForm/depreciateForm: the
        // Expense, Manual Journal Entry, and Fixed Assets tours' own practice
        // steps — same tutorial_practice flag, read by
        // ExpenseController/ManualJournalEntryController/FixedAssetController
        // to tag the resulting row is_tutorial_practice=true, which
        // ExcludesAccountingTrialData excludes from real reports
        // unconditionally (unlike the broader is_tutorial tag, which only
        // counts once Trial Mode is off — a form left out of this list
        // silently falls back to that weaker tag instead, staying mixed
        // into real data for as long as Trial Mode stays on).
        ['rentalForm', 'editForm', 'expenseForm', 'journalEntryForm', 'fixedAssetForm', 'depreciateForm'].forEach(function (formId) {
            var form = document.getElementById(formId);
            if (!form) return;
            var input = form.querySelector('input[name="tutorial_practice"]');
            if (formId === activeFormId) {
                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'tutorial_practice';
                    input.value = '1';
                    form.appendChild(input);
                }
            } else if (input) {
                input.remove();
            }
        });

        // The backend exempts tutorial submissions from the "open shift" check
        // (see rejectUnlessOpenShift), but the real form is still rendered with a
        // disabled <fieldset> at page-load time if no real shift is open — a
        // disabled fieldset silently excludes ALL its fields from FormData, so
        // every value the tour fills in gets submitted as blank regardless of
        // what the inputs visibly show. Force-enable it while in tutorial mode,
        // and restore its real (possibly still-locked) state on the way out so
        // a genuinely shift-less session doesn't stay unlocked afterward.
        var rentalFieldset = document.getElementById('rentalFormFieldset');
        if (rentalFieldset) {
            if (activeFormId === 'rentalForm') {
                if (rentalFieldsetOriginalDisabled === null) rentalFieldsetOriginalDisabled = rentalFieldset.disabled;
                rentalFieldset.disabled = false;
            } else if (rentalFieldsetOriginalDisabled !== null) {
                rentalFieldset.disabled = rentalFieldsetOriginalDisabled;
                rentalFieldsetOriginalDisabled = null;
            }
        }

        // The real item-search (/outfits/available) and rentals list
        // (/rentals/search) fetches read this flag to swap to tutorial-only data
        // (dedicated fake items, only this user's own practice rental) instead of
        // real inventory/bookings — see the matching `?tutorial=1` checks in
        // rentals-index.js and the two backend controllers. Re-fetch on change so
        // stale real-mode (or stale tutorial-mode) results don't linger visually
        // after switching.
        var wasTutorialMode = !!window.__rentalTutorialMode;
        var isTutorialMode = !!activeFormId;
        window.__rentalTutorialMode = isTutorialMode;
        if (wasTutorialMode !== isTutorialMode) {
            if (activeFormId === 'rentalForm' && typeof window.fetchOutfits === 'function') window.fetchOutfits();
            if (activeFormId === 'editForm' && typeof window.loadRentals === 'function') window.loadRentals();
            if (!isTutorialMode && typeof window.loadRentals === 'function') window.loadRentals();
        }
    }

    function render() {
        // installRentCreateFetchWatch() (and the other background
        // fetch/event watchers) can call current++/render() at ANY time a
        // matching real request succeeds — including long after this tour
        // was closed, since closing only hides the overlay and resets the
        // *visible* state, not the in-memory `current` pointer. Without this
        // guard, that background render() still runs every one of its side
        // effects (toggling window.__rentalTutorialMode, injecting the
        // tutorial_practice hidden field, writing RESUME_KEY) even though
        // nothing is on screen — which can silently flip a totally unrelated
        // REAL action (e.g. a normal Create Rent submit made long after the
        // tour was skipped) into tutorial mode, making the real rentals list
        // suddenly filter itself down to "no rents found". A closed tour has
        // no business doing any of this.
        if (overlay.classList.contains('hidden')) return;
        positionToken++; // invalidate any in-flight positioning pass from the previous step
        // Keeps resume state accurate regardless of HOW this step was reached
        // — a real nav-link click already queues the NEXT index for the page
        // load that's about to happen (see the click handler below), but the
        // AJAX-driven auto-advance steps (edit modal opening, form submit,
        // approval decided) change `current` in place with no page load at
        // all, and were never recorded here otherwise. Without this, a
        // refresh after one of those falls back to wherever the last REAL
        // navigation left off, not the step actually being shown.
        persistResumeState(current);
        clearTargetHandler();
        clearRequireListeners();
        clearAutoAdvanceWatchers();
        stopPositionPoll();
        setNextEnabled(true);
        stepEls.forEach(function (el, idx) {
            el.classList.toggle('hidden', idx !== current);
        });
        Array.prototype.forEach.call(dotsWrap.children, function (dot, idx) {
            dot.className = 'h-1.5 w-1.5 rounded-full ' + (idx === current ? 'bg-indigo-600' : 'bg-gray-300');
        });
        backBtn.classList.toggle('hidden', current === scopeFloor());
        var stepCounterEl = document.getElementById(cfg.idPrefix + 'StepCounter');
        if (stepCounterEl) {
            stepCounterEl.textContent = scopeEnd !== null
                ? '(' + (current - scopeStart + 1) + '/' + (scopeEnd - scopeStart + 1) + ')'
                : '(' + (current + 1) + '/' + total + ')';
        }

        var activeStep = stepEls[current];
        applyTutorialFormFlag(activeStep ? activeStep.getAttribute('data-tutorial-form') : null);
        // Most steps are a couple of sentences and look sparse in a wide dialog —
        // only the step with the full 2-column card mockup needs the extra width.
        var wideCard = activeStep ? activeStep.hasAttribute('data-wide-card') : false;
        overlay.classList.toggle('max-w-lg', !wideCard);
        overlay.classList.toggle('max-w-4xl', wideCard);
        // data-tutorial-seed: this step's real nav link leads somewhere that needs
        // tutorial-only practice data to already exist first — unlike Admin,
        // Supervisor/Management edit rentals directly (no approval created), so
        // there's nothing real for them to practice approving unless one is
        // seeded. Carry `?tutorial=1` on the real link's href so the resulting
        // page load already renders tutorial-only content server-side — no
        // client-side JS state needs to survive the real navigation.
        var tutorialSeedKind = activeStep ? activeStep.getAttribute('data-tutorial-seed') : null;
        // Kept outside this block (module-scope `var`, not declared here)
        // so the real-click handler further down can wait on it — a plain
        // fetch() isn't guaranteed to finish once the real link navigation
        // it's racing against actually unloads the page, so letting the
        // click fire immediately can land on the next page before the
        // practice data it depends on actually exists yet.
        tutorialSeedPromise = null;
        if (tutorialSeedKind && tutorialSeedConfig[tutorialSeedKind]) {
            var seedCfg = tutorialSeedConfig[tutorialSeedKind];
            var seedNavLink = document.getElementById(seedCfg.navLinkId);
            if (seedNavLink) {
                try {
                    var seedUrl = new URL(seedNavLink.getAttribute('href'), window.location.href);
                    seedUrl.searchParams.set('tutorial', '1');
                    seedNavLink.setAttribute('href', seedUrl.pathname + seedUrl.search);
                } catch (e) {}
            }
            tutorialSeedPromise = fetch(seedCfg.endpoint, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Accept': 'application/json',
                },
            }).catch(function () {});
        }

        var targetSelector = activeStep ? activeStep.getAttribute('data-target') : null;
        var targetEl = targetSelector ? document.querySelector(targetSelector) : null;
        // data-target-first-child: spotlight just the target's first child instead
        // of the whole element — for a long list (e.g. #rentalCards) that fills the
        // entire viewport height, there is no dialog position that avoids
        // overlapping SOME row; narrowing the actual spotlighted/measured target to
        // one representative card makes it small enough to sit beside cleanly,
        // while the step's own text ("pick any rental below") still applies to the
        // whole list — only the highlight target itself is narrowed.
        var useFirstChild = !!(activeStep && activeStep.hasAttribute('data-target-first-child'));
        if (targetEl && useFirstChild && targetEl.children.length) {
            targetEl = targetEl.children[0];
        }
        // data-target-readonly: spotlight a real element purely for visual guidance,
        // without wiring a real click to advance — used for anything that mutates data
        // (Create Rent, Submit for Approval, Approve/Reject), where forcing a real click
        // would create or change real data as a side effect of the tutorial.
        var isReadonly = activeStep ? activeStep.hasAttribute('data-target-readonly') : false;
        var requireKey = activeStep ? activeStep.getAttribute('data-require') : null;
        if (requireKey) applyRequire(requireKey);
        // data-auto-advance: continues the practice flow inline, in the same dialog,
        // instead of handing off to a separate flow — the step spotlights a real
        // element and waits for the real async event (edit modal opening, form
        // submitting) to advance itself, rather than Next.
        var autoAdvance = activeStep ? activeStep.getAttribute('data-auto-advance') : null;
        // data-finish-action: a step whose own embedded button (not the standard
        // Next) performs the real action (cancelling the practice request) before
        // advancing — Next stays hidden so there's nothing to click past it with.
        var finishAction = activeStep ? activeStep.hasAttribute('data-finish-action') : false;
        var scrollBlock = activeStep ? activeStep.getAttribute('data-scroll-block') : null;
        // data-prefer-left: put the dialog to the LEFT of the target when
        // there's room, instead of the default right/below — for a target
        // with a lot of content BELOW it that the step wants to keep visible
        // (e.g. the edit modal's item detail cards under the Selected items
        // header), the default placement would land the dialog right on top
        // of exactly that content.
        var preferLeft = !!(activeStep && activeStep.hasAttribute('data-prefer-left'));

        if (targetEl && autoAdvance) {
            nextBtn.classList.add('hidden');
            // "Now try an edit" spotlights the rentals list and waits for a real
            // Edit click to open the modal — but Back can land here FROM a later
            // step (e.g. "Change something") where the modal is expected to already be
            // open. Landing here with it still open spotlights #rentalCards while
            // it's hidden behind the modal — broken position, and no Next because
            // this step is auto-advance-only by design. Close it first so the step's
            // real precondition (modal closed, list clickable) actually holds.
            if (autoAdvance === 'edit-opened') {
                var openEditModal = document.getElementById('editModal');
                if (openEditModal && !openEditModal.classList.contains('hidden') && typeof window.closeModal === 'function') {
                    window.closeModal();
                }
            }
            positionNearTarget(targetEl, scrollBlock, preferLeft, targetSelector, useFirstChild);
            if (autoAdvance === 'edit-opened') watchEditModalOpen();
            else if (autoAdvance === 'edit-submitted') watchEditFormSubmit();
            else if (autoAdvance === 'rent-created') watchRentCreateSubmit();
            else if (autoAdvance === 'approval-decided') watchApprovalDecided();
            else if (autoAdvance === 'items-changed') watchEditItemsChanged();
            else if (autoAdvance === 'opname-marked') watchOpnameMarked();
            else if (autoAdvance === 'late-done-sent') watchLateDoneSent();
        } else if (finishAction) {
            nextBtn.classList.add('hidden');
            centerDialog();
            startCenterDialogPoll();
            wireFinishButton();
        } else if (targetEl && !isReadonly) {
            nextBtn.classList.remove('hidden');
            nextBtn.textContent = 'Skip this step';
            positionNearTarget(targetEl, scrollBlock, preferLeft, targetSelector, useFirstChild);
            var seedPromiseForClick = tutorialSeedPromise;
            var fn = seedPromiseForClick ? function (e) {
                // This step's real link leads somewhere that needs the seed
                // request above to have actually landed first — block the
                // real navigation until it resolves, then navigate manually,
                // instead of letting the click's own navigation race it (a
                // page unload can abort an in-flight fetch before it finishes).
                e.preventDefault();
                persistResumeState(current + 1);
                var href = targetEl.getAttribute('href');
                seedPromiseForClick.then(function () { window.location.href = href; });
            } : function () {
                persistResumeState(current + 1);
                // Let the real navigation happen — this is a genuine sidebar link click.
            };
            targetEl.addEventListener('click', fn);
            targetClickHandler = { el: targetEl, fn: fn };
        } else if (targetEl && isReadonly) {
            nextBtn.classList.remove('hidden');
            nextBtn.textContent = isLastStep() ? 'Got it' : 'Next';
            positionNearTarget(targetEl, scrollBlock, preferLeft, targetSelector, useFirstChild);
        } else {
            nextBtn.classList.remove('hidden');
            nextBtn.textContent = isLastStep() ? 'Got it' : 'Next';
            centerDialog();
            startCenterDialogPoll();
        }
    }

    // Practice-flow state: which rental the user opened for editing, and the
    // resulting pending approval id (captured from the real save response) so the
    // "Finish" step can cancel it for real.
    var practiceRentalId = null;
    var practiceApprovalId = null;

    function clearAutoAdvanceWatchers() {
        if (editModalObserver) { editModalObserver.disconnect(); editModalObserver = null; }
        if (approvalDecidedHandler) { document.removeEventListener('rental:approval-changed', approvalDecidedHandler); approvalDecidedHandler = null; }
        if (editItemsChangeObserver) { editItemsChangeObserver.disconnect(); editItemsChangeObserver = null; }
        if (opnameMarkedHandler) { document.removeEventListener('rental:opname-marked', opnameMarkedHandler); opnameMarkedHandler = null; }
        if (lateDoneSentHandler) { document.removeEventListener('rental:late-done-sent', lateDoneSentHandler); lateDoneSentHandler = null; }
    }

    function editCartIdsSignature() {
        var cart = window.editCart || {};
        return Object.keys(cart).map(Number).sort(function (a, b) { return a - b; }).join(',');
    }

    // "Change something" (items-changed): waits for the real cart to actually
    // change, then hands off to the next step ("This is what changed") — split
    // into two steps instead of one step that silently re-spotlights itself
    // mid-way (checking an item used to shift the highlight from the item
    // list to the Selected items panel without advancing `current` at all),
    // which made Back from later steps jump straight past both phases to
    // before the modal was even open.
    var editItemsChangeObserver = null;
    function watchEditItemsChanged() {
        clearAutoAdvanceWatchers();
        var editCartEl = document.getElementById('editOutfitCart');
        if (!editCartEl) return;
        var myIndex = current;
        var baseline = editCartIdsSignature();
        editItemsChangeObserver = new MutationObserver(function () {
            if (current !== myIndex) { clearAutoAdvanceWatchers(); return; }
            if (editCartIdsSignature() !== baseline) {
                clearAutoAdvanceWatchers();
                current++;
                render();
            }
        });
        editItemsChangeObserver.observe(editCartEl, { childList: true });
    }

    // Approve/Reject on the real card is an AJAX fetch (app.js's global submit
    // handler), not a real page navigation — there's no page load to resume
    // the tour on, unlike the nav-link steps. app.js already dispatches
    // rental:approval-changed on success (it uses this itself to update
    // sidebar badges), so listen for that instead of re-wrapping fetch again.
    var approvalDecidedHandler = null;
    function watchApprovalDecided() {
        clearAutoAdvanceWatchers();
        var myIndex = current;
        approvalDecidedHandler = function () {
            if (current !== myIndex) return;
            clearAutoAdvanceWatchers();
            current++;
            render();
        };
        document.addEventListener('rental:approval-changed', approvalDecidedHandler);
    }

    // Stock Opname's mark button is also an AJAX fetch (see stock-opname/show
    // .blade.php's inline script), not a page navigation — same shape as
    // watchApprovalDecided() above, just listening for the event that
    // handler dispatches on a successful mark instead.
    var opnameMarkedHandler = null;
    function watchOpnameMarked() {
        clearAutoAdvanceWatchers();
        var myIndex = current;
        opnameMarkedHandler = function () {
            if (current !== myIndex) return;
            clearAutoAdvanceWatchers();
            current++;
            render();
        };
        document.addEventListener('rental:opname-marked', opnameMarkedHandler);
    }

    // "Report pickup/return" (rentals-index.js) is an AJAX send from a dialog —
    // it dispatches this event on success, same shape as approval-changed.
    var lateDoneSentHandler = null;
    function watchLateDoneSent() {
        clearAutoAdvanceWatchers();
        var myIndex = current;
        lateDoneSentHandler = function () {
            if (current !== myIndex) return;
            clearAutoAdvanceWatchers();
            current++;
            render();
        };
        document.addEventListener('rental:late-done-sent', lateDoneSentHandler);
    }

    var editModalObserver = null;
    function watchEditModalOpen() {
        var editModal = document.getElementById('editModal');
        if (!editModal) return;
        var myIndex = current;
        clearAutoAdvanceWatchers();
        editModalObserver = new MutationObserver(function () {
            if (current !== myIndex) { clearAutoAdvanceWatchers(); return; }
            if (editModal.classList.contains('hidden')) return;
            var form = document.getElementById('editForm');
            var match = form && form.action ? form.action.match(/\/rentals\/(\d+)/) : null;
            if (match) {
                practiceRentalId = match[1];
                editModalObserver.disconnect();
                // Watch for the real submit from the moment the modal opens, not only
                // once the tour's own step 9 becomes active — nothing stops a real
                // click on the real Save/Submit button while still on step 8 ("Change
                // something"), and that used to go completely unheard, leaving the tour
                // stuck on step 8 after the modal it depends on had already closed.
                watchEditFormSubmit();
                current++;
                render();
            }
        });
        editModalObserver.observe(editModal, { attributes: true, attributeFilter: ['class'] });
    }

    var editSubmitHandler = null;
    var fetchWrapped = false;
    function watchEditFormSubmit() {
        var form = document.getElementById('editForm');
        if (!form) return;
        if (editSubmitHandler) form.removeEventListener('submit', editSubmitHandler);
        editSubmitHandler = function () { wrapFetchOnce(); };
        form.addEventListener('submit', editSubmitHandler);
    }

    // Captures the resulting pending approval's id from the real save response, by
    // briefly wrapping fetch — nothing here calls the network itself, it just reads
    // what the real submit already sends, so it can be cancelled later.
    function wrapFetchOnce() {
        if (fetchWrapped) return;
        fetchWrapped = true;
        var originalFetch = window.fetch;
        window.fetch = function (input, init) {
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            var isRentalUpdate = /\/rentals\/\d+(\?.*)?$/.test(url) && init && String(init.method || '').toUpperCase() === 'POST';
            var promise = originalFetch.apply(this, arguments);
            if (isRentalUpdate) {
                window.fetch = originalFetch;
                fetchWrapped = false;
                promise.then(function (res) {
                    res.clone().json().then(function (data) {
                        if (data && data.mode === 'edit_approval_request_sent' && data.pending_edit_approval_id) {
                            practiceApprovalId = data.pending_edit_approval_id;
                            // Jump straight to the finish step by identity, not by
                            // current++ — the submit can legitimately happen from
                            // step 8 OR step 9 now that the watcher is live for both,
                            // and a plain increment from step 8 would land on step 9
                            // instead of the real finish step, which is just as
                            // "stuck" (targets a save button inside a modal that's
                            // already closing for real).
                            var finishIdx = stepEls.findIndex(function (el) { return el.hasAttribute('data-post-submit-landing'); });
                            current = finishIdx >= 0 ? finishIdx : current + 1;
                            // This handler was attached to the fetch promise BEFORE the
                            // real submit handler's own .then() (ours wraps fetch and
                            // subscribes internally, before returning the promise to the
                            // real caller) — so calling render() here runs synchronously
                            // BEFORE the real handler's closeModal()/loadRentals(), while
                            // the modal is technically still open (body scroll still
                            // locked, layout not yet settled). centerDialog() is a
                            // one-shot measurement with no later re-correction, so it was
                            // centering against a transient layout instead of the real
                            // final one. Deferring to a macrotask lets the real handler's
                            // synchronous DOM/layout changes finish first.
                            setTimeout(function () { render(); }, 50);
                        }
                    }).catch(function () {});
                }).catch(function () {});
            }
            return promise;
        };
    }

    // The real "Create Rent" submit handler was already attached to #rentalForm at
    // page load, long before the tour reaches this step — installing our own fetch
    // wrap reactively (e.g. from a 'submit' listener added only once this step
    // becomes active) loses the race: the real handler's listener runs first and
    // already calls the real (unwrapped) fetch before ours gets a chance to wrap
    // it, so the "successful create" is never observed. Wrapping fetch once, here,
    // at tour script init time (independent of step/submit timing entirely) avoids
    // that race — this wrap is always in place before any real submit can happen.
    (function installRentCreateFetchWatch() {
        // Only the main tour has a 'rent-created' step at all (the removal
        // tour never creates a rental) — skip installing this instance's
        // copy entirely rather than wrapping window.fetch a second,
        // functionally-dead time for every fetch call site-wide.
        if (stepEls.findIndex(function (el) { return el.getAttribute('data-auto-advance') === 'rent-created'; }) < 0) return;
        var originalFetch = window.fetch;
        if (!originalFetch) return;
        window.fetch = function (input, init) {
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            var method = (init && init.method) || '';
            var isCreate = /\/rentals(\?.*)?$/.test(url) && String(method).toUpperCase() === 'POST';
            var promise = originalFetch.apply(this, arguments);
            if (isCreate) {
                // Same class of gap as the edit-flow fix above: nothing stops a real
                // click on the real "Create Rent" button while the tour is still on
                // an EARLIER New Rent step (dates/items/payment/customer) — the
                // button works the whole time regardless of which step the dialog is
                // currently narrating. Gating on "current step must literally be the
                // create step" missed that entirely, leaving the tour stuck on an
                // early step after a real rent was already created. Accept success
                // from any step up to and including the create step, and jump to the
                // step right after it by identity, not by a blind current++.
                var createStepIdx = stepEls.findIndex(function (el) { return el.getAttribute('data-auto-advance') === 'rent-created'; });
                var isBeforeOrAtCreateStep = createStepIdx >= 0 && current <= createStepIdx;
                if (isBeforeOrAtCreateStep) {
                    promise.then(function (res) {
                        res.clone().json().then(function (data) {
                            if (data && data.ok) {
                                current = createStepIdx + 1;
                                render();
                            }
                        }).catch(function () {});
                    }).catch(function () {});
                }
            }
            return promise;
        };
    })();

    function watchRentCreateSubmit() {
        // No-op: kept as a named call-site in render()'s auto-advance dispatch for
        // symmetry with watchEditModalOpen/watchEditFormSubmit — the actual watch is
        // a permanent fetch wrap installed once above, not a per-step listener.
    }

    var finishBtnHandler = null;
    function wireFinishButton() {
        var btn = document.getElementById(cfg.idPrefix + 'FinishPractice');
        if (!btn) return;
        if (finishBtnHandler) btn.removeEventListener('click', finishBtnHandler.fn);
        finishBtnHandler = { el: btn, fn: function () {
            // This is the LAST step (Done) — advancing further would run
            // past the end of stepEls, so finish by closing the tour instead
            // of current++/render(), same as the normal Next button's own
            // last-step branch.
            function advance() {
                if (isLastStep()) close(true);
                else { current++; render(); }
            }
            if (!practiceApprovalId) { advance(); return; }
            btn.disabled = true;
            btn.textContent = 'Cancelling…';
            fetch('/approvals/rentals/' + practiceApprovalId + '/cancel', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            }).finally(function () {
                practiceApprovalId = null;
                advance();
            });
        } };
        btn.addEventListener('click', finishBtnHandler.fn);
    }

    // Fire-and-forget: deletes every tutorial rental + approval request this
    // user has, regardless of status. Used both when a tour ends (skip,
    // Escape, or reaching the last step — a safety net alongside the finish
    // step's own single real /cancel call) and right before a FRESH tour
    // starts (never on resume — that would delete the very practice data the
    // resumed step depends on), so an abandoned run (closed tab, browser
    // crash) doesn't leave its practice request sitting in "My Requests"
    // forever.
    function cleanupTutorialData() {
        fetch(cfg.cleanupEndpoint || @json(route('approvals.tutorial-cleanup')), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
            },
        }).catch(function () {});
    }

    function open(resumeAt, endAt) {
        current = resumeAt || 0;
        if (current >= total) current = 0;
        scopeEnd = typeof endAt === 'number' ? endAt : null;
        scopeStart = scopeEnd !== null ? current : null;
        overlay.classList.remove('hidden');
        // Used to lock document.body's own scroll here — needed back when the
        // click-blocker didn't exist yet, as the only way to stop the user
        // scrolling away from the spotlighted target. Now redundant (the
        // blocker already gates every click to the hole) AND actively wrong:
        // a step whose target grows taller than the viewport (e.g. expanding
        // "View details" on a long approval card) has no way to reach its own
        // lower half with body scroll locked. The position poll already
        // re-syncs the spotlight/hole to wherever the target ends up
        // regardless of scroll position, so real scrolling is always safe.
        render();
    }

    function startFreshTour() {
        cleanupTutorialData();
        open(0);
    }

    function close(markSeen) {
        clearTargetHandler();
        clearRequireListeners();
        clearAutoAdvanceWatchers();
        stopPositionPoll();
        applyTutorialFormFlag(null);
        overlay.classList.add('hidden');
        spotlight.classList.add('hidden');
        clickBlocker.classList.add('hidden');
        document.body.style.overflow = '';
        if (markSeen) {
            try { localStorage.setItem(STORAGE_KEY, '1'); } catch (e) {}
        }
        try { sessionStorage.removeItem(RESUME_KEY); sessionStorage.removeItem(SCOPE_KEY); } catch (e) {}
        cleanupTutorialData();
    }

    nextBtn.addEventListener('click', function () {
        if (isLastStep()) {
            close(true);
            return;
        }
        current++;
        render();
    });
    // A step whose data-target lives inside something a real, irreversible
    // action already removed (e.g. an approval card, gone from the DOM the
    // moment it's decided) has nothing left to spotlight if the user goes
    // Back to it — it would fall through to a bare centered dialog still
    // instructing them to click a target that no longer exists. Skip past
    // any such dead step automatically instead of stopping on it.
    function stepTargetExists(idx) {
        var el = stepEls[idx];
        if (!el) return true;
        // data-target-optional: this step's target is legitimately absent for
        // SOME viewers by design (e.g. a store-switcher only rendered when the
        // viewer has more than one store) — not the transient "stale practice
        // data" case this whole check exists for. Treat it as always resumable;
        // render()'s own normal fallback already degrades a missing target to
        // a plain centered step with no spotlight, same as reaching it via
        // Next/Back would. Without this, resuming here for exactly the
        // viewers it doesn't apply to nukes the whole tour back to step 0 on
        // every single page load — a real, reported infinite loop.
        if (el.hasAttribute('data-target-optional')) return true;
        var sel = el.getAttribute('data-target');
        if (!sel) return true;
        var found = document.querySelector(sel);
        if (!found) return false;
        if (el.hasAttribute('data-target-first-child')) {
            // The container itself (e.g. #editRequestsPendingList) can still
            // exist even when empty — an empty pending list after a decision
            // is exactly the "nothing left to spotlight" case this whole
            // check exists for, so a container with zero children counts as
            // not existing, not as "found, spotlight the empty box".
            if (!found.children.length) return false;
        }
        return true;
    }
    // Re-creates the practice pending request (same idempotent seed endpoint
    // the "Now: approving a request" step already calls) and reloads,
    // resuming directly at targetIdx — so going Back into the card-dependent
    // steps after a decision brings a fresh, live card back instead of
    // skipping past them.
    function reseedAndResumeAt(targetIdx, seedKind) {
        var seedCfg = tutorialSeedConfig[seedKind];
        try { sessionStorage.setItem(RESUME_KEY, String(targetIdx)); } catch (e) {}
        if (!seedCfg) { window.location.reload(); return; }
        fetch(seedCfg.endpoint, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
            },
        }).finally(function () { window.location.reload(); });
    }
    backBtn.addEventListener('click', function () {
        var idx = current - 1;
        while (idx > scopeFloor() && !stepTargetExists(idx)) {
            var seedKind = stepEls[idx] ? stepEls[idx].getAttribute('data-requires-pending-seed') : null;
            if (seedKind) {
                reseedAndResumeAt(idx, seedKind);
                return;
            }
            idx--;
        }
        if (idx < current) {
            current = idx;
            render();
        }
    });
    skipBtn.addEventListener('click', function () { close(true); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !overlay.classList.contains('hidden')) close(true);
    });
    window.addEventListener('resize', function () {
        if (overlay.classList.contains('hidden')) return;
        render();
    });
    var scrollRaf = null;
    window.addEventListener('scroll', function () {
        if (overlay.classList.contains('hidden') || spotlight.classList.contains('hidden')) return;
        if (scrollRaf) return;
        scrollRaf = requestAnimationFrame(function () {
            scrollRaf = null;
            var el = targetClickHandler ? targetClickHandler.el : (function () {
                var step = stepEls[current];
                var sel = step ? step.getAttribute('data-target') : null;
                return sel ? document.querySelector(sel) : null;
            })();
            if (el) {
                var rect = el.getBoundingClientRect();
                var pad = 6;
                spotlight.style.top = (rect.top - pad) + 'px';
                spotlight.style.left = (rect.left - pad) + 'px';
            }
        });
    }, true);

    // Optional numeric args jump straight to that step, for the per-page "?"
    // help shortcuts: a startAtStep alone still jumps into the full tour
    // (Next keeps walking into later pages); passing endAtStep too scopes
    // Back/Next to just that step range, so Next never wanders past the
    // current page's own steps. Omit both for the normal from-the-start open.
    window[cfg.openFnName] = function (startAtStep, endAtStep) {
        open(typeof startAtStep === 'number' ? startAtStep : 0, endAtStep);
    };

    // Lets a step's own embedded button (e.g. Stock Opname's "Start Practice
    // Session Now" — a fetch-then-navigate action outside the standard
    // Next/target-click paths render() already wires) persist where the
    // tour should resume on the page it navigates to next, the same way the
    // real-nav-link click handler in render() does internally.
    window[cfg.idPrefix + 'PersistResume'] = persistResumeState;

    @if(config('app.debug'))
    // Debug-only: jump straight to any step without walking through the real
    // prerequisite actions (dates/items/payment/customer/create/edit) each time —
    // useful for checking a single step's layout in isolation.
    // <name>(10)             -> by index
    // <name>('Submitted')     -> by a substring of its <h3> title
    // <name>('wide')         -> first step with data-wide-card="1"
    window[cfg.debugFnName] = function (target) {
        var idx = -1;
        if (typeof target === 'number') {
            idx = target;
        } else if (target === 'wide') {
            idx = stepEls.findIndex(function (el) { return el.hasAttribute('data-wide-card'); });
        } else if (typeof target === 'string') {
            var needle = target.toLowerCase();
            idx = stepEls.findIndex(function (el) {
                var h3 = el.querySelector('h3');
                return h3 && h3.textContent.toLowerCase().indexOf(needle) !== -1;
            });
        }
        if (idx < 0 || idx >= total) {
            console.warn('[' + cfg.debugFnName + '] no matching step for', target, '— steps:', stepEls.map(function (el, i) {
                var h3 = el.querySelector('h3');
                return i + ': ' + (h3 ? h3.textContent : '(no title)');
            }));
            return;
        }
        open(idx);
    };
    @endif

    // Whatever triggers the initial open — resuming a step after a real page
    // reload, or opening fresh — must wait for the DOM to actually be ready first.
    // The resume path used to skip this and call open() immediately at script
    // parse time; on a real navigation (unlike a same-page open() call later) that
    // can run before the target element has settled into its final layout,
    // leaving the very first positioning pass with nothing valid to measure.
    function whenDomReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    var resumeAt = null;
    var resumeScopeEnd = null;
    try {
        var raw = sessionStorage.getItem(RESUME_KEY);
        if (raw !== null) resumeAt = parseInt(raw, 10);
        var rawScope = sessionStorage.getItem(SCOPE_KEY);
        if (rawScope !== null) resumeScopeEnd = parseInt(rawScope, 10);
    } catch (e) {}
    if (resumeScopeEnd !== null && Number.isNaN(resumeScopeEnd)) resumeScopeEnd = null;

    if (resumeAt !== null && !Number.isNaN(resumeAt) && resumeAt < total) {
        // Claimed synchronously (not deferred at all) so it's already set by
        // the time any OTHER tour's autoOpenOnFirstVisit check runs — that
        // branch defers itself an extra setTimeout(0) tick specifically so
        // every resume-branch claim like this one, across every tour partial
        // on the page, always lands first regardless of include order.
        window.__rentalTourAutoOpenClaimed = true;
        // A queued resume step can be stale by the time this next page
        // actually loads — e.g. the practice request it depended on already
        // got decided in another tab, or (as seen live) the resume index was
        // recorded before that. Resuming into a step with nothing real left
        // to show is worse than just starting over: the seed step re-creates
        // a fresh practice request anyway, so restarting is a full recovery,
        // not a downgrade.
        whenDomReady(function () {
            if (stepTargetExists(resumeAt)) {
                open(resumeAt, resumeScopeEnd === null ? undefined : resumeScopeEnd);
            } else {
                startFreshTour();
            }
        });
    } else if (cfg.autoOpenOnFirstVisit) {
        var seen = false;
        try { seen = localStorage.getItem(STORAGE_KEY) === '1'; } catch (e) {}
        if (!seen) {
            whenDomReady(function () {
                // Deferred an extra tick (not just whenDomReady) on purpose: by
                // the time these <script> tags near the end of <body> actually
                // run, document.readyState is often already past 'loading', so
                // whenDomReady's own callback fires SYNCHRONOUSLY — meaning
                // whichever tour partial happens to be included first would
                // win this check before a later tour's resume-branch (which
                // claims the flag synchronously in ITS OWN rentalCreateTour()
                // call, further down the page) ever got a chance to run.
                // setTimeout(0) pushes this check to a fresh task, guaranteed
                // to run after every tour's top-level registration code across
                // the whole page has finished — order-independent.
                setTimeout(function () {
                    // Another tour is already resuming mid-walkthrough on this
                    // same page load (e.g. the viewer just clicked a real nav
                    // link inside the Accounting tour) — don't stack a second
                    // full-screen tour on top of it. This "first visit" nudge
                    // just waits for a later page load where nothing else
                    // claims the slot first; nothing is lost, since `seen` in
                    // localStorage is only set once the viewer actually
                    // finishes or skips it.
                    if (window.__rentalTourAutoOpenClaimed) return;
                    window.__rentalTourAutoOpenClaimed = true;
                    startFreshTour();
                }, 0);
            });
        }
    }

    document.querySelectorAll(cfg.triggerSelector).forEach(function (btn) {
        btn.addEventListener('click', function () { startFreshTour(); });
    });
}

window.rentalCreateTour = rentalCreateTour;
</script>
