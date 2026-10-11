        // _viewerDiscountLockExempt is set by index-script.blade.php (role-based:
        // true only for admin, whose discount edits always go through the
        // edit-approval queue for a human to decide).
        // The Blade template bakes min="today" into #editSd/#editEd at render time (see
        // edit-modal.blade.php). That static floor conflicts with an existing rental's own
        // saved date once it's overdue (its date is now in the past), silently blocking the
        // native form submit. Whenever we populate the field with the rental's real value,
        // relax min down to that value so it stays a valid, submittable date.
        // Practice (tour) rents share id numbers with real rents, so every request about one
        // carries ?tutorial=1 and the server looks in the practice table first.
        function rentalTutorialQs(prefix) {
            return window.__rentalTutorialMode ? (prefix || '?') + 'tutorial=1' : '';
        }
        function lowerEditDateMinToValue(el, ymd) {
            if (!el || !ymd) return;
            var currentMin = el.getAttribute('min');
            if (!currentMin || ymd < currentMin) {
                el.setAttribute('min', ymd);
            }
        }
        // Always open the rents page at the top: the browser otherwise restores
        // the previous visit's scroll position (annoying after login/nav-back).
        // The receipt-return flow restores its own scroll explicitly and is kept.
        // The approval tour also restores its own scroll (to whichever real form
        // section the resumed step spotlights) — pageshow fires late, well after
        // the tour's inline script has already positioned itself (it runs at
        // HTML-parse time; pageshow waits for the whole page to finish loading),
        // so without this exemption this reset silently undid the tour's
        // positioning moments after it ran, with no further correction once the
        // tour's own retry logic had already given up.
        try { if ('scrollRestoration' in history) history.scrollRestoration = 'manual'; } catch (e) {}
        window.addEventListener('pageshow', function () {
            var pendingReceiptReturn = false;
            var pendingTourResume = false;
            try {
                pendingReceiptReturn = !!sessionStorage.getItem('__RENTAL_RECEIPT_RETURN');
                pendingTourResume = Object.keys(sessionStorage).some(function (k) { return k.indexOf('rental_tour_resume_v1_') === 0; });
            } catch (e) {}
            if (!pendingReceiptReturn && !pendingTourResume) window.scrollTo(0, 0);
        });

        /** Management / super_admin: poll even with an empty queue so new staff requests appear without refresh (reload when snapshot changes). */
        /** Admin (staff): poll every 5s on Rentals to see if cool-down requests are still pending or were approved — no refresh needed. */
        /** Super admins create cool-down bookings via Create Rent (store); the cooldown shortcut endpoint rejects them. */
        var cart = {};
        /** Edit modal cart & takeover metadata (declared early — pricing helpers run before edit-modal section). */
        var editCart = {}; // id => enriched item object
        var takeoverFrom = {}; // outfitId => source rental id
        var currentEditRentalOutfitsMeta = {};
        /** Cumulative amount already saved on the rent (server); used with increment payment entry in Quick edit. */
        var editPaymentBasePaid = 0;
        /** Original down payment amount recorded when rental was first created as down_payment. Null if not a down_payment rental. */
        var editDownPaymentAmount = null;
        /** Cumulative overdue charge payment already saved on the rent (server). */
        var editOverduePaymentBasePaid = 0;
        /** Change (cash to return) from the last overdue payment. */
        var editOverduePaymentBaseChange = 0;
        /** 'replace' | 'increment' — controls whether #editAmountPaid posts as amount_paid or payment_amount_add. */
        var editPaymentFieldMode = null;
        /** null | 'full' | 'down_payment' — stored kind for the rent open in Quick edit; drives payment field lock. */
        var currentEditRentalStoredPaymentKind = null;
        /** Saved payment used when inputs are disabled so submit/fetch still posts correct values. */
        var editPaymentLockSnapshot = null;
        /** Last known total due (cart grand or server `total_price`) so change due stays visible while the edit cart is empty during outfit fetch/reload. */
        var editSnapshotTotalDue = null;
        var editOriginalEndDateYmd = null;
        var editOverdueMergedOnce = false;
        var editOverdueDismissed = false;
        var currentEditRentalStatus = null;
        /** created_at (Y-m-d) of the rent open in Quick edit; decides legacy (per-day) vs block (3-day) pricing. */
        var currentEditRentalCreatedAt = null;
        var editOverdueSummary = null;
        /** Tracks staff-queued cool-down <em>edit</em> approval for seamless modal + polling (paired with #my-pending-approvals-list data attributes). */
        var editCooldownApprovalTracked = null;
        var rentalListToastTimer = null;
        var editCooldownApprovalSending = false;
        /** When the same tab handles approve/reject via fetch, skip duplicate reload from the pending snapshot poll. */
        window.__suppressEditPollReloadUntil = 0;
        var editModalViewOnly = false;
        var editModalHardLocked = false;

        function overdueMergeStorageKey(rentalId) {
            return 'rental_rental_overdue_merge_' + String(rentalId || '');
        }

        function loadOverdueMergeRecord(rentalId) {
            try {
                var key = overdueMergeStorageKey(rentalId);
                var raw = window.localStorage ? window.localStorage.getItem(key) : null;
                if (!raw) return null;
                var data = JSON.parse(raw);
                if (!data || typeof data !== 'object') return null;
                var base = (typeof data.base_end === 'string' && data.base_end.match(/^\d{4}-\d{2}-\d{2}$/)) ? data.base_end : '';
                var through = (typeof data.through === 'string' && data.through.match(/^\d{4}-\d{2}-\d{2}$/)) ? data.through : '';
                if (!base && !through) return null;
                return { base_end: base || null, through: through || null };
            } catch (e) {
                return null;
            }
        }

        function saveOverdueMergeRecord(rentalId, baseEnd, throughYmd) {
            try {
                if (!window.localStorage) return;
                var key = overdueMergeStorageKey(rentalId);
                window.localStorage.setItem(key, JSON.stringify({
                    base_end: String(baseEnd || ''),
                    through: String(throughYmd || ''),
                    saved_at: (new Date()).toISOString()
                }));
            } catch (e) {}
        }

        function applyEditModalViewOnlyState() {
            var viewOnly = !!editModalViewOnly;
            var locked = viewOnly || !!editModalHardLocked;
            var title = document.getElementById('editModalTitle');
            var subtitle = document.getElementById('editModalSubtitle');
            if (title) title.textContent = locked ? 'Rent details' : 'Quick edit rent';
            if (subtitle) {
                subtitle.innerHTML = viewOnly
                    ? 'View-only mode. Your account cannot change this rent.'
                    : (editModalHardLocked
                        ? 'This rent is returned and locked. Payment and details cannot be edited.'
                        : 'Update dates, then manage items by tab: <b>Available</b> to add, <b>Rented</b> to switch. Selected items show code, description, stock, price, and per-item discount (same as new rent).');
            }
            var saveBtn = document.getElementById('editSaveChangesBtn');
            if (saveBtn) {
                saveBtn.classList.toggle('hidden', locked);
                saveBtn.disabled = locked;
            }
            var hint = document.getElementById('editFinalStepHint');
            if (hint) hint.classList.toggle('hidden', locked);
            var openBtn = document.getElementById('openInvoicePageBtn');
            if (openBtn) openBtn.classList.toggle('hidden', !mayGenerateInvoices);
            var openImageBtn = document.getElementById('openInvoiceImageBtn');
            if (openImageBtn) openImageBtn.classList.toggle('hidden', !mayGenerateInvoices);
            var waBtn = document.getElementById('sendInvoiceWhatsappBtn');
            if (waBtn) waBtn.classList.toggle('hidden', viewOnly);
            var payBtn = document.getElementById('editPayBtn');
            if (payBtn) {
                if (locked) {
                    payBtn.classList.add('hidden');
                } else if (typeof syncEditPayButtonUi === 'function') {
                    syncEditPayButtonUi();
                }
            }
            var overdueBtn = document.getElementById('editPayOverdueBtn');
            var overduePanel = document.getElementById('editOverduePayPanel');
            if (overdueBtn || overduePanel) {
                if (locked) {
                    if (overdueBtn) overdueBtn.classList.add('hidden');
                    if (overduePanel) overduePanel.classList.add('hidden');
                } else if (typeof syncEditOverduePaymentUi === 'function') {
                    syncEditOverduePaymentUi();
                }
            }
            var form = document.getElementById('editForm');
            if (!form) return;
            var controls = form.querySelectorAll('input, select, textarea');
            for (var i = 0; i < controls.length; i++) {
                var el = controls[i];
                if (el.type === 'hidden') continue;
                el.disabled = locked;
                el.classList.toggle('bg-gray-100', locked);
                el.classList.toggle('text-gray-500', locked);
                el.classList.toggle('cursor-not-allowed', locked);
                el.classList.toggle('opacity-80', locked);
                if (!locked) {
                    el.classList.remove('bg-gray-100', 'text-gray-500', 'cursor-not-allowed', 'opacity-80');
                }
            }
            var buttons = form.querySelectorAll('button');
            for (var j = 0; j < buttons.length; j++) {
                var b = buttons[j];
                if (b.id === 'openInvoicePageBtn' || b.id === 'openInvoiceImageBtn') {
                    b.disabled = !mayGenerateInvoices;
                    continue;
                }
                if (b.id === 'sendInvoiceWhatsappBtn') {
                    b.disabled = viewOnly;
                    continue;
                }
                // Free is only a request (approval decides), so it stays usable on a returned rent.
                if (b.id === 'editRequestFreeBtn') {
                    b.disabled = viewOnly;
                    continue;
                }
                if (b.id === 'editLateDoneBtn') {
                    b.disabled = viewOnly;
                    continue;
                }
                if (b.id === 'editSaveChangesBtn') continue;
                if (b.getAttribute('onclick') === 'closeModal()') continue;
                b.disabled = locked;
            }
            if (!locked && _pricingEnabled && typeof applyEditModalPaymentLockUi === 'function') {
                applyEditModalPaymentLockUi();
            }
            var editSearch = document.getElementById('editOutfitSearch');
            if (editSearch) editSearch.disabled = locked || editSearch.disabled;
        }

        function patchFormDataForEditPaymentLock(fd) {
            if (!_pricingEnabled || !editPaymentLockSnapshot) {
                return;
            }
            if (typeof editModalPaymentFieldsLocked !== 'function' || !editModalPaymentFieldsLocked()) {
                return;
            }
            var snap = editPaymentLockSnapshot;
            fd.set('payment_method', snap.method);
            fd.set('payment_kind', snap.kind);
            fd.set('amount_paid', snap.amountNum != null ? String(snap.amountNum) : '0');
            fd.set('payment_entry', 'replace');
            fd.delete('payment_amount_add');
        }

        function getEditCooldownPendingApprovalIdForRental(rid) {
            if (!rid) return null;
            var rnum = Number(rid);
            if (Number.isNaN(rnum)) return null;
            var list = document.getElementById('my-pending-approvals-list');
            if (!list) return null;
            var lis = list.querySelectorAll('li[data-my-pending-approval]');
            for (var i = 0; i < lis.length; i++) {
                var li = lis[i];
                var pk = li.getAttribute('data-pending-kind');
                var prid = li.getAttribute('data-pending-rental-id');
                if (pk === 'edit' && prid != null && prid !== '' && Number(prid) === rnum) {
                    return li.getAttribute('data-my-pending-approval');
                }
                if (pk == null || pk === '') {
                    var t = li.textContent || '';
                    if (t.indexOf('Quick edit pending') !== -1) {
                        var m = t.match(/Rent\s*#(\d+)/i);
                        if (m && Number(m[1]) === rnum) {
                            return li.getAttribute('data-my-pending-approval');
                        }
                    }
                }
            }
            if (editCooldownApprovalTracked && Number(editCooldownApprovalTracked.rentalId) === rnum) {
                var aid = editCooldownApprovalTracked.approvalId;
                var row = list.querySelector('[data-my-pending-approval="' + aid + '"]');
                if (row) return String(aid);
            }
            return null;
        }

        function getEditCooldownPendingDetailForRental(rid) {
            var aid = getEditCooldownPendingApprovalIdForRental(rid);
            if (!aid) return null;
            var list = document.getElementById('my-pending-approvals-list');
            var li = list ? list.querySelector('[data-my-pending-approval="' + aid + '"]') : null;
            if (!li) return { id: aid, created: '', createdHuman: '' };
            return {
                id: aid,
                created: li.getAttribute('data-pending-created') || '',
                createdHuman: li.getAttribute('data-pending-created-human') || ''
            };
        }

        function editCooldownPendingActiveForCurrentRental() {
            if (!currentRentalId) return false;
            return getEditCooldownPendingApprovalIdForRental(currentRentalId) !== null;
        }

        function syncEditCooldownTrackedFromListForRental(rentalId) {
            if (!rentalId) {
                editCooldownApprovalTracked = null;
                return;
            }
            var pendingId = getEditCooldownPendingApprovalIdForRental(rentalId);
            if (pendingId != null) {
                var aid = parseInt(pendingId, 10);
                if (!Number.isNaN(aid)) {
                    editCooldownApprovalTracked = { approvalId: aid, rentalId: Number(rentalId) };
                    return;
                }
            }
            editCooldownApprovalTracked = null;
        }

        function syncEditCooldownPendingUi() {
            var banner = document.getElementById('editCooldownPendingBanner');
            var saveBtn = document.getElementById('editSaveChangesBtn');
            var hint = document.getElementById('editFinalStepHint');
            var pending = editCooldownPendingActiveForCurrentRental();
            if (banner) {
                if (pending) banner.classList.remove('hidden');
                else banner.classList.add('hidden');
            }
            var cancelWrap = document.getElementById('editCancelPendingWrap');
            var cancelBtn = document.getElementById('editCancelPendingBtn');
            if (cancelWrap && cancelBtn) {
                // getEditCooldownPendingApprovalIdForRental() only ever resolves ids from
                // #my-pending-approvals-list, which the server already scopes to the
                // current user's own requests — so if it resolved, this user is the
                // requester and is safe to show the cancel button to.
                var pendingAid = pending ? getEditCooldownPendingApprovalIdForRental(currentRentalId) : null;
                if (pendingAid) {
                    cancelBtn.dataset.approvalId = pendingAid;
                    cancelWrap.classList.remove('hidden');
                } else {
                    delete cancelBtn.dataset.approvalId;
                    cancelWrap.classList.add('hidden');
                }
            }
            if (saveBtn) {
                saveBtn.disabled = !!pending;
                saveBtn.classList.toggle('opacity-50', !!pending);
                saveBtn.classList.toggle('cursor-not-allowed', !!pending);
            }
            if (hint) {
                hint.textContent = pending
                    ? 'You can\'t save right now — there\'s already a request waiting for approval on this rent.'
                    : 'Final step: click Save Changes after confirming selected items and dates.';
            }
            var detailWrap = document.getElementById('editFinalStepPendingDetail');
            var detailBody = document.getElementById('editFinalStepPendingDetailBody');
            if (detailWrap && detailBody) {
                if (pending) {
                    var d = getEditCooldownPendingDetailForRental(currentRentalId);
                    var summaryLine = d
                        ? 'Requested ' + (d.createdHuman || 'recently') + ' — still waiting for approval.'
                        : '';
                    var diffTpl = d ? document.getElementById('pending-edit-diff-' + d.id) : null;
                    var diffHtml = diffTpl ? diffTpl.innerHTML : '';
                    detailBody.innerHTML = '<div class="font-medium">' + summaryLine.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</div>'
                        + '<div class="text-[11px] text-indigo-400 mt-0.5">What was requested:</div>'
                        + (diffHtml ? '<div class="mt-1">' + diffHtml + '</div>' : '');
                    detailWrap.classList.remove('hidden');
                } else {
                    detailWrap.classList.add('hidden');
                    detailBody.classList.add('hidden');
                    detailBody.textContent = '';
                }
            }
        }

        (function initEditCooldownPendingDetailsToggle() {
            document.addEventListener('click', function(e) {
                var toggle = e.target.closest && e.target.closest('#editCooldownPendingDetailsToggle');
                if (!toggle) return;
                e.preventDefault();
                var details = document.getElementById('editCooldownPendingDetails');
                if (details) details.classList.toggle('hidden');
            });
            document.addEventListener('click', function(e) {
                var toggle = e.target.closest && e.target.closest('#editFinalStepPendingDetailToggle');
                if (!toggle) return;
                e.preventDefault();
                var body = document.getElementById('editFinalStepPendingDetailBody');
                if (body) body.classList.toggle('hidden');
            });
            document.addEventListener('click', function(e) {
                var btn = e.target.closest && e.target.closest('#editCancelPendingBtn');
                if (!btn) return;
                var aid = btn.dataset.approvalId;
                if (!aid) return;
                window.rentalConfirm('The manager will no longer see this request and it cannot be undone.', {
                    title: 'Cancel this request?', icon: 'warning', confirmButtonText: 'Yes, cancel it'
                }).then(function(ok) {
                    if (!ok) return;
                    btn.disabled = true;
                    fetch('/approvals/rentals/' + encodeURIComponent(aid) + '/cancel', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                            'Accept': 'application/json'
                        }
                    }).then(function(r) { return r.json().then(function(body) { return { ok: r.ok, body: body }; }); })
                        .then(function(res) {
                            btn.disabled = false;
                            if (!res.ok) {
                                window.rentalAlert(res.body?.message || 'Could not cancel this request.', { title: 'Cancel failed', icon: 'error' });
                                return;
                            }
                            // Drop the now-resolved entry from the "my pending" list and re-sync —
                            // this instantly clears the banner/highlights/disabled Save button
                            // without a full page reload, same as approving/rejecting elsewhere.
                            var li = document.querySelector('#my-pending-approvals-list [data-my-pending-approval="' + aid + '"]');
                            if (li) li.remove();
                            editCooldownApprovalTracked = null;
                            syncEditCooldownPendingUi();
                            applyPendingRequestFieldHighlights();
                        }).catch(function() {
                            btn.disabled = false;
                        });
                });
            });
        })();

        function reloadEditModalFromServer(rentalId, decisionDetail) {
            fetch('/rentals/search?id=' + encodeURIComponent(rentalId) + '&per_page=1&page=1&_=' + Date.now() + rentalsDebugTodayQuery(), { cache: 'no-store', credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var rental = (data && data.data && data.data[0]) ? data.data[0] : null;
                    if (!rental) return;
                    if (window.rentalOfflineRentals && typeof window.rentalOfflineRentals.upsertMany === 'function') {
                        window.rentalOfflineRentals.upsertMany([rental]);
                    }
                    if (!currentRentalId || Number(rental.id) !== Number(currentRentalId)) return;
                    openEditModal(rental);
                    if (decisionDetail && decisionDetail.mode === 'approved') {
                        showRentalsListToast('Management approved — this rent is updated in Quick edit.', 'success');
                    } else if (decisionDetail && decisionDetail.mode === 'rejected') {
                        showRentalsListToast('Management rejected the cool-down edit. The form matches the saved rent.', 'warning');
                    } else {
                        showRentalsListToast('Your cool-down request was decided. The form matches the saved rent.', 'warning');
                    }
                })
                .catch(function() { /* ignore */ });
        }

        function refreshRentalCacheById(rentalId) {
            if (!window.rentalOfflineRentals || typeof window.rentalOfflineRentals.upsertMany !== 'function') {
                return;
            }
            fetch('/rentals/search?id=' + encodeURIComponent(rentalId) + '&per_page=1&page=1&_=' + Date.now() + rentalsDebugTodayQuery(), { cache: 'no-store', credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var rental = (data && data.data && data.data[0]) ? data.data[0] : null;
                    if (!rental) return;
                    window.rentalOfflineRentals.upsertMany([rental]);
                })
                .catch(function() { /* ignore */ });
        }

        function preFetchRentalIntoCache(rentalId) {
            if (!rentalId) return;
            fetch('/rentals/search?id=' + encodeURIComponent(rentalId) + '&per_page=1&page=1&_=' + Date.now() + rentalsDebugTodayQuery(), { cache: 'no-store', credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var fresh = (data && data.data && data.data[0]) ? data.data[0] : null;
                    if (fresh) {
                        _freshRentalCache[rentalId] = fresh;
                    }
                })
                .catch(function(e) { console.error('[preFetch] error', e); });
        }

        function maybeHandleEditCooldownClearedFromPoll() {
            if (!editCooldownApprovalTracked) return;
            if (typeof window.__suppressEditPollReloadUntil === 'number' && Date.now() < window.__suppressEditPollReloadUntil) {
                return;
            }
            var list = document.getElementById('my-pending-approvals-list');
            if (!list) return;
            var aid = editCooldownApprovalTracked.approvalId;
            var still = list.querySelector('[data-my-pending-approval="' + aid + '"]');
            if (still) return;
            var rid = editCooldownApprovalTracked.rentalId;
            editCooldownApprovalTracked = null;
            var modal = document.getElementById('editModal');
            if (modal && !modal.classList.contains('hidden') && currentRentalId && Number(currentRentalId) === Number(rid)) {
                reloadEditModalFromServer(rid, null);
            }
            syncEditCooldownPendingUi();
        }

        /** Display label for staff role slugs (admin, management, super_admin). */
        function formatStaffRoleLabel(role) {
            if (role == null || role === '') return '';
            return String(role).split('_').filter(Boolean).map(function (w) {
                return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
            }).join(' ');
        }

        /** Name + role for UI; omits role when it only repeats the name (e.g. user "Admin" with role admin). */
        function formatHandledByDisplay(name, roleSlug) {
            if (!name) return '-';
            var rl = roleSlug ? formatStaffRoleLabel(roleSlug) : '';
            var suffix = rl && String(name).trim().toLowerCase() !== rl.toLowerCase() ? ' (' + rl + ')' : '';
            return String(name) + suffix;
        }

        function escRentalHtml(s) {
            var v = s == null ? '' : String(s);
            if (window.rentalEscapeHtml) {
                return window.rentalEscapeHtml(v);
            }
            return v
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function clearCreateErrorSummary() {
            var el = document.getElementById('createErrorSummary');
            if (!el) return;
            el.classList.add('hidden');
            el.innerHTML = '';
        }

        function showCreateErrorSummary(messages) {
            var el = document.getElementById('createErrorSummary');
            if (!el) return;
            var list = Array.isArray(messages) ? messages.filter(Boolean) : [];
            if (!list.length) return clearCreateErrorSummary();
            if (window.rentalAlert) {
                var popupText = list.length === 1 ? String(list[0]) : list.join('\n');
                window.rentalAlert(popupText, { icon: 'error', title: 'Validation error' });
            }
            var errIcon = '<span class="shrink-0 text-red-600 mt-0.5" aria-hidden="true"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg></span>';
            if (list.length === 1) {
                el.innerHTML = '<div class="flex gap-2.5 items-start">' + errIcon + '<div class="min-w-0 flex-1 pt-0.5"><p class="leading-snug">' + escRentalHtml(list[0]) + '</p></div></div>';
            } else {
                el.innerHTML = '<div class="flex gap-2.5 items-start">' + errIcon + '<div class="min-w-0 flex-1"><p class="text-xs font-medium text-red-950 mb-1.5">Check the following:</p><ul class="space-y-1 text-sm list-disc list-inside marker:text-red-400">' + list.map(function(msg) {
                    return '<li class="pl-0.5">' + escRentalHtml(msg) + '</li>';
                }).join('') + '</ul></div></div>';
            }
            el.classList.remove('hidden');
        }

        (function showCreateValidationFromServerOnLoad() {
            var msgs = __rentalsValidationErrors;
            if (!Array.isArray(msgs) || !msgs.length) {
                return;
            }
            // On refresh/redirect after validation failure, surface the same errors in SweetAlert too.
            showCreateErrorSummary(msgs);
        })();

        var rentalFormEl = document.getElementById('rentalForm');
        if (rentalFormEl) rentalFormEl.addEventListener('submit', function(e) {
            e.preventDefault();
            clearCreateErrorSummary();
            if (typeof isCooldownApprovalOnlyMode === 'function' && isCooldownApprovalOnlyMode()) {
                showCreateErrorSummary(['Cool-down items need management approval. Use the amber Request Management Approval button below.']);
                return;
            }
            var form = this;
            document.querySelectorAll('.cart-hidden-input').forEach(function(el) { el.remove(); });
            Object.values(cart).forEach(function(item) {
                var inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'outfit_ids[]'; inp.value = item.id;
                inp.className = 'cart-hidden-input'; form.appendChild(inp);
                if (_pricingEnabled) {
                    var di = document.createElement('input');
                    di.type = 'hidden'; di.name = 'item_discounts[' + item.id + ']'; di.value = item.discount || '';
                    di.className = 'cart-hidden-input'; form.appendChild(di);
                    var dt = document.createElement('input');
                    dt.type = 'hidden'; dt.name = 'item_discount_types[' + item.id + ']'; dt.value = item.discountType || 'fixed';
                    dt.className = 'cart-hidden-input'; form.appendChild(dt);
                    var dn = document.createElement('input');
                    dn.type = 'hidden'; dn.name = 'item_discount_notes[' + item.id + ']'; dn.value = item.discountNote || '';
                    dn.className = 'cart-hidden-input'; form.appendChild(dn);
                }
            });
            if (_pricingEnabled) {
                var _cbi2 = createRentalBillingInfo();
                if (_cbi2.extraDays > 0) {
                    var _cExc = document.getElementById('createExtraCharge');
                    var _cExcVal = _cExc ? (rentalMoneyParse(_cExc.value) || 0) : 0;
                    if (!(_cExcVal > 0)) {
                        showCreateErrorSummary(['Additional charge is required — the selected dates have ' + _cbi2.extraDays + ' extra ' + (_cbi2.extraDays === 1 ? 'day' : 'days') + ' beyond the 3-day blocks.']);
                        if (_cExc) { _cExc.focus(); _cExc.classList.add('ring-2', 'ring-red-500'); }
                        return;
                    }
                }
            }
            var fd = new FormData(form);
            // The app's PWA loading overlay auto-shows on real form submits, but
            // deliberately skips itself when the submit calls preventDefault() (this
            // one does, since it's AJAX not a real navigation) — leaving zero visual
            // feedback during the request and nothing blocking a second click, which
            // reads as "did that even do anything?" and invites spam-clicking.
            if (typeof window.rentalShowPwaLoading === 'function') window.rentalShowPwaLoading();
            fetch(form.action, {
                method: 'POST',
                body: fd,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getRentalsCsrfToken()
                },
                credentials: 'same-origin'
            }).then(function(r) {
                return r.text().then(function(text) {
                    var data = {};
                    try {
                        data = text ? JSON.parse(text) : {};
                    } catch (e) {
                        data = { message: text ? String(text).slice(0, 240) : 'Invalid response' };
                    }
                    return { r: r, data: data };
                });
            }).then(function(res) {
                if (res.r.ok && res.data && res.data.ok) {
                    if (res.data.mode === 'rental_created') {
                        showRentalsListToast('Rent created successfully.', 'success');
                        if (typeof loadRentals === 'function') loadRentals(1);
                        // SweetAlert2 restores focus to whatever had it when the alert opened
                        // (the Create Rent button) once dismissed — the browser's default
                        // .focus() then auto-scrolls that now off-screen button back into
                        // view, undoing the tour's scroll to the rentals list. Blur it first
                        // so there's nothing to restore focus to.
                        if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
                        window.rentalAlert('Rent created successfully.', { icon: 'success', title: 'Created' }).then(function() {
                            resetCreateRentalFormAfterSuccess();
                            if (typeof loadRentals === 'function') loadRentals(1);
                        });
                    } else if (res.data.mode === 'approval_request_sent') {
                        showRentalsListToast('Request sent to manager. Booking is not active until approved.', 'warning');
                        resetCreateRentalFormAfterSuccess();
                        appendMyPendingApprovalRow(res.data.pending_summary);
                        if (typeof loadRentals === 'function') loadRentals(1);
                    } else if (res.data.mode === 'approval_duplicate') {
                        showRentalsListToast('You already have a pending approval for these dates and items.', 'warning');
                    }
                    return;
                }
                var msgs = [];
                var hasFieldErrors = !!(res.data && res.data.errors);
                if (hasFieldErrors) {
                    Object.keys(res.data.errors).forEach(function(k) {
                        var v = res.data.errors[k];
                        if (Array.isArray(v)) msgs = msgs.concat(v);
                        else msgs.push(String(v));
                    });
                }
                // When Laravel already returns per-field errors, skip generic summary
                // like "(and N more errors)" to avoid duplicates/noise.
                if (!hasFieldErrors && res.data && res.data.message) msgs.push(res.data.message);
                // Deduplicate repeated lines while preserving order.
                var seen = {};
                msgs = msgs.filter(function(m) {
                    var key = String(m || '').trim();
                    if (!key || seen[key]) return false;
                    seen[key] = true;
                    return true;
                });
                if (msgs.length) showCreateErrorSummary(msgs);
                else showCreateErrorSummary(['Could not create rent. Please try again.']);
            }).catch(function() {
                showCreateErrorSummary(['Network error. Check your connection and try again.']);
            }).finally(function() {
                if (typeof window.rentalHidePwaLoading === 'function') window.rentalHidePwaLoading();
            });
        });

        let currentRentalId = null;
        let currentRentalHasItemRemovalApproved = false;
        let currentRentalItemRemovalChangeAmount = null;
        let currentRentalItemRemovalRefundAmount = null;
        let editOriginalDueAtOpen = null;
        // Set when openEditModal() turns on tutorial mode itself (opening a
        // tutorial-tagged rental from OUTSIDE the guided tour — e.g. the "My
        // Requests" list's rent link) so closeModal() knows to turn it back off
        // again. Left false while the guided tour is what's driving the flag,
        // so closing a tour-opened modal doesn't stop the tour's own mode.
        var modalForcedTutorialMode = false;
        var _staleRentalIds = {};
        var _freshRentalCache = {}; // pre-fetched rental data after save, keyed by id
        let currentOutfitFilter = 'available';
        let currentEditOutfitFilter = 'available';
        let currentStatus = '';
        let allOutfits = [];
        let editAllOutfits = [];
        let currentPage = 1;

        function toggleSection(id) { document.getElementById(id).classList.toggle('hidden'); }

        /** Show Quick add panel and optionally copy the current search into Code. */
        function openQuickAddCreatePanel(prefillFromSearch) {
            var panel = document.getElementById('quickAddCreatePanel');
            if (!panel) {
                if (!mayMutateItems) {
                    showRentalsListToast('Your account cannot create new items.', 'error');
                } else {
                    showRentalsListToast('Quick add is not available on this page.', 'error');
                }
                return;
            }
            if (panel) panel.classList.remove('hidden');
            var inp = document.getElementById('quickAddCreateInput');
            var searchEl = document.getElementById('outfitSearch');
            if (prefillFromSearch && inp && searchEl) {
                var t = searchEl.value.trim();
                if (t) inp.value = t;
            }
            if (inp) {
                inp.focus();
                if (inp.value) inp.select();
            }
            if (panel) {
                try { panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } catch (e) {}
            }
        }

        function syncOutfitSearchClearVisible() {
            var inp = document.getElementById('outfitSearch');
            var btn = document.getElementById('outfitSearchClear');
            if (!inp || !btn) return;
            var show = !inp.disabled && inp.value.trim().length > 0;
            btn.classList.toggle('hidden', !show);
        }

        function clearOutfitSearchInput() {
            var inp = document.getElementById('outfitSearch');
            if (inp) inp.value = '';
            filterOutfitList('');
            if (inp && !inp.disabled) inp.focus();
        }

        function syncEditOutfitSearchClearVisible() {
            var inp = document.getElementById('editOutfitSearch');
            var btn = document.getElementById('editOutfitSearchClear');
            if (!inp || !btn) return;
            var show = !inp.disabled && inp.value.trim().length > 0;
            btn.classList.toggle('hidden', !show);
        }

        function clearEditOutfitSearchInput() {
            var inp = document.getElementById('editOutfitSearch');
            if (inp) inp.value = '';
            filterOutfitCheckboxes('editOutfitCheckboxList', '');
            if (inp && !inp.disabled) inp.focus();
        }

        function syncRentalsListSearchClearVisible() {
            var inp = document.getElementById('searchInput');
            var btn = document.getElementById('searchInputClear');
            if (!inp || !btn) return;
            var show = inp.value.trim().length > 0;
            btn.classList.toggle('hidden', !show);
        }

        function clearRentalsListSearchInput() {
            var inp = document.getElementById('searchInput');
            if (inp) inp.value = '';
            syncRentalsListSearchClearVisible();
            clearTimeout(searchTimer);
            if (typeof rentalsOfflineMode !== 'undefined' && rentalsOfflineMode) loadRentalsOffline(currentPage);
            else loadRentals();
            if (inp) inp.focus();
        }

        function sd() { return document.getElementById('rsd').value; }
        function ed() { return document.getElementById('red').value; }

        function isCooldownApprovalOnlyMode() {
            if (rentalCooldownApprovalBypass) return false;
            if (rentalMutationsLocked || !sd() || !ed()) return false;
            var ids = Object.keys(cart);
            if (!ids.length) return false;
            return ids.some(function(id) {
                var item = cart[id];
                if (item && item.needs_manager_approval) return true;
                var s = allOutfits.find(function(x) { return String(x.id) === String(id); });
                return s && s.needs_manager_approval;
            });
        }

        function reconcileCartCooldownFlagsFromAllOutfits() {
            Object.keys(cart).forEach(function(k) {
                var item = cart[k];
                if (!item) return;
                var s = allOutfits.find(function(x) { return String(x.id) === String(k); });
                if (s) item.needs_manager_approval = !!s.needs_manager_approval;
            });
        }

        function syncCooldownApprovalOnlyUi() {
            var summary = document.getElementById('cooldownApprovalSummary');
            var btnCreate = document.getElementById('btnCreateRental');
            var btnApproval = document.getElementById('btnCooldownApprovalOnly');
            var dateEl = document.getElementById('cooldownApprovalDates');
            var itemsEl = document.getElementById('cooldownApprovalItems');
            if (!summary || !btnCreate || !btnApproval) return;
            if (!isCooldownApprovalOnlyMode()) {
                summary.classList.add('hidden');
                btnCreate.classList.remove('hidden');
                btnApproval.classList.add('hidden');
                return;
            }
            summary.classList.remove('hidden');
            btnCreate.classList.add('hidden');
            btnApproval.classList.remove('hidden');
            if (dateEl) {
                var d1 = sd();
                var d2 = ed();
                dateEl.textContent = d1 && d2 ? d1 + ' → ' + d2 : '—';
            }
            if (itemsEl) {
                var codes = Object.keys(cart).map(function(k) {
                    var it = cart[k];
                    if (!it) return '';
                    return it.code + (it.new_code ? ' | ' + it.new_code : '');
                }).filter(Boolean);
                itemsEl.textContent = codes.length ? codes.join(', ') : '—';
            }
        }

        function outfitSelectable(s) {
            return s && (s.selectable === true || (s.selectable === undefined && s.available));
        }

        /** Outfit ids from JSON/DOM may be string or number — normalize for lookups. */
        function rentalNumericOutfitId(id) {
            if (id === null || id === undefined || id === '') return null;
            var n = typeof id === 'number' && Number.isFinite(id) ? id : parseInt(id, 10);
            return Number.isFinite(n) ? n : null;
        }

        function outfitsSameId(a, b) {
            var na = rentalNumericOutfitId(a);
            var nb = rentalNumericOutfitId(b);
            return na !== null && nb !== null && na === nb;
        }

        function addToCart(outfit) {
            var sid = Number(outfit.id);
            if (!Number.isFinite(sid) || cart[sid] || !outfitSelectable(outfit)) return;
            cart[sid] = { id: sid, code: outfit.code, new_code: outfit.new_code || '', price: rentalNumericOutfitPrice(outfit.price), stock: outfit.stock || 0, description: outfit.description || '', discount: '', discountType: 'fixed', discountNote: '', needs_manager_approval: !!outfit.needs_manager_approval };
            var search = document.getElementById('outfitSearch');
            search.value = '';
            search.focus();
            renderOutfitList();
            renderCart();
        }

        function removeFromCart(id) {
            delete cart[id];
            renderOutfitList();
            renderCart();
        }

        function updateCartDiscount(id, val) {
            if (cart[id]) { cart[id].discount = val; renderCart(); }
        }

        function updateCartDiscountType(id, val) {
            if (cart[id]) { cart[id].discountType = val; renderCart(); }
        }
        function updateCartDiscountNote(id, val) {
            if (cart[id]) { cart[id].discountNote = val; }
        }

        function renderOutfitList() {
            const container = document.getElementById('outfitList');
            const filter = currentOutfitFilter;
            if (!allOutfits.length) {
                container.innerHTML = rentalsOutfitListEmptyInventoryHtml;
                syncOutfitSearchClearVisible();
                return;
            }
            let filtered = filter === 'all'
                ? allOutfits
                : allOutfits.filter(function(s) { return filter === 'available' ? outfitSelectable(s) : !s.available; });
            filtered = filtered.filter(function(s) { return !cart[Number(s.id)]; });
            if (!filtered.length) {
                container.innerHTML = filter === 'available'
                    ? '<p class="text-sm text-gray-400 text-center py-6">No items you can add for these dates (or all matching items are already in the cart). Items in rent cool-down may appear under <b>All</b> — add them to the cart, then use <b>Request Management Approval</b> at the bottom of the form.</p>'
                    : '<p class="text-sm text-gray-400 text-center py-6">No rented/unavailable items to show for this tab. Try the <b>All</b> tab.</p>';
                syncOutfitSearchClearVisible();
                return;
            }
            container.innerHTML = '';
            filtered.forEach(function(s) {
                var div = document.createElement('div');
                var clickable = outfitSelectable(s);
                div.className = 'flex items-center justify-between px-2 py-1.5 rounded text-sm border-b border-gray-100 last:border-b-0' + (clickable ? ' cursor-pointer hover:bg-indigo-50' : '') + (!clickable ? ' opacity-60' : '');
                if (clickable) div.setAttribute('data-id', s.id);
                var badge = s.available
                    ? '<span class="shrink-0 px-1.5 py-0.5 text-xs rounded border border-green-300 bg-green-100 text-green-700 font-medium">Available</span>'
                    : (s.needs_manager_approval
                        ? '<span class="shrink-0 px-1.5 py-0.5 text-xs rounded border border-amber-300 bg-amber-100 text-amber-900 font-medium">Needs approval</span>'
                        : '<span class="shrink-0 px-1.5 py-0.5 text-xs rounded border border-red-300 bg-red-100 text-red-700 font-medium">Rented</span>');
                var rentalInfo = '';
                if (!s.available && s.rental) {
                    var rcls = s.needs_manager_approval ? 'text-amber-800' : 'text-red-500';
                    var extra = s.needs_manager_approval ? ' <span class="text-amber-700">(cool-down — use Request Management Approval at the bottom of the form)</span>' : '';
                    rentalInfo = '<div class="text-xs ' + rcls + '">Rented by ' + escRentalHtml(s.rental.customer) + ' ' + escRentalHtml(s.rental.start) + ' - ' + escRentalHtml(s.rental.end) + extra + '</div>';
                }
                var priceHtml = _pricingEnabled && s.price != null ? '<span class="text-xs text-gray-600">Rp ' + rentalNumericOutfitPrice(s.price).toLocaleString('id-ID') + '</span>' : '';
                var stockHtml = _pricingEnabled && (s.stock !== undefined && s.stock !== null) ? '<span class="text-xs text-gray-600">Stock: ' + (s.stock || 0) + '</span>' : '';
                var statusHtml = '';
                // In Available tab do not show any selection hint (cart is separate)
                if (filter !== 'available') {
                    if (outfitSelectable(s)) {
                        statusHtml = '<span class="text-xs text-indigo-600 font-medium">+ Add</span>';
                    }
                }
                div.innerHTML = '<div class="flex-1 min-w-0"><div class="flex items-center gap-1 flex-wrap"><span class="font-medium text-gray-900 text-sm">' + escRentalHtml(s.code) + (s.new_code ? ' | ' + escRentalHtml(s.new_code) : '') + '</span>' + (s.description ? '<span class="text-gray-500 text-xs truncate">' + escRentalHtml(s.description) + '</span>' : '') + '</div>' + rentalInfo + '</div><div class="flex items-center gap-2 shrink-0">' + priceHtml + stockHtml + badge + statusHtml + '</div>';
                container.appendChild(div);
            });
            updateConflictInfo();
            var searchVal = document.getElementById('outfitSearch').value;
            if (searchVal) filterOutfitList(searchVal);
            else syncOutfitSearchClearVisible();
        }

        function parseItemDiscountAmount(item) {
            var raw = item.discount;
            if (raw === '' || raw == null) return 0;
            if (item.discountType === 'percent') {
                return parseFloat(String(raw).replace(',', '.')) || 0;
            }
            var p = rentalMoneyParse(String(raw));
            return Number.isFinite(p) ? p : 0;
        }
        function rentalYmdUtcMs(ymd) {
            var m = String(ymd || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (!m) return NaN;
            return Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
        }
        // Mirrors PHP RentalFinancialSummary::billingInfo().
        // createdAtYmd decides legacy (per-day) vs block (3-day) pricing.
        function rentalBillingInfo(startYmd, endYmd, createdAtYmd) {
            var s = rentalYmdUtcMs(startYmd);
            var e = rentalYmdUtcMs(endYmd);
            if (!Number.isFinite(s) || !Number.isFinite(e)) return { days: 3, extraDays: 0, mode: 'block' };
            if (e < s) {
                var tmp = s;
                s = e;
                e = tmp;
            }
            var diff = Math.floor((e - s) / 86400000);
            var calendarDays = diff + 1;

            if (_pricingLegacyCutOffDate) {
                var cutMs = rentalYmdUtcMs(String(_pricingLegacyCutOffDate).substring(0, 10));
                var createdMs = rentalYmdUtcMs(String(createdAtYmd || '').substring(0, 10));
                // Unknown created_at → treat as legacy (safe default for new/offline rows)
                if (Number.isFinite(cutMs) && (!Number.isFinite(createdMs) || createdMs < cutMs)) {
                    // Legacy rentals keep the original day formula (preserve historical totals).
                    return { days: Math.max(1, diff - 1), extraDays: 0, mode: 'legacy' };
                }
            }

            var blockDays = Math.max(3, Math.floor(calendarDays / 3) * 3);
            return { days: blockDays, extraDays: Math.max(0, calendarDays - blockDays), mode: 'block' };
        }
        // Item-line price label: "/3 day × blocks" for block mode, "/day × days" for legacy.
        function rentalItemPriceLabel(unitPrice, info) {
            var price = rentalNumericOutfitPrice(unitPrice);
            var days = info.days;
            if (info.mode === 'block') {
                var blocks = Math.floor(Math.max(3, days) / 3);
                var gross = price * blocks;
                var label = 'Rp ' + price.toLocaleString('id-ID') + '/3 day';
                if (blocks > 1) label += ' × ' + blocks;
                label += ' = Rp ' + gross.toLocaleString('id-ID');
                return label;
            }
            var gross2 = price * days;
            var label2 = 'Rp ' + price.toLocaleString('id-ID') + '/day';
            if (days > 1) label2 += ' × ' + days + ' = Rp ' + gross2.toLocaleString('id-ID');
            return label2;
        }
        function rentalDaysBetweenYmd(startYmd, endYmd, createdAtYmd) {
            return rentalBillingInfo(startYmd, endYmd, createdAtYmd).days;
        }
        function createRentalDays() {
            var sdEl = document.getElementById('rsd');
            var edEl = document.getElementById('red');
            // New rental created now → block pricing (after any cut-off)
            var todayYmd = typeof todayYmdLocal === 'function' ? todayYmdLocal() : '';
            return rentalDaysBetweenYmd(sdEl ? sdEl.value : '', edEl ? edEl.value : '', todayYmd);
        }
        function createRentalBillingInfo() {
            var sdEl = document.getElementById('rsd');
            var edEl = document.getElementById('red');
            var todayYmd = typeof todayYmdLocal === 'function' ? todayYmdLocal() : '';
            return rentalBillingInfo(sdEl ? sdEl.value : '', edEl ? edEl.value : '', todayYmd);
        }
        function createRentalExtraDays() {
            return createRentalBillingInfo().extraDays;
        }
        function createExtraChargeNum() {
            var el = document.getElementById('createExtraCharge');
            var raw = el ? String(el.value || '').trim() : '';
            if (!raw) return 0;
            var n = rentalMoneyParse(raw);
            return Number.isFinite(n) ? Math.max(0, n) : 0;
        }
        function syncCreateExtraChargeRow() {
            var row = document.getElementById('createExtraChargeRow');
            var label = document.getElementById('createExtraDaysLabel');
            var input = document.getElementById('createExtraCharge');
            if (!row) return;
            var extraDays = _pricingEnabled ? createRentalExtraDays() : 0;
            if (extraDays > 0) {
                row.classList.remove('hidden');
                if (label) label.textContent = '(+' + extraDays + ' day' + (extraDays === 1 ? '' : 's') + ' additional)';
                if (input) input.addEventListener('input', function() { input.classList.remove('ring-2', 'ring-red-500'); }, { once: true });
            } else {
                row.classList.add('hidden');
                if (input) { input.value = ''; input.classList.remove('ring-2', 'ring-red-500'); }
            }
        }
        function editEffectivePricingEndYmd() {
            var edEl = document.getElementById('editEd');
            var endYmd = edEl ? String(edEl.value || '') : '';
            return endYmd;
        }
        function editRentalDays() {
            var sdEl = document.getElementById('editSd');
            var endYmd = editEffectivePricingEndYmd();
            return rentalDaysBetweenYmd(sdEl ? sdEl.value : '', endYmd, currentEditRentalCreatedAt);
        }
        function editRentalBillingInfo() {
            var sdEl = document.getElementById('editSd');
            var endYmd = editEffectivePricingEndYmd();
            return rentalBillingInfo(sdEl ? sdEl.value : '', endYmd, currentEditRentalCreatedAt);
        }
        function editRentalExtraDays() {
            return editRentalBillingInfo().extraDays;
        }
        function formatOverdueSeconds(seconds) {
            var sec = Math.max(0, Number(seconds) || 0);
            if (sec <= 0) return '';
            var units = [
                ['year', 365 * 86400],
                ['month', 30 * 86400],
                ['week', 7 * 86400],
                ['day', 86400],
                ['hour', 3600],
                ['minute', 60],
            ];
            var parts = [];
            for (var i = 0; i < units.length; i++) {
                var name = units[i][0];
                var unitSec = units[i][1];
                if (sec < unitSec) continue;
                var qty = Math.floor(sec / unitSec);
                sec = sec - (qty * unitSec);
                parts.push(String(qty) + ' ' + name + (qty === 1 ? '' : 's'));
                if (parts.length >= 2) break;
            }
            if (!parts.length) return 'less than a minute overdue';
            return (parts.length === 1)
                ? (parts[0] + ' overdue')
                : (parts[0] + ' and ' + parts[1] + ' overdue');
        }
        function overdueSecondsForEndYmd(endYmd) {
            var msEnd = rentalYmdUtcMs(endYmd);
            if (!Number.isFinite(msEnd)) return 0;
            var msDueBoundary = msEnd + 86400000;
            var msNow = (__RENTAL_DEBUG_TODAY_YMD && typeof todayYmdLocal === 'function')
                ? rentalYmdUtcMs(String(todayYmdLocal() || ''))
                : Date.now();
            if (!Number.isFinite(msNow)) return 0;
            return Math.max(0, Math.floor((msNow - msDueBoundary) / 1000));
        }
        function syncOverdueDurationLabels() {
            var nodes = document.querySelectorAll('.js-overdue-duration[data-end-ymd]');
            if (!nodes || !nodes.length) return;
            nodes.forEach(function(el) {
                var endYmd = String(el.getAttribute('data-end-ymd') || '');
                var sec = overdueSecondsForEndYmd(endYmd);
                var human = formatOverdueSeconds(sec);
                el.textContent = human || '';
                el.classList.toggle('hidden', !human);
            });
        }
        setInterval(syncOverdueDurationLabels, 60000);
        function calcEffectivePrice(item, days) {
            var d = Math.max(1, Number(days) || 1);
            var unitPrice = rentalNumericOutfitPrice(item.price);
            var lineGross = unitPrice * d;
            var discNum = parseItemDiscountAmount(item);
            if (!discNum) return lineGross;
            return item.discountType === 'percent'
                ? lineGross - (lineGross * discNum / 100)
                : lineGross - discNum;
        }

        function calcTax(amount) {
            var base = Math.max(0, Number(amount) || 0);
            return base * (Number(_pricingTaxPercent) || 0) / 100;
        }

        var rentalMoneyFormatter = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        function rentalMoneyParse(str) {
            if (str == null || String(str).trim() === '') return NaN;
            if (typeof str === 'number') return Number.isFinite(str) ? str : NaN;
            var s = String(str).trim();
            // If it has a comma it's Indonesian format (dot=thousands, comma=decimal)
            if (s.indexOf(',') !== -1) {
                s = s.replace(/\./g, '').replace(',', '.');
            } else {
                var dots = (s.match(/\./g) || []).length;
                if (dots > 1) {
                    // Multiple dots = Indonesian thousands separators
                    s = s.replace(/\./g, '');
                } else if (dots === 1) {
                    var afterDot = s.slice(s.lastIndexOf('.') + 1);
                    // Single dot with exactly 1-2 trailing digits = standard decimal from server
                    // Single dot with 3 digits = Indonesian thousands (e.g. "1.000")
                    if (afterDot.length === 3) {
                        s = s.replace(/\./g, '');
                    }
                    // else leave as-is (standard decimal like "100000.00" or "1000.5")
                }
            }
            var n = parseFloat(s);
            return Number.isFinite(n) ? n : NaN;
        }
        function rentalMoneyFormatNum(n) {
            if (!Number.isFinite(n)) return '';
            return rentalMoneyFormatter.format(n);
        }
        /** Parse outfit line price from API/form (handles decimals, id-ID-style grouping). */
        function rentalNumericOutfitPrice(raw) {
            if (raw == null || raw === '') return 0;
            if (typeof raw === 'number') return Number.isFinite(raw) ? raw : 0;
            var s = String(raw).trim();
            if (s === '') return 0;
            if (s.indexOf(',') !== -1) {
                var n1 = parseFloat(s.replace(/\./g, '').replace(',', '.'));
                return Number.isFinite(n1) ? n1 : 0;
            }
            var dots = (s.match(/\./g) || []).length;
            if (dots > 1) {
                var n2 = parseFloat(s.replace(/\./g, ''));
                return Number.isFinite(n2) ? n2 : 0;
            }
            var n3 = Number(s);
            return Number.isFinite(n3) ? n3 : 0;
        }
        function rentalMoneyDigitCountBefore(val, caret) {
            var c = 0;
            var lim = Math.min(caret | 0, val.length);
            for (var i = 0; i < lim; i++) {
                if (/\d/.test(val.charAt(i))) c++;
            }
            return c;
        }
        function rentalMoneyFormatIntGrouping(digits) {
            if (!digits) return '';
            var out = '';
            var cnt = 0;
            for (var i = digits.length - 1; i >= 0; i--) {
                if (cnt > 0 && cnt % 3 === 0) out = '.' + out;
                out = digits.charAt(i) + out;
                cnt++;
            }
            return out;
        }
        function rentalMoneyCaretPos(newVal, intDigitsBefore, fracDigitsBefore, caretRightAfterComma) {
            var i = 0;
            var intSeen = 0;
            for (; i < newVal.length; i++) {
                var ch = newVal.charAt(i);
                if (ch === ',') break;
                if (/\d/.test(ch)) {
                    intSeen++;
                    if (intDigitsBefore > 0 && intSeen >= intDigitsBefore) {
                        i++;
                        break;
                    }
                    if (intDigitsBefore === 0) break;
                }
            }
            if (fracDigitsBefore <= 0 && !caretRightAfterComma) return Math.min(i, newVal.length);
            while (i < newVal.length && newVal.charAt(i) !== ',') i++;
            if (caretRightAfterComma && fracDigitsBefore <= 0) return Math.min(i + 1, newVal.length);
            if (i < newVal.length && newVal.charAt(i) === ',') i++;
            var fracSeen = 0;
            for (; i < newVal.length; i++) {
                if (/\d/.test(newVal.charAt(i))) {
                    fracSeen++;
                    if (fracSeen >= fracDigitsBefore) return i + 1;
                }
            }
            return newVal.length;
        }
        function rentalApplyLiveMoneyFormatting(el) {
            var raw = el.value;
            var caret = el.selectionStart != null ? el.selectionStart : raw.length;
            var commaIdx = raw.indexOf(',');
            var intEnd = commaIdx === -1 ? raw.length : commaIdx;
            var intDigitsBefore = rentalMoneyDigitCountBefore(raw.slice(0, intEnd), Math.min(caret, intEnd));
            var fracDigitsBefore = 0;
            var caretRightAfterComma = false;
            if (commaIdx !== -1 && caret > commaIdx) {
                fracDigitsBefore = rentalMoneyDigitCountBefore(raw.slice(commaIdx + 1), caret - commaIdx - 1);
            }
            if (commaIdx !== -1 && caret === commaIdx + 1) caretRightAfterComma = true;
            var intRaw = commaIdx === -1 ? raw : raw.slice(0, commaIdx);
            var fracRaw = commaIdx === -1 ? null : raw.slice(commaIdx + 1);
            intRaw = intRaw.replace(/\./g, '').replace(/\D/g, '');
            if (fracRaw !== null) fracRaw = fracRaw.replace(/\D/g, '').slice(0, 2);
            var hasComma = commaIdx !== -1;
            var intFmt = rentalMoneyFormatIntGrouping(intRaw);
            if (hasComma && intRaw === '') intFmt = '0';
            var newVal = hasComma ? intFmt + ',' + (fracRaw !== null ? fracRaw : '') : intFmt;
            var newCaret = rentalMoneyCaretPos(newVal, intDigitsBefore, fracDigitsBefore, caretRightAfterComma);
            el.value = newVal;
            el.setSelectionRange(newCaret, newCaret);
        }
        function bindRentalMoneyThousandsInput(el, afterInputCallback) {
            if (!el) return;
            el.addEventListener('input', function() {
                rentalApplyLiveMoneyFormatting(el);
                if (typeof afterInputCallback === 'function') {
                    afterInputCallback();
                }
            });
            el.addEventListener('blur', function() {
                var v = el.value.trim();
                if (v === '') return;
                var n = rentalMoneyParse(v);
                if (Number.isFinite(n)) el.value = rentalMoneyFormatNum(n);
            });
        }
        function bindSmartOrderDiscountInput(el, getTypeFn) {
            if (!el || typeof getTypeFn !== 'function') return;
            el.addEventListener('input', function() {
                if (getTypeFn() === 'fixed') rentalApplyLiveMoneyFormatting(el);
                else el.value = String(el.value).replace(/[^\d.,]/g, '').replace(',', '.');
                updateCreatePricingDisplays();
            });
            el.addEventListener('blur', function() {
                if (getTypeFn() !== 'fixed') return;
                var v = el.value.trim();
                if (v === '') return;
                var n = rentalMoneyParse(v);
                if (Number.isFinite(n)) el.value = rentalMoneyFormatNum(n);
            });
        }
        function bindSmartEditOrderDiscountInput(el, getTypeFn) {
            if (!el || typeof getTypeFn !== 'function') return;
            el.addEventListener('input', function() {
                if (getTypeFn() === 'fixed') rentalApplyLiveMoneyFormatting(el);
                else el.value = String(el.value).replace(/[^\d.,]/g, '').replace(',', '.');
                updateEditPricingDisplays();
            });
            el.addEventListener('blur', function() {
                if (getTypeFn() !== 'fixed') return;
                var v = el.value.trim();
                if (v === '') return;
                var n = rentalMoneyParse(v);
                if (Number.isFinite(n)) el.value = rentalMoneyFormatNum(n);
            });
        }
        function computeCreatePricingBreakdown() {
            var items = Object.values(cart);
            var gross = 0;
            var sumAfter = 0;
            var _cbi = createRentalBillingInfo();
            var days = _cbi.days;
            var multiplier = _cbi.mode === 'block' ? Math.floor(Math.max(3, days) / 3) : days;
            items.forEach(function(item) {
                var unitPrice = rentalNumericOutfitPrice(item.price);
                gross += unitPrice * multiplier;
                sumAfter += Math.max(0, calcEffectivePrice(item, multiplier));
            });
            var dtEl = document.getElementById('discountType');
            var dEl = document.getElementById('discount');
            var orderType = dtEl ? dtEl.value : 'fixed';
            var rawOrder = dEl ? String(dEl.value).trim() : '';
            var orderVal = 0;
            if (rawOrder !== '') {
                orderVal = orderType === 'percent'
                    ? (parseFloat(String(rawOrder).replace(',', '.')) || 0)
                    : (rentalMoneyParse(rawOrder) || 0);
            }
            var orderAmt = 0;
            if (orderVal > 0) {
                orderAmt = orderType === 'percent' ? sumAfter * orderVal / 100 : orderVal;
            }
            var inclAfter = Math.max(0, sumAfter - orderAmt);
            var extraCharge = createExtraChargeNum();
            if (_pricingPricesIncludeTax) {
                var r = (Number(_pricingTaxPercent) || 0) / 100;
                var tax = Math.max(0, inclAfter * r);
                var preTax = Math.max(0, inclAfter - tax);
                return { gross: gross, sumAfterItems: sumAfter, orderDiscountAmount: orderAmt, preTax: preTax, tax: tax, extraCharge: extraCharge, grand: inclAfter + extraCharge };
            }
            var preTax = inclAfter;
            var tax = calcTax(preTax);
            return { gross: gross, sumAfterItems: sumAfter, orderDiscountAmount: orderAmt, preTax: preTax, tax: tax, extraCharge: extraCharge, grand: preTax + tax + extraCharge };
        }
        function syncCreatePaymentKindUi() {
            var lbl = document.getElementById('createAmountPaidLabel');
            var down = document.getElementById('createPaymentKindDown');
            if (!lbl || !down) return;
            lbl.textContent = down.checked ? 'Down payment' : 'Amount paid';
            updatePaymentBalanceHintCreate();
        }
        function syncEditPaymentKindSettlementVisibility(rental) {
            var host = document.getElementById('editPaymentKindSettlement');
            var ekf = document.getElementById('editPaymentKindFull');
            var ekd = document.getElementById('editPaymentKindDown');
            if (!host || !ekf || !ekd) {
                return;
            }
            /* Quick edit never shows Full vs Down — staff only adjust amounts; server keeps / upgrades kind. */
            host.classList.add('hidden');
            var pk = rental && rental.payment_kind === 'down_payment' ? 'down_payment' : 'full';
            ekf.checked = pk === 'full';
            ekd.checked = pk === 'down_payment';
        }

        function syncEditPaymentKindUi() {
            if (incrementPaymentUiActive()) {
                return;
            }
            var lbl = document.getElementById('editAmountPaidLabel');
            if (!lbl) return;
            lbl.textContent = 'Amount paid';
            updatePaymentBalanceHintEdit();
        }
        function updateCreatePricingDisplays() {
            var host = document.getElementById('cartTotalsHost');
            if (!_pricingEnabled) return;
            syncCreateExtraChargeRow();
            var items = Object.values(cart);
            var dueInline = document.getElementById('createDueInlineDisplay');
            if (!items.length) {
                if (host) {
                    host.innerHTML = '';
                    host.classList.add('hidden');
                }
                if (dueInline) dueInline.textContent = '—';
                var apEmpty = document.getElementById('amountPaid');
                if (apEmpty) apEmpty.value = '';
                updatePaymentBalanceHintCreate();
                return;
            }
            var b = computeCreatePricingBreakdown();
            if (dueInline) dueInline.textContent = 'Rp ' + b.grand.toLocaleString('id-ID');
            var showTax = Number(_pricingTaxPercent) > 0.004 && Number.isFinite(b.tax) && b.tax > 0.004;
            var showTaxDisplay = showTax && !_pricingPricesIncludeTax;
            var incl = document.getElementById('createDueInclTaxLabel');
            if (incl) incl.classList.toggle('hidden', !showTaxDisplay);
            if (host) {
                host.classList.remove('hidden');
                var rows = '<div class="flex items-center justify-between text-sm text-gray-700"><span>Subtotal <span class="text-gray-500 font-normal">(after line discounts)</span></span><span class="tabular-nums">Rp ' + b.sumAfterItems.toLocaleString('id-ID') + '</span></div>';
                if (b.orderDiscountAmount > 0.004) {
                    rows += '<div class="flex items-center justify-between text-sm text-amber-900 mt-1"><span>Order discount</span><span class="tabular-nums">−Rp ' + b.orderDiscountAmount.toLocaleString('id-ID') + '</span></div>';
                }
                if (!_pricingPricesIncludeTax) {
                    rows += '<div class="flex items-center justify-between text-sm text-gray-700 mt-1"><span>Total before tax</span><span class="tabular-nums">Rp ' + b.preTax.toLocaleString('id-ID') + '</span></div>';
                    if (showTaxDisplay) {
                        rows += '<div class="flex items-center justify-between text-sm text-gray-700 mt-1"><span>Tax (' + Number(_pricingTaxPercent).toLocaleString('id-ID') + '%)</span><span class="tabular-nums">Rp ' + b.tax.toLocaleString('id-ID') + '</span></div>';
                    }
                }
                if ((b.extraCharge || 0) > 0.004) {
                    var exDaysCreate = createRentalExtraDays();
                    rows += '<div class="flex items-center justify-between text-sm text-gray-700 mt-1"><span>Additional charge' + (exDaysCreate > 0 ? ' <span class="text-gray-500 font-normal">(' + exDaysCreate + ' day' + (exDaysCreate === 1 ? '' : 's') + ')</span>' : '') + '</span><span class="tabular-nums">Rp ' + b.extraCharge.toLocaleString('id-ID') + '</span></div>';
                }
                rows += '<div class="flex items-center justify-between mt-1.5 pt-1.5 border-t border-indigo-200"><span class="text-base font-semibold text-gray-800">Total due' + (showTaxDisplay ? ' <span class="text-xs font-normal text-gray-500">(incl. tax)</span>' : '') + '</span><span class="text-[calc(1.125rem-3px)] font-bold text-indigo-900 tabular-nums">Rp ' + b.grand.toLocaleString('id-ID') + '</span></div>';
                host.innerHTML = '<div class="mt-3 rounded-xl border border-indigo-100 bg-indigo-50/60 px-3 py-2.5"><p class="text-[calc(0.75rem-1px)] font-semibold uppercase tracking-wide text-indigo-700 mb-2">Order summary</p>' + rows + '</div>';
            }
            updatePaymentBalanceHintCreate();
        }
        function editOverdueFeeAmountNum() {
            var el = document.getElementById('editOverdueFeeAmount');
            var raw = el ? String(el.value || '').trim() : '';
            if (!raw) return 0;
            var n = parseFloat(raw.replace(',', '.'));
            return Number.isFinite(n) ? Math.max(0, n) : 0;
        }
        function editExtraChargeNum() {
            var el = document.getElementById('editExtraCharge');
            var raw = el ? String(el.value || '').trim() : '';
            if (!raw) return 0;
            var n = rentalMoneyParse(raw);
            return Number.isFinite(n) ? Math.max(0, n) : 0;
        }
        // Show the manual extra-day charge field only when the rent runs past whole 3-day blocks.
        function syncEditExtraChargeRow() {
            var row = document.getElementById('editExtraChargeRow');
            var label = document.getElementById('editExtraDaysLabel');
            var input = document.getElementById('editExtraCharge');
            if (!row) return;
            var extraDays = _pricingEnabled ? editRentalExtraDays() : 0;
            if (extraDays > 0) {
                row.classList.remove('hidden');
                if (label) label.textContent = '(+' + extraDays + ' day' + (extraDays === 1 ? '' : 's') + ' additional)';
                if (input) input.addEventListener('input', function() { input.classList.remove('ring-2', 'ring-red-500'); }, { once: true });
            } else {
                row.classList.add('hidden');
                if (input) { input.value = ''; input.classList.remove('ring-2', 'ring-red-500'); }
            }
        }
        function computeEditPricingBreakdown() {
            var ids = Object.keys(editCart);
            var gross = 0;
            var sumAfter = 0;
            var _ebi = editRentalBillingInfo();
            var days = _ebi.days;
            var multiplier = _ebi.mode === 'block' ? Math.floor(Math.max(3, days) / 3) : days;
            ids.forEach(function(id) {
                var item = editCart[id];
                var unitPrice = rentalNumericOutfitPrice(item.price);
                gross += unitPrice * multiplier;
                sumAfter += Math.max(0, calcEffectivePrice(item, multiplier));
            });
            var dtEl = document.getElementById('editDiscountType');
            var dEl = document.getElementById('editDiscount');
            var orderType = dtEl ? dtEl.value : 'fixed';
            var rawOrder = dEl ? String(dEl.value).trim() : '';
            var orderVal = 0;
            if (rawOrder !== '') {
                orderVal = orderType === 'percent'
                    ? (parseFloat(String(rawOrder).replace(',', '.')) || 0)
                    : (rentalMoneyParse(rawOrder) || 0);
            }
            var orderAmt = 0;
            if (orderVal > 0) {
                orderAmt = orderType === 'percent' ? sumAfter * orderVal / 100 : orderVal;
            }
            var inclAfter = Math.max(0, sumAfter - orderAmt);
            var r = (Number(_pricingTaxPercent) || 0) / 100;
            var tax = _pricingPricesIncludeTax ? Math.max(0, inclAfter * r) : calcTax(inclAfter);
            var preTax = _pricingPricesIncludeTax ? Math.max(0, inclAfter - tax) : inclAfter;
            var overdueFee = editOverdueFeeAmountNum();
            var extraCharge = editExtraChargeNum();
            var due = _pricingPricesIncludeTax ? inclAfter : (preTax + tax);
            return { gross: gross, sumAfterItems: sumAfter, orderDiscountAmount: orderAmt, preTax: preTax, tax: tax, overdueFee: overdueFee, extraCharge: extraCharge, grand: due + overdueFee + extraCharge };
        }
        function computeEditPricingBreakdownForDays(days) {
            var ids = Object.keys(editCart);
            var gross = 0;
            var sumAfter = 0;
            var d = Math.max(1, Number(days) || 1);
            ids.forEach(function(id) {
                var item = editCart[id];
                var unitPrice = rentalNumericOutfitPrice(item.price);
                gross += unitPrice * d;
                sumAfter += Math.max(0, calcEffectivePrice(item, d));
            });
            var dtEl = document.getElementById('editDiscountType');
            var dEl = document.getElementById('editDiscount');
            var orderType = dtEl ? dtEl.value : 'fixed';
            var rawOrder = dEl ? String(dEl.value).trim() : '';
            var orderVal = 0;
            if (rawOrder !== '') {
                orderVal = orderType === 'percent'
                    ? (parseFloat(String(rawOrder).replace(',', '.')) || 0)
                    : (rentalMoneyParse(rawOrder) || 0);
            }
            var orderAmt = 0;
            if (orderVal > 0) {
                orderAmt = orderType === 'percent' ? sumAfter * orderVal / 100 : orderVal;
            }
            var inclAfter = Math.max(0, sumAfter - orderAmt);
            var r = (Number(_pricingTaxPercent) || 0) / 100;
            var tax = _pricingPricesIncludeTax ? Math.max(0, inclAfter * r) : calcTax(inclAfter);
            var preTax = _pricingPricesIncludeTax ? Math.max(0, inclAfter - tax) : inclAfter;
            var overdueFee = editOverdueFeeAmountNum();
            var extraCharge = editExtraChargeNum();
            var due = _pricingPricesIncludeTax ? inclAfter : (preTax + tax);
            return { gross: gross, sumAfterItems: sumAfter, orderDiscountAmount: orderAmt, preTax: preTax, tax: tax, overdueFee: overdueFee, extraCharge: extraCharge, grand: due + overdueFee + extraCharge };
        }
        function updateEditPricingDisplays() {
            var host = document.getElementById('editCartTotalsHost');
            if (!_pricingEnabled) return;
            syncEditExtraChargeRow();
            var ids = Object.keys(editCart);
            var dueInline = document.getElementById('editDueInlineDisplay');
            var dueBreak = document.getElementById('editDueQuickBreakdown');
            var dueTax = document.getElementById('editDueQuickTax');
            var dueTaxPct = document.getElementById('editDueQuickTaxPct');
            var dueTaxLbl = document.getElementById('editDueQuickTaxLabel');
            var taxChip = document.getElementById('editDueInclTaxChip');
            if (!ids.length) {
                if (host) {
                    host.innerHTML = '';
                    host.classList.add('hidden');
                }
                if (dueInline) dueInline.textContent = '—';
                if (dueBreak) dueBreak.classList.add('hidden');
                if (dueTax) dueTax.textContent = '—';
                if (dueTaxPct) dueTaxPct.textContent = String(Number(_pricingTaxPercent));
                if (dueTaxLbl) dueTaxLbl.classList.add('hidden');
                if (dueTax) dueTax.classList.add('hidden');
                if (taxChip) taxChip.classList.toggle('hidden', _pricingPricesIncludeTax || Number(_pricingTaxPercent) <= 0.004);
                syncEditPaymentIncrementUi();
                applyEditModalPaymentLockUi();
                return;
            }
            var b = computeEditPricingBreakdown();
            var showTax = Number(_pricingTaxPercent) > 0.004 && Number.isFinite(b.tax) && b.tax > 0.004;
            var showTaxDisplay = showTax && !_pricingPricesIncludeTax;
            if (Number.isFinite(b.grand)) {
                editSnapshotTotalDue = b.grand;
            }
            if (dueInline) dueInline.textContent = 'Rp ' + b.grand.toLocaleString('id-ID');
            if (dueBreak) {
                dueBreak.classList.remove('hidden');
            }
            if (dueTaxPct) dueTaxPct.textContent = String(Number(_pricingTaxPercent));
            if (dueTaxLbl) dueTaxLbl.classList.toggle('hidden', !showTaxDisplay);
            if (dueTax) {
                dueTax.classList.toggle('hidden', !showTaxDisplay);
                dueTax.textContent = showTaxDisplay ? ('Rp ' + b.tax.toLocaleString('id-ID')) : '—';
            }
            if (taxChip) taxChip.classList.toggle('hidden', !showTaxDisplay);
            if (host) {
                host.classList.remove('hidden');
                var rows = '<div class="flex items-center justify-between text-sm text-gray-700"><span>Subtotal <span class="text-gray-500 font-normal">(after line discounts)</span></span><span class="tabular-nums">Rp ' + b.sumAfterItems.toLocaleString('id-ID') + '</span></div>';
                if (b.overdueFee > 0.004) {
                    rows += '<div class="flex items-center justify-between text-sm text-gray-700 mt-1"><span>Overdue fine</span><span class="tabular-nums">Rp ' + b.overdueFee.toLocaleString('id-ID') + '</span></div>';
                }
                if (b.orderDiscountAmount > 0.004) {
                    rows += '<div class="flex items-center justify-between text-sm text-amber-900 mt-1"><span>Order discount</span><span class="tabular-nums">−Rp ' + b.orderDiscountAmount.toLocaleString('id-ID') + '</span></div>';
                }
                if (!_pricingPricesIncludeTax) {
                    rows += '<div class="flex items-center justify-between text-sm text-gray-700 mt-1"><span>Total before tax</span><span class="tabular-nums">Rp ' + b.preTax.toLocaleString('id-ID') + '</span></div>';
                    if (showTaxDisplay) {
                        rows += '<div class="flex items-center justify-between text-sm text-gray-700 mt-1"><span>Tax (' + Number(_pricingTaxPercent).toLocaleString('id-ID') + '%)</span><span class="tabular-nums">Rp ' + b.tax.toLocaleString('id-ID') + '</span></div>';
                    }
                }
                if ((b.extraCharge || 0) > 0.004) {
                    var exDaysEdit = editRentalExtraDays();
                    rows += '<div class="flex items-center justify-between text-sm text-gray-700 mt-1"><span>Additional charge' + (exDaysEdit > 0 ? ' <span class="text-gray-500 font-normal">(' + exDaysEdit + ' day' + (exDaysEdit === 1 ? '' : 's') + ')</span>' : '') + '</span><span class="tabular-nums">Rp ' + b.extraCharge.toLocaleString('id-ID') + '</span></div>';
                }
                rows += '<div class="flex items-center justify-between mt-1.5 pt-1.5 border-t border-indigo-200"><span class="text-base font-semibold text-gray-800">Total due' + (showTaxDisplay ? ' <span class="text-xs font-normal text-gray-500">(incl. tax)</span>' : '') + '</span><span class="text-[calc(1.125rem-3px)] font-bold text-indigo-900 tabular-nums">Rp ' + b.grand.toLocaleString('id-ID') + '</span></div>';
                host.innerHTML = '<div class="mt-3 rounded-xl border border-indigo-100 bg-indigo-50/60 px-3 py-2.5"><p class="text-[calc(0.75rem-1px)] font-semibold uppercase tracking-wide text-indigo-700 mb-2">Order summary</p>' + rows + '</div>';
            }
            syncEditPaymentIncrementUi();
            applyEditModalPaymentLockUi();
        }
        function rentalMoneyLiveCartDiscount(el, id) {
            var item = cart[id];
            if (!item) return;
            if (item.discountType === 'fixed') rentalApplyLiveMoneyFormatting(el);
            else el.value = String(el.value).replace(/[^\d.,]/g, '').replace(',', '.');
            item.discount = el.value;
            updateCreatePricingDisplays();
        }
        function rentalMoneyBlurCartDiscount(el, id) {
            var item = cart[id];
            if (!item || item.discountType !== 'fixed') return;
            var n = rentalMoneyParse(el.value);
            el.value = (Number.isFinite(n) && n > 0) ? rentalMoneyFormatNum(n) : '';
            item.discount = el.value;
            updateCreatePricingDisplays();
        }
        function rentalMoneyLiveEditCartDiscount(el, id) {
            var item = editCart[id];
            if (!item) return;
            if (item.discountType === 'fixed') rentalApplyLiveMoneyFormatting(el);
            else el.value = String(el.value).replace(/[^\d.,]/g, '').replace(',', '.');
            item.discount = el.value;
            updateEditPricingDisplays();
        }
        function rentalMoneyBlurEditCartDiscount(el, id) {
            var item = editCart[id];
            if (!item || item.discountType !== 'fixed') return;
            var n = rentalMoneyParse(el.value);
            el.value = (Number.isFinite(n) && n > 0) ? rentalMoneyFormatNum(n) : '';
            item.discount = el.value;
            updateEditPricingDisplays();
        }
        function updatePaymentBalanceHintCreate() {
            var out = document.getElementById('createPaymentBalanceDisplay');
            var apEl = document.getElementById('amountPaid');
            if (!out || !apEl) return;
            var shell = 'text-[calc(0.875rem-1px)] font-medium tabular-nums leading-snug border-t border-indigo-100/80 pt-2 mt-0.5 min-h-[2.75rem] flex flex-col justify-center gap-1 ';
            var items = Object.values(cart);
            if (!items.length) {
                out.className = shell + 'text-gray-400 text-[calc(0.75rem-1px)]';
                out.textContent = '';
                return;
            }
            var due = computeCreatePricingBreakdown().grand;
            var raw = apEl.value.trim();
            if (raw === '') {
                out.className = shell + 'text-gray-600';
                var downCk = document.getElementById('createPaymentKindDown');
                out.textContent = (downCk && downCk.checked)
                    ? 'Enter down payment to see balance due.'
                    : 'Enter amount paid to see change due or balance.';
                return;
            }
            var ap = rentalMoneyParse(raw);
            if (!Number.isFinite(ap)) {
                out.className = shell + 'text-amber-800';
                out.textContent = 'Check amount format.';
                return;
            }
            var diff = ap - due;
            if (diff > 0.004) {
                out.className = shell + 'text-gray-800';
                out.innerHTML = '<span class="text-[calc(0.875rem-1px)] font-semibold text-emerald-800">Change (cash to return)</span>'
                    + '<span class="text-[calc(1.125rem-3px)] sm:text-[calc(1.25rem-3px)] font-bold tabular-nums text-emerald-950 leading-none">Rp ' + diff.toLocaleString('id-ID') + '</span>';
            } else if (diff < -0.004) {
                out.className = shell + 'text-gray-800';
                out.innerHTML = '<span class="text-[calc(0.875rem-1px)] font-semibold text-amber-900">Balance due</span>'
                    + '<span class="text-[calc(1.125rem-3px)] sm:text-[calc(1.25rem-3px)] font-bold tabular-nums text-amber-950 leading-none">Rp ' + Math.abs(diff).toLocaleString('id-ID') + '</span>';
            } else {
                out.className = shell + 'text-gray-800';
                out.innerHTML = '<span class="text-[calc(1rem-1px)] font-semibold text-gray-800">Matches total due</span>'
                    + '<span class="text-[calc(1.125rem-3px)] font-bold tabular-nums text-indigo-950 leading-none">Rp ' + due.toLocaleString('id-ID') + '</span>';
            }
        }
        /** True when Quick edit should collect an additional payment (balance remains after recorded amount). */
        function editModalPaymentFieldsLocked() {
            if (!_pricingEnabled) {
                return false;
            }
            if (_adminTransactionEditMode) {
                return false;
            }
            if (currentEditRentalStoredPaymentKind === null) {
                return false;
            }
            // Lock only when recorded payments actually cover the total — a
            // full/final payment below a recomputed total must stay collectible.
            var ids = Object.keys(editCart);
            var due = null;
            if (ids.length) {
                due = computeEditPricingBreakdown().grand;
            } else if (editSnapshotTotalDue != null && Number.isFinite(Number(editSnapshotTotalDue))) {
                due = Number(editSnapshotTotalDue);
            }
            if (due == null || !Number.isFinite(due)) {
                return false;
            }
            // Overdue change is cash handed back to the customer, so it never counts as paid.
            var overduePaid = Number(editOverduePaymentBasePaid) || 0;
            var totalPaid = (Number(editPaymentBasePaid) || 0) + overduePaid;
            return totalPaid + 0.004 >= due;
        }

        function resetEditModalPaymentLockUi() {
            currentEditRentalStoredPaymentKind = null;
            editPaymentLockSnapshot = null;
            var pm = document.getElementById('editPaymentMethod');
            var eap = document.getElementById('editAmountPaid');
            var ekf = document.getElementById('editPaymentKindFull');
            var ekd = document.getElementById('editPaymentKindDown');
            var pe = document.getElementById('editPaymentEntry');
            [pm, eap, ekf, ekd, pe].forEach(function(el) {
                if (el) {
                    el.disabled = false;
                }
            });
            if (eap) {
                eap.readOnly = false;
                eap.classList.remove('bg-gray-100', 'text-gray-500', 'cursor-not-allowed', 'opacity-80');
            }
            var ban = document.getElementById('editPaymentLockedBanner');
            var editHint = document.getElementById('editPaymentEditableHint');
            if (ban) {
                ban.classList.add('hidden');
                ban.textContent = '';
            }
            if (editHint) {
                editHint.classList.add('hidden');
            }
            editOverduePaymentBasePaid = 0;
            editOverduePaymentBaseChange = 0;
            var ovPanel = document.getElementById('editOverduePayPanel');
            if (ovPanel) ovPanel.classList.add('hidden');
            var ovInfo = document.getElementById('editOverduePayInfo');
            if (ovInfo) ovInfo.textContent = '';
            var ovBtn = document.getElementById('editPayOverdueBtn');
            if (ovBtn) ovBtn.classList.add('hidden');
            var ovMethod = document.getElementById('editOverduePaymentMethod');
            if (ovMethod) ovMethod.value = '';
            var ovAmt = document.getElementById('editOverdueAmountPaid');
            if (ovAmt) ovAmt.value = '';
            var ovEntry = document.getElementById('editOverduePaymentEntry');
            if (ovEntry) ovEntry.value = 'replace';
        }

        function applyEditModalPaymentLockUi() {
            if (!_pricingEnabled) {
                return;
            }
            var locked = editModalPaymentFieldsLocked();
            var pm = document.getElementById('editPaymentMethod');
            var eap = document.getElementById('editAmountPaid');
            var ekf = document.getElementById('editPaymentKindFull');
            var ekd = document.getElementById('editPaymentKindDown');
            var ekfp = document.getElementById('editPaymentKindFinalPayment');
            var ekfpRow = document.getElementById('editPaymentKindFinalPaymentRow');
            var pe = document.getElementById('editPaymentEntry');
            var ban = document.getElementById('editPaymentLockedBanner');
            var editHint = document.getElementById('editPaymentEditableHint');
            var exc = document.getElementById('editExtraCharge');
            var edisc = document.getElementById('editDiscount');
            var ediscType = document.getElementById('editDiscountType');
            var ediscNote = document.getElementById('editDiscountNote');
            var discountLocked = !_viewerDiscountLockExempt && (locked || (!_adminTransactionEditMode && currentEditRentalStoredPaymentKind === 'down_payment'));
            [pm, eap, ekf, ekd, exc].forEach(function(el) {
                if (el) {
                    el.disabled = locked;
                }
            });
            [edisc, ediscType, ediscNote].forEach(function(el) {
                if (el) {
                    el.disabled = discountLocked;
                }
            });
            if (ekfp) ekfp.disabled = locked;
            var showFinalPayment = currentEditRentalStoredPaymentKind === 'down_payment' || currentEditRentalStoredPaymentKind === 'final_payment';
            if (ekfpRow) ekfpRow.style.display = showFinalPayment ? '' : 'none';
            if (pe) {
                pe.disabled = false;
                if (locked) {
                    pe.value = 'replace';
                }
            }
            if (ban) {
                if (locked) {
                    ban.textContent = currentEditRentalStoredPaymentKind === 'final_payment'
                        ? 'Final payment locked — can\'t be changed here.'
                        : currentEditRentalStoredPaymentKind !== 'down_payment'
                            ? 'Fully paid — payment locked.'
                            : 'Payment already covers the total — locked.';
                    ban.classList.remove('hidden');
                } else {
                    ban.classList.add('hidden');
                    ban.textContent = '';
                }
            }
            if (editHint) {
                if (!locked) {
                    editHint.classList.remove('hidden');
                } else {
                    editHint.classList.add('hidden');
                }
            }
            var bh = document.getElementById('editPaymentBaseHint');
            if (bh && locked) {
                bh.innerHTML = '';
                bh.classList.add('hidden');
            }
            var bal = document.getElementById('editPaymentBalanceDisplay');
            if (bal) {
                if (locked) {
                    updatePaymentBalanceHintEdit();
                } else {
                    bal.className = 'mt-3 text-sm font-semibold tabular-nums leading-snug rounded-lg border-2 border-indigo-100 bg-white/95 px-3 py-2.5 text-indigo-950 min-h-[2.75rem] flex items-center shadow-sm';
                    updatePaymentBalanceHintEdit();
                }
            }
            if (_pricingEnabled && typeof updateEditChangeMoneyInDuePanel === 'function') {
                updateEditChangeMoneyInDuePanel();
            }
            var cartEl = document.getElementById('editOutfitCart');
            if (cartEl) {
                // Only lock the per-item discount fields here — never the item
                // Remove/Add buttons. Item mutation is governed solely by
                // editModalViewOnly/editModalHardLocked (see applyEditModalViewOnlyState),
                // not by whether a down payment has been recorded.
                cartEl.querySelectorAll('input, select, textarea').forEach(function(el) {
                    el.disabled = discountLocked;
                });
            }
        }

        function incrementPaymentUiActive() {
            if (!_pricingEnabled) {
                return false;
            }
            if (editModalPaymentFieldsLocked()) {
                return false;
            }
            var ids = Object.keys(editCart);
            if (!ids.length) {
                return false;
            }
            var due = computeEditPricingBreakdown().grand;
            if (!Number.isFinite(due)) {
                return false;
            }
            // grand includes the overdue fee, so credit overdue payments too.
            var rem = Math.max(0, due - editPaymentBasePaid - (Number(editOverduePaymentBasePaid) || 0));
            return editPaymentBasePaid > 0.004 && rem > 0.004;
        }

        function syncEditPaymentIncrementUi() {
            if (!_pricingEnabled) {
                return;
            }
            var eap = document.getElementById('editAmountPaid');
            var pe = document.getElementById('editPaymentEntry');
            if (!eap || !pe) {
                return;
            }
            if (typeof editModalPaymentFieldsLocked === 'function' && editModalPaymentFieldsLocked()) {
                var bh0 = document.getElementById('editPaymentBaseHint');
                eap.name = 'amount_paid';
                eap.value = editPaymentBasePaid > 0.004 ? rentalMoneyFormatNum(editPaymentBasePaid) : '';
                eap.setAttribute('placeholder', 'e.g. 200.000');
                eap.readOnly = false;
                eap.classList.remove('bg-gray-100', 'text-gray-500', 'cursor-not-allowed', 'opacity-80');
                if (bh0) {
                    bh0.innerHTML = '';
                    bh0.classList.add('hidden');
                }
                editPaymentFieldMode = 'replace';
                pe.value = 'replace';
                syncEditPaymentKindUi();
                syncEditPayButtonUi();
                syncEditOverdueReturnUi();
                return;
            }
            var ids = Object.keys(editCart);
            var due = null;
            if (ids.length) {
                due = computeEditPricingBreakdown().grand;
            } else if (editSnapshotTotalDue != null && Number.isFinite(Number(editSnapshotTotalDue))) {
                due = Number(editSnapshotTotalDue);
            }
            // grand includes the overdue fee, so credit overdue payments too.
            var rem = (due !== null && Number.isFinite(due)) ? Math.max(0, due - editPaymentBasePaid - (Number(editOverduePaymentBasePaid) || 0)) : null;
            var inc = editPaymentBasePaid > 0.004 && rem !== null && rem > 0.004;
            var next = inc ? 'increment' : 'replace';
            if (next === 'increment') {
                // The remaining balance is a fact, not something to type — locking it
                // out removes the class of mistake where a careless or "rounded up"
                // amount gets recorded as an overpayment needing a refund later.
                eap.value = rem != null ? rentalMoneyFormatNum(rem) : '';
                eap.readOnly = true;
                eap.classList.add('bg-gray-100', 'text-gray-500', 'cursor-not-allowed', 'opacity-80');
                if (typeof editHighlightCheck === 'function') editHighlightCheck(eap);
            } else if (editPaymentFieldMode === null || next !== editPaymentFieldMode) {
                eap.value = editPaymentBasePaid > 0.004 ? rentalMoneyFormatNum(editPaymentBasePaid) : '';
                eap.readOnly = false;
                eap.classList.remove('bg-gray-100', 'text-gray-500', 'cursor-not-allowed', 'opacity-80');
            }
            editPaymentFieldMode = next;
            pe.value = next === 'increment' ? 'increment' : 'replace';
            var bh = document.getElementById('editPaymentBaseHint');
            if (inc) {
                eap.name = 'payment_amount_add';
                eap.removeAttribute('placeholder');
                var lbl = document.getElementById('editAmountPaidLabel');
                if (lbl) {
                    lbl.textContent = 'Amount to pay';
                }
                if (bh) {
                    var recStr = editPaymentBasePaid.toLocaleString('id-ID');
                    var remStr = rem != null ? rem.toLocaleString('id-ID') : '';
                    bh.innerHTML = '<div class="flex flex-col gap-2.5">'
                        + '<div class="flex flex-wrap items-end justify-between gap-x-3 gap-y-1">'
                        + '<span class="shrink-0 text-xs font-extrabold uppercase tracking-wider text-indigo-800">Down payment</span>'
                        + '<span class="text-lg sm:text-xl font-black tabular-nums tracking-tight text-indigo-950 leading-none">Rp ' + recStr + '</span>'
                        + '</div>'
                        + (rem != null
                            ? '<div class="flex flex-wrap items-end justify-between gap-x-3 gap-y-1 border-t-2 border-dashed border-indigo-200/90 pt-2.5">'
                            + '<span class="shrink-0 text-xs font-extrabold uppercase tracking-wider text-amber-900">Balance</span>'
                            + '<span class="text-lg sm:text-xl font-black tabular-nums tracking-tight text-amber-950 leading-none">Rp ' + remStr + '</span>'
                            + '</div>'
                            : '')
                        + '</div>';
                    bh.classList.remove('hidden');
                }
            } else {
                eap.name = 'amount_paid';
                eap.setAttribute('placeholder', 'e.g. 200.000');
                if (bh) {
                    bh.innerHTML = '';
                    bh.classList.add('hidden');
                }
                syncEditPaymentKindUi();
                syncEditPayButtonUi();
                syncEditOverdueReturnUi();
                return;
            }
            updatePaymentBalanceHintEdit();
            syncEditPayButtonUi();
            syncEditOverdueReturnUi();
        }

        function syncEditOverdueReturnUi() {
            var panel = document.getElementById('editOverdueReturnPanel');
            if (!panel || !_pricingEnabled) {
                return;
            }
            var actionTitleEl = document.getElementById('editOverdueActionTitle');
            var active = !editModalViewOnly && currentEditRentalStatus === 'active';
            if (!active || editOverdueDismissed) {
                editOverdueSummary = null;
                panel.classList.add('hidden');
                if (actionTitleEl) {
                    actionTitleEl.textContent = '';
                    actionTitleEl.classList.add('hidden');
                }
                return;
            }
            if (typeof todayYmdLocal !== 'function') {
                editOverdueSummary = null;
                panel.classList.add('hidden');
                if (actionTitleEl) {
                    actionTitleEl.textContent = '';
                    actionTitleEl.classList.add('hidden');
                }
                return;
            }
            var edEl = document.getElementById('editEd');
            var infoEl = document.getElementById('editOverdueInfo');
            var applyBtn = document.getElementById('editApplyOverdueChargeBtn');
            var dismissBtn = document.getElementById('editDismissOverdueBtn');
            var titleEl = document.getElementById('editOverdueTitle');
            if (!edEl || !infoEl || !applyBtn) {
                editOverdueSummary = null;
                panel.classList.add('hidden');
                if (actionTitleEl) {
                    actionTitleEl.textContent = '';
                    actionTitleEl.classList.add('hidden');
                }
                return;
            }
            var dueEnd = String(editOriginalEndDateYmd || '');
            var today = String(todayYmdLocal() || '');
            var msDue = rentalYmdUtcMs(dueEnd);
            var msToday = rentalYmdUtcMs(today);
            var fee = editOverdueFeeAmountNum();
            var paid = Number(editOverduePaymentBasePaid) || 0;
            var paidChange = Number(editOverduePaymentBaseChange) || 0;
            var paidCash = paid + paidChange;
            var hasHistory = fee > 0.004 || paidCash > 0.004;
            var overdueNow = !!(dueEnd && Number.isFinite(msDue) && today && Number.isFinite(msToday) && msToday > msDue);
            if (!overdueNow && !hasHistory) {
                editOverdueSummary = null;
                panel.classList.add('hidden');
                if (actionTitleEl) {
                    actionTitleEl.textContent = '';
                    actionTitleEl.classList.add('hidden');
                }
                return;
            }
            var ids = Object.keys(editCart);
            if (!ids.length && !hasHistory) {
                editOverdueSummary = null;
                panel.classList.add('hidden');
                if (actionTitleEl) {
                    actionTitleEl.textContent = '';
                    actionTitleEl.classList.add('hidden');
                }
                return;
            }
            var sdEl = document.getElementById('editSd');
            var startYmd = sdEl ? String(sdEl.value || '') : '';
            if (!startYmd && !hasHistory) {
                editOverdueSummary = null;
                panel.classList.add('hidden');
                if (actionTitleEl) {
                    actionTitleEl.textContent = '';
                    actionTitleEl.classList.add('hidden');
                }
                return;
            }
            var msDueBoundary = msDue + 86400000;
            var msNow = (__RENTAL_DEBUG_TODAY_YMD ? msToday : Date.now());
            var overdueSeconds = Math.max(0, Math.floor((msNow - msDueBoundary) / 1000));
            var overdueHuman = formatOverdueSeconds(overdueSeconds);
            var overdueDays = Math.max(1, Math.floor((msToday - msDue) / 86400000));
            if (!overdueHuman) {
                overdueHuman = String(Math.max(1, overdueDays)) + (overdueDays === 1 ? ' day overdue' : ' days overdue');
            }
            if (!overdueNow) {
                overdueHuman = 'Not overdue today';
            }

            var rem = Math.max(0, fee - paid);
            var thruEl = document.getElementById('editOverdueChargeThroughDate');
            var lastThru = thruEl ? String(thruEl.value || '').trim() : '';
            var msLastThru = lastThru ? rentalYmdUtcMs(lastThru) : NaN;
            var msSince = Number.isFinite(msLastThru) ? msLastThru : msDue;
            var additionalDays = overdueNow && msToday > msSince ? Math.max(1, Math.floor((msToday - msSince) / 86400000)) : 0;
            var rate = overdueFineRatePerDay();
            var additional = Math.max(0, rate * additionalDays);
            var feeApplied = fee > 0.004 || !!editOverdueMergedOnce || paid > 0.004;
            var manualWrap = document.getElementById('editOverdueManualRateWrap');
            var showManual = _overdueFineType === 'manual' && overdueNow && (!feeApplied || additionalDays > 0);
            if (manualWrap) manualWrap.classList.toggle('hidden', !showManual);
            if (feeApplied) {
                editOverdueSummary = null;
                if (titleEl) titleEl.textContent = 'Overdue charge';
                if (actionTitleEl) {
                    actionTitleEl.textContent = '';
                    actionTitleEl.classList.add('hidden');
                }
                infoEl.innerHTML = '<div class="flex flex-col gap-1.5">'
                    + (overdueNow ? '<div><strong>' + overdueHuman + '</strong> (due: <span class="tabular-nums">' + dueEnd + '</span>).</div>' : '<div><strong>Overdue history</strong> (due: <span class="tabular-nums">' + dueEnd + '</span>).</div>')
                    + (fee > 0.004 ? '<div>Overdue fine: <strong class="tabular-nums">Rp ' + fee.toLocaleString('id-ID') + '</strong></div>' : '')
                    + (paidCash > 0.004 ? '<div>Paid: <strong class="tabular-nums">Rp ' + paidCash.toLocaleString('id-ID') + '</strong></div>' : '')
                    + (paidChange > 0.004 ? '<div>Change (cash to return): <strong class="tabular-nums">Rp ' + paidChange.toLocaleString('id-ID') + '</strong></div>' : '')
                    + (fee > 0.004
                        ? (rem > 0.004
                            ? '<div>Remaining: <strong class="tabular-nums">Rp ' + rem.toLocaleString('id-ID') + '</strong></div>'
                            : '<div><strong>Settled</strong></div>')
                        : '')
                    + (overdueNow && additional > 0.004 ? '<div class="text-amber-900/90">Additional charge available (' + additionalDays + (additionalDays === 1 ? ' day' : ' days') + '): <strong class="tabular-nums">Rp ' + additional.toLocaleString('id-ID') + '</strong></div>' : '')
                    + '</div>';
                if (overdueNow && additional > 0.004) {
                    if (actionTitleEl) {
                        actionTitleEl.textContent = 'Apply additional charge (+Rp ' + additional.toLocaleString('id-ID') + ')';
                        actionTitleEl.classList.remove('hidden');
                    }
                    applyBtn.textContent = 'Apply';
                    applyBtn.classList.remove('hidden');
                    if (dismissBtn) dismissBtn.classList.remove('hidden');
                } else {
                    applyBtn.classList.add('hidden');
                    if (dismissBtn) dismissBtn.classList.add('hidden');
                }
                panel.classList.remove('hidden');
                if (typeof syncEditOverduePaymentUi === 'function') {
                    syncEditOverduePaymentUi();
                }
                return;
            }

            var extra = Math.max(0, rate * overdueDays);
            var before = computeEditPricingBreakdown();
            var nextTotal = (Number(before.grand) || 0) + extra;
            editOverdueSummary = {
                due_end: dueEnd,
                today: today,
                overdue_days: overdueDays,
                extra: extra,
                next_total: nextTotal,
            };
            if (titleEl) titleEl.textContent = 'Add charge';
            if (actionTitleEl) {
                actionTitleEl.textContent = extra > 0.004 ? ('Apply charge (+Rp ' + extra.toLocaleString('id-ID') + ')') : 'Apply charge';
                actionTitleEl.classList.remove('hidden');
            }
            infoEl.innerHTML = '<div class="flex flex-col gap-1.5">'
                + '<div><strong>' + overdueHuman + '</strong> (due: <span class="tabular-nums">' + dueEnd + '</span>).</div>'
                + '<div>Overdue fine: <strong class="tabular-nums">Rp ' + extra.toLocaleString('id-ID') + '</strong> <span class="text-xs text-gray-600">(Rp ' + rate.toLocaleString('id-ID') + ' × ' + overdueDays + (overdueDays === 1 ? ' day' : ' days') + ')</span></div>'
                + '<div>New total: <strong class="tabular-nums">Rp ' + nextTotal.toLocaleString('id-ID') + '</strong></div>'
                + '</div>';
            applyBtn.textContent = 'Apply';
            applyBtn.classList.remove('hidden');
            if (dismissBtn) dismissBtn.classList.remove('hidden');
            var payPanel = document.getElementById('editOverduePayPanel');
            if (payPanel) payPanel.classList.add('hidden');
            panel.classList.remove('hidden');
        }

        function updateEditChangeMoneyInDuePanel() {
            var panel = document.getElementById('editChangeMoneyInDuePanel');
            var amtEl = document.getElementById('editChangeMoneyAmount');
            var paidPanel = document.getElementById('editCustomerPaidPanel');
            var paidEl = document.getElementById('editCustomerPaidAmount');
            var paidSub = document.getElementById('editCustomerPaidSubtitle');
            var paidBreak = document.getElementById('editCustomerPaidBreakdown');
            if (!panel || !amtEl || !_pricingEnabled) {
                if (paidPanel) paidPanel.classList.add('hidden');
                return;
            }
            var ids = Object.keys(editCart);
            var due;
            if (ids.length) {
                due = computeEditPricingBreakdown().grand;
                if (Number.isFinite(due)) {
                    editSnapshotTotalDue = due;
                }
            } else if (editSnapshotTotalDue != null && Number.isFinite(Number(editSnapshotTotalDue))) {
                due = Number(editSnapshotTotalDue);
            } else {
                panel.classList.add('hidden');
                return;
            }
            if (!Number.isFinite(due)) {
                panel.classList.add('hidden');
                if (paidPanel) paidPanel.classList.add('hidden');
                return;
            }
            var apEl = document.getElementById('editAmountPaid');
            var inc = editPaymentFieldMode === 'increment';
            var effectivePaid;
            var overduePaid = (Number(editOverduePaymentBasePaid) || 0) + (Number(editOverduePaymentBaseChange) || 0);
            if (typeof editModalPaymentFieldsLocked === 'function' && editModalPaymentFieldsLocked()) {
                effectivePaid = editPaymentBasePaid;
            } else {
                var raw = apEl ? String(apEl.value).trim() : '';
                var parsed = raw === '' ? NaN : rentalMoneyParse(raw);
                if (inc) {
                    effectivePaid = editPaymentBasePaid + (Number.isFinite(parsed) ? parsed : 0);
                } else {
                    effectivePaid = Number.isFinite(parsed) ? parsed : editPaymentBasePaid;
                }
            }
            var diff = (effectivePaid + overduePaid) - due;
            var breakdownEl = document.getElementById('editChangeMoneyBreakdown');
            if (diff > 0.004) {
                panel.classList.remove('hidden');
                // Same "paid more than owed" number can mean two very
                // different things: ordinary cash-back-at-checkout change,
                // or leftover from an item just unchecked out of the cart —
                // a refund the customer is owed, not a till transaction.
                // editSelectedIds is the snapshot taken when the modal
                // opened (or dates were last set) — if any of those ids
                // aren't in the live cart anymore, something was removed.
                var currentCartIds = Object.keys(editCart).map(Number);
                var liveRemovalHappened = (editSelectedIds || []).some(function (id) {
                    return currentCartIds.indexOf(id) === -1;
                });
                var itemWasRemoved = !!currentRentalHasItemRemovalApproved || liveRemovalHappened;
                var labelEl = document.getElementById('editChangeMoneyLabel');
                var subtitleEl = document.getElementById('editChangeMoneySubtitle');
                // Split the overpayment only when we actually have a
                // pre-removal baseline from THIS editing session (items were
                // unchecked live) — editOriginalDueAtOpen is the due as it
                // stood when the modal opened, before any of this session's
                // cart changes. Without a live removal we can't attribute
                // how much of an already-approved-removal's overpayment came
                // from the removal vs. the customer's own payment, so that
                // case still shows a single "Refund due" total (below).
                var originalDueNum = (editOriginalDueAtOpen != null && Number.isFinite(Number(editOriginalDueAtOpen))) ? Number(editOriginalDueAtOpen) : null;
                var changeFromPayment = 0;
                var refundFromRemoval = 0;
                var showSplit = false;
                if (liveRemovalHappened && originalDueNum != null) {
                    var totalPaidNow = effectivePaid + overduePaid;
                    changeFromPayment = Math.min(diff, Math.max(0, totalPaidNow - originalDueNum));
                    refundFromRemoval = diff - changeFromPayment;
                    showSplit = changeFromPayment > 0.004 && refundFromRemoval > 0.004;
                } else if (!liveRemovalHappened && currentRentalItemRemovalChangeAmount != null && currentRentalItemRemovalRefundAmount != null) {
                    // No live edit this session — fall back to the split computed
                    // and stored server-side at the moment that removal was
                    // approved. Only trust it if it still adds up to the current
                    // excess (guards against a stale split if amount_paid changed
                    // since that approval).
                    var storedSum = currentRentalItemRemovalChangeAmount + currentRentalItemRemovalRefundAmount;
                    if (Math.abs(storedSum - diff) <= 1) {
                        changeFromPayment = currentRentalItemRemovalChangeAmount;
                        refundFromRemoval = currentRentalItemRemovalRefundAmount;
                        showSplit = changeFromPayment > 0.004 && refundFromRemoval > 0.004;
                    }
                }
                if (breakdownEl) breakdownEl.classList.add('hidden');
                if (showSplit) {
                    amtEl.textContent = 'Rp ' + diff.toLocaleString('id-ID');
                    labelEl.textContent = 'Change + Refund due';
                    labelEl.className = 'text-xs font-bold uppercase tracking-wide text-amber-900';
                    subtitleEl.textContent = 'Part payment change, part refund from the removed item';
                    panel.className = 'w-full rounded-lg border border-amber-300/80 bg-amber-50/90 px-3 py-2.5 ring-1 ring-amber-200/60';
                    amtEl.className = 'text-[calc(1.125rem-3px)] sm:text-[calc(1.25rem-3px)] font-extrabold tabular-nums text-amber-950 leading-none shrink-0';
                    if (breakdownEl) {
                        breakdownEl.classList.remove('hidden');
                        breakdownEl.className = 'mt-2 grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 text-xs border-t border-amber-300/60 pt-2';
                        breakdownEl.innerHTML =
                            '<span class="font-medium text-emerald-900">Change (cash to return)</span>'
                            + '<span class="font-semibold tabular-nums text-emerald-950">Rp ' + changeFromPayment.toLocaleString('id-ID') + '</span>'
                            + '<span class="font-medium text-red-900">Refund due (item removed)</span>'
                            + '<span class="font-semibold tabular-nums text-red-950">Rp ' + refundFromRemoval.toLocaleString('id-ID') + '</span>';
                    }
                } else if (itemWasRemoved) {
                    amtEl.textContent = 'Rp ' + diff.toLocaleString('id-ID');
                    labelEl.textContent = 'Refund due';
                    labelEl.className = 'text-xs font-bold uppercase tracking-wide text-red-900';
                    subtitleEl.textContent = 'Item removed — customer paid more than the new total';
                    panel.className = 'w-full rounded-lg border border-red-300/80 bg-red-50/90 px-3 py-2.5 ring-1 ring-red-200/60';
                    amtEl.className = 'text-[calc(1.125rem-3px)] sm:text-[calc(1.25rem-3px)] font-extrabold tabular-nums text-red-950 leading-none shrink-0';
                } else {
                    amtEl.textContent = 'Rp ' + diff.toLocaleString('id-ID');
                    labelEl.textContent = 'Change';
                    labelEl.className = 'text-xs font-bold uppercase tracking-wide text-emerald-900';
                    subtitleEl.textContent = 'Cash to return to customer';
                    panel.className = 'w-full rounded-lg border border-emerald-300/80 bg-emerald-50/90 px-3 py-2.5 ring-1 ring-emerald-200/60';
                    amtEl.className = 'text-[calc(1.125rem-3px)] sm:text-[calc(1.25rem-3px)] font-extrabold tabular-nums text-emerald-950 leading-none shrink-0';
                }
            } else {
                panel.classList.add('hidden');
                if (breakdownEl) breakdownEl.classList.add('hidden');
            }
            // Charges breakdown: show the fine as a line item of Total due, so it
            // appears exactly once (POS ledger style: charges / payments / result).
            var feeNow = Math.max(0, editOverdueFeeAmountNum());
            var rentOnlyDue = Math.max(0, due - feeNow);
            var chargesBreak = document.getElementById('editChargesBreakdown');
            if (chargesBreak) {
                if (feeNow > 0.004 && due > feeNow) {
                    chargesBreak.innerHTML = '<div>Rent (after discounts)</div><div class="text-right font-semibold tabular-nums">Rp ' + Math.max(0, due - feeNow).toLocaleString('id-ID') + '</div>'
                        + '<div>Overdue fine</div><div class="text-right font-semibold tabular-nums">Rp ' + feeNow.toLocaleString('id-ID') + '</div>';
                    chargesBreak.classList.remove('hidden');
                } else {
                    chargesBreak.innerHTML = '';
                    chargesBreak.classList.add('hidden');
                }
            }
            if (paidPanel && paidEl) {
                // Payment rows show amounts actually kept/applied (capped at what was
                // due), matching the payment records the shift and reports count.
                // Cash physically handed over (which can exceed this) is Change, shown
                // as its own result line below — never mixed into the payments list.
                // While collecting the balance, "Amount to pay" is pre-filled but not
                // paid yet — only what is already recorded counts as paid here.
                var recordedPaid = inc ? editPaymentBasePaid : effectivePaid;
                var rentKept = Math.min(Math.max(0, recordedPaid), rentOnlyDue);
                var overdueKept = Math.min(Math.max(0, overduePaid), feeNow);
                var totalReceived = rentKept + overdueKept;
                // Payments already made must stay visible even while a balance is
                // outstanding — the balance line below carries the "still owed" story.
                if (totalReceived > 0.004) {
                    paidEl.textContent = 'Rp ' + totalReceived.toLocaleString('id-ID');
                    paidPanel.classList.remove('hidden');
                    if (paidBreak) {
                        var dpAmt = (editDownPaymentAmount != null && editDownPaymentAmount > 0.004) ? Math.min(Number(editDownPaymentAmount), rentKept) : null;
                        var paidRowParts = [];
                        if (dpAmt !== null) {
                            paidRowParts.push('<div>Down payment</div><div class="text-right font-semibold tabular-nums">Rp ' + dpAmt.toLocaleString('id-ID') + '</div>');
                            var finalPmt = rentKept - dpAmt;
                            if (finalPmt > 0.004) {
                                paidRowParts.push('<div>Final payment</div><div class="text-right font-semibold tabular-nums">Rp ' + finalPmt.toLocaleString('id-ID') + '</div>');
                            }
                        } else {
                            var rentLabel = currentEditRentalStoredPaymentKind === 'down_payment' ? 'Down payment' : 'Rent paid';
                            paidRowParts.push('<div>' + rentLabel + '</div><div class="text-right font-semibold tabular-nums">Rp ' + rentKept.toLocaleString('id-ID') + '</div>');
                        }
                        if (overdueKept > 0.004) {
                            paidRowParts.push('<div>Overdue paid</div><div class="text-right font-semibold tabular-nums">Rp ' + overdueKept.toLocaleString('id-ID') + '</div>');
                        }
                        paidBreak.innerHTML = paidRowParts.join('');
                        paidBreak.classList.remove('hidden');
                    }
                } else {
                    paidPanel.classList.add('hidden');
                    paidEl.textContent = '—';
                    if (paidBreak) {
                        paidBreak.innerHTML = '';
                        paidBreak.classList.add('hidden');
                    }
                }
            }
        }

        function updatePaymentBalanceHintEdit() {
            var out = document.getElementById('editPaymentBalanceDisplay');
            var apEl = document.getElementById('editAmountPaid');
            if (!out || !apEl) {
                return;
            }
            try {
                var boxBase = 'mt-3 text-[calc(1rem-1px)] font-semibold tabular-nums leading-snug rounded-lg border-2 px-3 py-2.5 min-h-[3rem] flex flex-col justify-center gap-1 shadow-sm ';
                var boxMuted = boxBase + 'border-gray-200 bg-gray-50 text-gray-500';
                var boxInfo = boxBase + 'border-indigo-200 bg-indigo-50/90 text-indigo-950';
                var boxWarn = boxBase + 'border-amber-300 bg-amber-50 text-amber-950';
                var boxOk = boxBase + 'border-emerald-300 bg-emerald-50 text-emerald-900';
                var boxErr = boxBase + 'border-amber-400 bg-amber-50 text-amber-900';
                var ids = Object.keys(editCart);
                if (!ids.length) {
                    out.className = boxMuted;
                    out.textContent = '';
                    return;
                }
                var b = computeEditPricingBreakdown();
                var due = b.grand;
                var overduePaid = (Number(editOverduePaymentBasePaid) || 0) + (Number(editOverduePaymentBaseChange) || 0);
                var inc = editPaymentFieldMode === 'increment';
                var raw = apEl.value.trim();
                if (raw === '') {
                    out.className = boxInfo;
                    if (inc && editPaymentBasePaid > 0.004) {
                        // grand includes the overdue fee, so credit overdue payments too.
                        var rem0 = Math.max(0, due - editPaymentBasePaid - (Number(editOverduePaymentBasePaid) || 0));
                        out.innerHTML = '<span class="text-[calc(0.875rem-1px)] font-semibold text-indigo-900">Still to collect</span>'
                            + '<span class="text-[calc(1.125rem-3px)] sm:text-[calc(1.25rem-3px)] font-bold tabular-nums text-indigo-950 leading-none">Rp ' + rem0.toLocaleString('id-ID') + '</span>'
                            + '<span class="text-[calc(0.875rem-1px)] font-medium text-indigo-800/90 leading-snug">Enter the additional payment in the field above.</span>';
                    } else {
                        var ovFee0 = typeof editOverdueFeeAmountNum === 'function' ? editOverdueFeeAmountNum() : 0;
                        var ovRem0 = Math.max(0, ovFee0 - (Number(editOverduePaymentBasePaid) || 0));
                        // due (grand) includes the overdue fee — rent balance is the fee-free part.
                        var rentRem0 = Math.max(0, due - ovFee0 - (Number(editPaymentBasePaid) || 0));
                        out.innerHTML = '<span class="text-[calc(0.875rem-1px)] font-semibold text-indigo-950">Rent balance <span class="tabular-nums">Rp ' + rentRem0.toLocaleString('id-ID') + '</span>'
                            + (ovRem0 > 0.004 ? ' · Overdue balance <span class="tabular-nums text-amber-900">Rp ' + ovRem0.toLocaleString('id-ID') + '</span>' : '')
                            + '</span>'
                            + '<span class="text-[calc(0.875rem-1px)] font-medium text-indigo-800/80">Enter amount paid to update.</span>';
                    }
                    return;
                }
                var parsed = rentalMoneyParse(raw);
                if (!Number.isFinite(parsed)) {
                    out.className = boxErr;
                    out.textContent = 'Check amount format.';
                    return;
                }
                var ap = inc ? (editPaymentBasePaid + parsed) : parsed;
                if (!Number.isFinite(ap)) {
                    out.className = boxErr;
                    out.textContent = 'Check amount format.';
                    return;
                }
                var totalPaid = ap + overduePaid;
                var diff = totalPaid - due;
                // Change handed back on the overdue fee is shown above as "Change", but it never pays the rent.
                var realDiff = ap + (Number(editOverduePaymentBasePaid) || 0) - due;
                if (diff > 0.004 && realDiff > -0.004) {
                    out.className = boxOk;
                    var settledCurrentCartIds = Object.keys(editCart).map(Number);
                    var settledLiveRemoval = (editSelectedIds || []).some(function (id) {
                        return settledCurrentCartIds.indexOf(id) === -1;
                    });
                    var settledItemWasRemoved = !!currentRentalHasItemRemovalApproved || settledLiveRemoval;
                    var settledOriginalDue = (editOriginalDueAtOpen != null && Number.isFinite(Number(editOriginalDueAtOpen))) ? Number(editOriginalDueAtOpen) : null;
                    var settledShowSplit = false;
                    if (settledLiveRemoval && settledOriginalDue != null) {
                        var settledChangePart = Math.min(diff, Math.max(0, totalPaid - settledOriginalDue));
                        var settledRefundPart = diff - settledChangePart;
                        settledShowSplit = settledChangePart > 0.004 && settledRefundPart > 0.004;
                    } else if (!settledLiveRemoval && currentRentalItemRemovalChangeAmount != null && currentRentalItemRemovalRefundAmount != null) {
                        var settledStoredSum = currentRentalItemRemovalChangeAmount + currentRentalItemRemovalRefundAmount;
                        if (Math.abs(settledStoredSum - diff) <= 1) {
                            settledShowSplit = currentRentalItemRemovalChangeAmount > 0.004 && currentRentalItemRemovalRefundAmount > 0.004;
                        }
                    }
                    out.innerHTML = settledShowSplit
                        ? '<span class="text-[calc(0.875rem-1px)] font-medium leading-snug">Settled — <strong>Change + Refund due</strong> breakdown is shown above.</span>'
                        : (settledItemWasRemoved
                            ? '<span class="text-[calc(0.875rem-1px)] font-medium leading-snug">Settled — <strong>Refund due</strong> (item removed) is shown above.</span>'
                            : '<span class="text-[calc(0.875rem-1px)] font-medium leading-snug">Settled — <strong>Change</strong> (cash to return) is shown above.</span>');
                } else if (realDiff < -0.004) {
                    out.className = boxWarn;
                    out.innerHTML = (inc ? '<span class="text-[calc(0.875rem-1px)] font-semibold text-amber-950">After this payment · Still due</span>' : '<span class="text-[calc(0.875rem-1px)] font-semibold text-amber-950">Still due</span>')
                        + '<span class="text-[calc(1.125rem-3px)] sm:text-[calc(1.25rem-3px)] font-bold tabular-nums text-amber-950 leading-none">Rp ' + Math.abs(realDiff).toLocaleString('id-ID') + '</span>';
                } else {
                    out.className = boxBase + 'border-emerald-300 bg-emerald-50/90 text-emerald-950';
                    out.innerHTML = (inc ? '<span class="text-[calc(0.875rem-1px)] font-semibold text-emerald-900">Settled after this payment</span>' : '<span class="text-[calc(0.875rem-1px)] font-semibold text-emerald-900">Settled</span>')
                        + '<span class="text-[calc(1.125rem-3px)] font-bold tabular-nums text-emerald-950 leading-none">Rp ' + due.toLocaleString('id-ID') + '</span>'
                        + '<span class="text-[calc(0.875rem-1px)] font-medium text-emerald-900/90">Matches total due.</span>';
                }
            } finally {
                if (_pricingEnabled) {
                    updateEditChangeMoneyInDuePanel();
                }
            }
        }

        function wireRentalPricingFormHelpers() {
            if (!_pricingEnabled) return;
            var ap = document.getElementById('amountPaid');
            if (ap) {
                bindRentalMoneyThousandsInput(ap, updatePaymentBalanceHintCreate);
                ap.addEventListener('blur', updatePaymentBalanceHintCreate);
            }
            var d = document.getElementById('discount');
            var dt = document.getElementById('discountType');
            if (d && dt) {
                bindSmartOrderDiscountInput(d, function() { return document.getElementById('discountType').value; });
                dt.addEventListener('change', function() {
                    var v = d.value.trim();
                    if (v !== '') {
                        var n = rentalMoneyParse(v);
                        if (Number.isFinite(n)) {
                            d.value = dt.value === 'fixed' ? rentalMoneyFormatNum(n) : String(parseFloat(String(n)));
                        }
                    }
                    updateCreatePricingDisplays();
                });
            }
            var cxc = document.getElementById('createExtraCharge');
            if (cxc) {
                bindRentalMoneyThousandsInput(cxc, updateCreatePricingDisplays);
            }
            var ed = document.getElementById('editDiscount');
            var edt = document.getElementById('editDiscountType');
            var eap = document.getElementById('editAmountPaid');
            if (eap) {
                bindRentalMoneyThousandsInput(eap, updatePaymentBalanceHintEdit);
                eap.addEventListener('blur', updatePaymentBalanceHintEdit);
            }
            if (ed && edt) {
                bindSmartEditOrderDiscountInput(ed, function() { return document.getElementById('editDiscountType').value; });
                edt.addEventListener('change', function() {
                    var v = ed.value.trim();
                    if (v !== '') {
                        var n = rentalMoneyParse(v);
                        if (Number.isFinite(n)) {
                            ed.value = edt.value === 'fixed' ? rentalMoneyFormatNum(n) : String(parseFloat(String(n)));
                        }
                    }
                    updateEditPricingDisplays();
                });
            }
            var exc = document.getElementById('editExtraCharge');
            if (exc) {
                bindRentalMoneyThousandsInput(exc, updateEditPricingDisplays);
            }
            var ovp = document.getElementById('editOverdueAmountPaid');
            if (ovp) {
                bindRentalMoneyThousandsInput(ovp, function() {
                    if (typeof clearEditErrorSummary === 'function') clearEditErrorSummary();
                });
            }
            var ckf = document.getElementById('createPaymentKindFull');
            var ckd = document.getElementById('createPaymentKindDown');
            if (ckf) ckf.addEventListener('change', function() {
                syncCreatePaymentKindUi();
            });
            if (ckd) ckd.addEventListener('change', function() {
                if (ckd.checked) {
                    var cap = document.getElementById('amountPaid');
                    if (cap) cap.value = '';
                }
                syncCreatePaymentKindUi();
            });
            var ekf = document.getElementById('editPaymentKindFull');
            var ekd = document.getElementById('editPaymentKindDown');
            if (ekf) ekf.addEventListener('change', function() {
                syncEditPaymentIncrementUi();
            });
            if (ekd) ekd.addEventListener('change', function() {
                if (ekd.checked) {
                    var eapx = document.getElementById('editAmountPaid');
                    if (eapx && !incrementPaymentUiActive()) {
                        eapx.value = '';
                    }
                }
                syncEditPaymentIncrementUi();
            });
            syncCreatePaymentKindUi();
            syncEditPaymentIncrementUi();
        }
        wireRentalPricingFormHelpers();

        function renderCart() {
            var items = Object.values(cart);
            var container = document.getElementById('cartItems');
            var empty = document.getElementById('cartEmpty');
            var section = document.getElementById('cartSection');
            var count = document.getElementById('cartCount');
            if (!container || !empty || !section || !count) {
                return;
            }
            count.textContent = items.length;
            if (!items.length) {
                section.classList.add('hidden');
                updateCreatePricingDisplays();
                syncCooldownApprovalOnlyUi();
                return;
            }
            section.classList.remove('hidden');
            empty.classList.add('hidden');
            container.innerHTML = '';
            var effectiveTotal = 0;
            var billingInfo = createRentalBillingInfo();
            var days = billingInfo.days;
            var _createMult = billingInfo.mode === 'block' ? Math.floor(Math.max(3, days) / 3) : days;
            items.forEach(function(item) {
                var unitPrice = rentalNumericOutfitPrice(item.price);
                var lineGross = unitPrice * _createMult;
                var effective = calcEffectivePrice(item, _createMult);
                effectiveTotal += effective;
                var div = document.createElement('div');
                var priceLine = '';
                if (_pricingEnabled) {
                    var grossLabel = rentalItemPriceLabel(unitPrice, billingInfo);
                    if (effective < lineGross - 1e-9) {
                        priceLine = '<span class="text-xs text-gray-400 line-through">' + grossLabel + '</span> <span class="text-sm text-green-700 font-medium">Rp ' + Math.max(0, effective).toLocaleString('id-ID') + '</span>';
                    } else {
                        priceLine = '<span class="text-sm text-gray-700">' + grossLabel + '</span>';
                    }
                }
                var stockLine = _pricingEnabled && (item.stock !== undefined && item.stock !== null) ? '<div class="text-xs text-gray-500">Stock: ' + (item.stock || 0) + '</div>' : '';
                var _discNumC = rentalMoneyParse(String(item.discount == null ? '' : item.discount));
                var discValEsc = (Number.isFinite(_discNumC) && _discNumC > 0) ? rentalMoneyFormatNum(_discNumC).replace(/"/g, '&quot;') : '';
                var discNoteEsc = String(item.discountNote || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                var discNoteHtml = _pricingEnabled ? '<textarea rows="1" oninput="updateCartDiscountNote(' + item.id + ', this.value);this.style.height=\'auto\';this.style.height=this.scrollHeight+\'px\'" class="form-input w-full text-xs mt-1 resize-none" placeholder="Discount reason" style="min-height:1.75rem">' + discNoteEsc + '</textarea>' : '';
                var discHtml = _pricingEnabled ? '<div class="flex items-center gap-1"><input type="text" value="' + discValEsc + '" oninput="rentalMoneyLiveCartDiscount(this,' + item.id + ')" onblur="rentalMoneyBlurCartDiscount(this,' + item.id + ')" onchange="updateCartDiscount(' + item.id + ', this.value)" class="form-input-compact w-[5.5rem] text-center" placeholder="0"><select onchange="updateCartDiscountType(' + item.id + ', this.value)" class="form-input-compact min-w-[4.5rem] cursor-pointer bg-white"><option value="fixed" ' + (item.discountType === 'fixed' ? 'selected' : '') + '>Rp</option><option value="percent" ' + (item.discountType === 'percent' ? 'selected' : '') + '>%</option></select></div>' : '';
                var approvalBadge = item.needs_manager_approval
                    ? '<span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-px text-[10px] font-semibold text-amber-900 align-middle">Needs approval</span>'
                    : '<span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-px text-[10px] font-semibold text-emerald-800 align-middle">Ready</span>';
                div.className = 'flex flex-col gap-1 px-3 py-2 bg-gradient-to-r from-gray-50 to-white rounded-xl border ' + (item.needs_manager_approval ? 'border-amber-300' : 'border-gray-200') + ' shadow-sm';
                div.innerHTML = '<div class="flex items-center justify-between gap-2"><div class="flex-1 min-w-0"><span class="text-sm font-medium text-gray-900">' + escRentalHtml(item.code) + (item.new_code ? ' | ' + escRentalHtml(item.new_code) : '') + '</span> ' + approvalBadge + stockLine + '</div><div class="flex items-center gap-2 shrink-0">' + priceLine + discHtml + '<button type="button" onclick="removeFromCart(' + item.id + ')" class="text-red-500 hover:text-red-700 text-lg leading-none cursor-pointer px-1">&times;</button></div></div>' + discNoteHtml;
                container.appendChild(div);
            });
            updateCreatePricingDisplays();
            syncCooldownApprovalOnlyUi();
        }

        var outfitListEl = document.getElementById('outfitList');
        if (outfitListEl) outfitListEl.addEventListener('click', function(e) {
            var row = e.target.closest('[data-id]');
            if (row) {
                var id = parseInt(row.getAttribute('data-id'), 10);
                var outfit = allOutfits.find(function(s) { return Number(s.id) === id; });
                if (outfit) addToCart(outfit);
            }
        });

        function updateConflictInfo() {
            var info = document.getElementById('conflictInfo');
            var approvalLines = [];
            var hardLines = [];
            Object.values(cart).forEach(function(item) {
                var outfit = allOutfits.find(function(s) { return outfitsSameId(s.id, item.id); });
                if (outfit && outfit.rental) {
                    var line = outfit.rental.customer + ' ' + outfit.rental.start + ' - ' + outfit.rental.end;
                    if (outfit.needs_manager_approval) approvalLines.push(line);
                    else hardLines.push(line);
                }
            });
            if (approvalLines.length || hardLines.length) {
                var html = '';
                if (approvalLines.length) {
                    html += '<div class="p-2 bg-amber-50 border border-amber-200 rounded text-amber-950 text-sm mt-2">Cool-down overlap detected. Use <strong>Request Management Approval</strong> at the bottom of this form (dates and items only for now). <strong>The rent becomes active only after management approves.</strong><br><span class="text-xs text-amber-900/90">' + approvalLines.join('<br>') + '</span></div>';
                }
                if (hardLines.length) {
                    html += '<div class="p-2 bg-red-50 border border-red-300 rounded text-red-700 mt-2">⚠ ' + hardLines.join('<br>') + '</div>';
                }
                info.innerHTML = html;
                info.classList.remove('hidden');
            } else {
                info.classList.add('hidden');
            }
        }

        function filterOutfitList(query) {
            var container = document.getElementById('outfitList');
            if (!container) return;
            var oldPh = document.getElementById('outfitListNoMatch');
            if (oldPh) oldPh.remove();

            var q = (query || '').trim().toLowerCase();
            var rowEls = [];
            for (var ci = 0; ci < container.children.length; ci++) {
                var ch = container.children[ci];
                if (ch.id === 'outfitListNoMatch') continue;
                if (ch.tagName === 'DIV' && !ch.classList.contains('quick-add-row')) rowEls.push(ch);
            }
            if (!q) {
                rowEls.forEach(function(row) { row.style.display = ''; });
                clearCreateErrorSummary();
                syncOutfitSearchClearVisible();
                return;
            }
            var anyVisible = false;
            rowEls.forEach(function(row) {
                var match = row.textContent.toLowerCase().includes(q);
                row.style.display = match ? '' : 'none';
                if (match) anyVisible = true;
            });
            if (!anyVisible && document.getElementById('outfitSearch').disabled === false) {
                var displayQ = (query || '').trim();
                var tabLabel = currentOutfitFilter === 'available' ? 'Available' : 'All';

                if (currentOutfitFilter === 'available') {
                    var matchesInAll = allOutfits.some(function(s) {
                        if (!s) return false;
                        if (cart && cart[Number(s.id)]) return false;
                        var hay = [
                            s.code,
                            s.new_code,
                            s.description,
                            s.rental && s.rental.customer,
                            s.rental && s.rental.start,
                            s.rental && s.rental.end,
                        ].filter(Boolean).join(' ').toLowerCase();
                        return hay.includes(q);
                    });

                    if (matchesInAll) {
                        var tabWrap = document.createElement('div');
                        tabWrap.id = 'outfitListNoMatch';
                        tabWrap.className = 'rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2.5 text-sm shadow-sm cursor-pointer hover:bg-indigo-50/70 hover:border-indigo-300 transition-colors select-none';
                        tabWrap.setAttribute('role', 'button');
                        tabWrap.setAttribute('tabindex', '0');
                        tabWrap.title = 'Show matches in All tab';
                        tabWrap.setAttribute('aria-label', 'Show matches in the All tab.');
                        tabWrap.addEventListener('click', function() {
                            var tabs = document.getElementById('outfitFilterTabs');
                            var allBtn = tabs ? tabs.querySelector('button[onclick*="\\\'all\\\'"]') : null;
                            setOutfitFilter('all', allBtn);
                        });
                        tabWrap.addEventListener('keydown', function(e) {
                            if (e.target !== tabWrap) return;
                            if (e.key !== 'Enter' && e.key !== ' ') return;
                            e.preventDefault();
                            var tabs = document.getElementById('outfitFilterTabs');
                            var allBtn = tabs ? tabs.querySelector('button[onclick*="\\\'all\\\'"]') : null;
                            setOutfitFilter('all', allBtn);
                        });

                        var tabHead = document.createElement('div');
                        tabHead.className = 'flex gap-2.5 items-start';
                        var tabIcon = document.createElement('span');
                        tabIcon.className = 'shrink-0 text-indigo-600 mt-0.5';
                        tabIcon.setAttribute('aria-hidden', 'true');
                        tabIcon.innerHTML = '<svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>';
                        tabHead.appendChild(tabIcon);
                        var tabCol = document.createElement('div');
                        tabCol.className = 'min-w-0 flex-1 space-y-1';

                        var tabTitle = document.createElement('p');
                        tabTitle.className = 'font-medium text-indigo-950';
                        tabTitle.textContent = 'Matches exist in All tab';
                        tabCol.appendChild(tabTitle);

                        var tabDetail = document.createElement('p');
                        tabDetail.className = 'text-xs text-indigo-900/80 leading-relaxed';
                        tabDetail.innerHTML = '“' + escRentalHtml(displayQ) + '” · <span class="text-indigo-900">Available tab</span> has no matches. Tap to switch to <span class="font-semibold">All</span>.';
                        tabCol.appendChild(tabDetail);

                        var tabCue = document.createElement('p');
                        tabCue.className = 'mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-700';
                        tabCue.setAttribute('aria-hidden', 'true');
                        tabCue.innerHTML = '<svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg><span>Switch to All</span>';
                        tabCol.appendChild(tabCue);
                        tabHead.appendChild(tabCol);
                        tabWrap.appendChild(tabHead);

                        container.appendChild(tabWrap);
                        syncOutfitSearchClearVisible();
                        return;
                    }
                }

                var wrap = document.createElement('div');
                wrap.id = 'outfitListNoMatch';
                var canQuickAdd = !!mayMutateItems && !!document.getElementById('quickAddCreatePanel');
                wrap.className = canQuickAdd
                    ? 'rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm shadow-sm cursor-pointer hover:bg-gray-100/90 hover:border-gray-300 transition-colors select-none'
                    : 'rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm shadow-sm select-none';
                if (canQuickAdd) {
                    wrap.setAttribute('role', 'button');
                    wrap.setAttribute('tabindex', '0');
                    wrap.title = 'Add new item (search copied to Code)';
                    wrap.setAttribute('aria-label', 'Add new item; search text is copied to the code field.');
                    wrap.addEventListener('click', function() {
                        openQuickAddCreatePanel(true);
                    });
                    wrap.addEventListener('keydown', function(e) {
                        if (e.target !== wrap) return;
                        if (e.key !== 'Enter' && e.key !== ' ') return;
                        e.preventDefault();
                        openQuickAddCreatePanel(true);
                    });
                } else {
                    wrap.setAttribute('role', 'status');
                    wrap.title = 'No matches';
                }

                var head = document.createElement('div');
                head.className = 'flex gap-2.5 items-start';
                var headIcon = document.createElement('span');
                headIcon.className = 'shrink-0 text-gray-500 mt-0.5';
                headIcon.setAttribute('aria-hidden', 'true');
                headIcon.innerHTML = '<svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>';
                head.appendChild(headIcon);
                var col = document.createElement('div');
                col.className = 'min-w-0 flex-1 space-y-1';

                var title = document.createElement('p');
                title.className = 'font-medium text-gray-900';
                title.textContent = 'No matches in this list';
                col.appendChild(title);

                var detail = document.createElement('p');
                detail.className = 'text-xs text-gray-600 leading-relaxed';
                detail.innerHTML = '“' + escRentalHtml(displayQ) + '” · <span class="text-gray-700">' + escRentalHtml(tabLabel) + ' tab</span> · selected dates. May be rented, filtered out, or not in inventory.';
                col.appendChild(detail);

                var hint = document.createElement('p');
                hint.className = 'text-xs text-gray-500 mt-1 leading-relaxed';
                hint.textContent = canQuickAdd
                    ? 'Tap anywhere here to add, or use the clear control in the search field.'
                    : 'Ask management to add new items, or use the clear control in the search field.';
                col.appendChild(hint);

                if (canQuickAdd) {
                    var addCue = document.createElement('p');
                    addCue.className = 'mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-700';
                    addCue.setAttribute('aria-hidden', 'true');
                    addCue.innerHTML = '<svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/></svg><span>Add</span>';
                    col.appendChild(addCue);
                }
                head.appendChild(col);
                wrap.appendChild(head);

                container.appendChild(wrap);
            } else {
                clearCreateErrorSummary();
            }
            syncOutfitSearchClearVisible();
        }

        function rentalsCsrfForJson() {
            var t = getRentalsCsrfToken();
            if (!t) {
                var inp = document.querySelector('#rentalForm input[name="_token"]');
                t = inp ? inp.value : '';
            }
            return t;
        }

        function findOutfitInAllById(rawId) {
            var nid = Number(rawId);
            if (!Number.isFinite(nid)) return null;
            return allOutfits.find(function(x) { return Number(x.id) === nid; }) || null;
        }

        function quickAddOutfit() {
            if (!mayMutateItems) {
                window.rentalAlert('Your account cannot create items.', { icon: 'warning', title: 'Not allowed' });
                return;
            }
            clearCreateErrorSummary();
            var input = document.getElementById('quickAddCreateInput');
            var newCodeInput = document.getElementById('quickAddCreateNewCode');
            var descInput = document.getElementById('quickAddCreateDescription');
            var code = (input ? input.value : '').trim();
            var newCode = (newCodeInput ? newCodeInput.value : '').trim();
            var description = (descInput ? descInput.value : '').trim();
            if (!code) {
                showCreateErrorSummary(['Enter a code first.']);
                return;
            }
            var payload = { code: code };
            if (newCode) payload.new_code = newCode;
            if (description) payload.description = description;
            var token = rentalsCsrfForJson();
            if (!token) {
                showCreateErrorSummary(['Missing security token. Refresh the page and try again.']);
                return;
            }
            fetch('/outfits/quick-add', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            }).then(function(r) {
                return r.text().then(function(text) {
                    var data = {};
                    try {
                        data = text ? JSON.parse(text) : {};
                    } catch (e) {
                        data = { message: text ? String(text).slice(0, 200) : 'Invalid response' };
                    }
                    return { r: r, data: data };
                });
            }).then(function(out) {
                var res = out.data;
                if (!out.r.ok) {
                    var msg = res.message || res.error || ('Request failed (' + out.r.status + ')');
                    if (res.errors && typeof res.errors === 'object') {
                        var flat = [];
                        Object.keys(res.errors).forEach(function(k) {
                            var v = res.errors[k];
                            if (Array.isArray(v)) flat = flat.concat(v);
                            else flat.push(String(v));
                        });
                        if (flat.length) msg = flat.join(' ');
                    }
                    showCreateErrorSummary([msg]);
                    return;
                }
                if (res.outfit) {
                    document.getElementById('outfitSearch').value = '';
                    if (input) input.value = '';
                    if (newCodeInput) newCodeInput.value = '';
                    if (descInput) descInput.value = '';
                    var newId = Number(res.outfit.id);
                    fetchOutfits(function() {
                        var s = findOutfitInAllById(newId);
                        if (s && outfitSelectable(s)) {
                            addToCart(s);
                            showRentalsListToast('Item added to cart.', 'success');
                        } else if (s) {
                            showCreateErrorSummary(['Item was added but cannot be booked for these dates from the Available tab. Switch to the All tab or adjust dates.']);
                        } else {
                            showCreateErrorSummary(['Item was added but is not in the list yet. Try Search or refresh the dates.']);
                        }
                    });
                    if (input) {
                        try { input.focus(); } catch (e) {}
                    }
                } else if (res && (res.message || res.error)) {
                    showCreateErrorSummary([res.message || res.error]);
                } else {
                    showCreateErrorSummary(['Could not add item.']);
                }
            }).catch(function() { showCreateErrorSummary(['Failed to add item. Please try again.']); });
        }

        function setOutfitFilter(filter, btn) {
            currentOutfitFilter = filter;
            document.querySelectorAll('#outfitFilterTabs button').forEach(function(b) {
                b.classList.remove('bg-white', 'shadow-sm', 'text-gray-900', 'font-semibold', 'border-indigo-600');
                b.classList.add('text-gray-600', 'border-gray-400', 'bg-gray-50');
            });
            if (btn) {
                btn.classList.remove('text-gray-600', 'border-gray-400', 'bg-gray-50');
                btn.classList.add('bg-white', 'text-gray-900', 'font-semibold', 'border-indigo-600');
            }
            renderOutfitList();
        }

        function setEditOutfitFilter(filter, btn) {
            currentEditOutfitFilter = filter;
            document.querySelectorAll('#editOutfitFilterTabs button').forEach(b => {
                b.classList.remove('bg-white', 'shadow-sm', 'text-gray-900', 'font-semibold', 'border-indigo-600');
                b.classList.add('text-gray-600', 'bg-gray-50', 'border-gray-400');
            });
            if (btn) {
                btn.classList.remove('text-gray-600', 'bg-gray-50', 'border-gray-400');
                btn.classList.add('bg-white', 'text-gray-900', 'font-semibold', 'border-indigo-600');
            }
            if (!document.getElementById('editOutfitListInner')) {
                var selectedIds = Object.keys(typeof editCart !== 'undefined' ? editCart : {}).map(function(i) { return parseInt(i); });
                renderOutfitCheckboxes('editOutfitCheckboxList', editAllOutfits, selectedIds);
                return;
            }

            renderEditOutfitList(editAllOutfits, currentEditOutfitFilter);
            renderEditCart();
            var searchVal = document.getElementById('editOutfitSearch') ? document.getElementById('editOutfitSearch').value : '';
            if (searchVal) filterOutfitCheckboxes('editOutfitCheckboxList', searchVal);
        }

        var emptyCreateOutfitListHtml = '<div class="empty-outfit-list-hint rounded-md border border-amber-100 bg-amber-50/90 m-1 px-4 py-6 text-center text-sm text-amber-950"><p class="font-semibold">Select start and end dates first</p><p class="mt-1.5 text-xs text-amber-900/85 leading-relaxed">The item list loads after both rent dates are filled in above.</p></div>';
        var emptyEditOutfitListHtml = '<div class="empty-outfit-list-hint rounded-md border border-amber-100 bg-amber-50/90 px-4 py-6 text-center text-sm text-amber-950"><p class="font-semibold">Set Step 1 dates first</p><p class="mt-1.5 text-xs text-amber-900/85 leading-relaxed">Choose start and end date above to load items for this rent.</p></div>';

        function fetchOutfits(callback) {
            const hint = document.getElementById('outfitHint');
            const search = document.getElementById('outfitSearch');
            const tabs = document.getElementById('outfitFilterTabs');
            if (!sd() || !ed()) {
                document.getElementById('outfitList').innerHTML = emptyCreateOutfitListHtml;
                search.disabled = true;
                if (tabs) tabs.classList.add('hidden');
                if (hint) {
                    hint.className = 'mb-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-950 shadow-sm';
                    hint.innerHTML = rentalsOutfitHintDatesRequiredHtml;
                }
                document.getElementById('cartSection').classList.add('hidden');
                document.getElementById('conflictInfo').classList.add('hidden');
                syncOutfitSearchClearVisible();
                syncCooldownApprovalOnlyUi();
                return;
            }
            search.disabled = false;
            if (tabs) {
                tabs.classList.remove('hidden');
                if (!tabs.dataset.initialized) {
                    tabs.dataset.initialized = '1';
                    const btn = tabs.querySelector('button[onclick*="\'available\'"]');
                    if (btn) setOutfitFilter('available', btn);
                }
            }
            if (hint) {
                hint.className = 'mb-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-950 shadow-sm';
                hint.innerHTML = rentalsOutfitHintReadyTemplateHtml.replace('__SD__', sd()).replace('__ED__', ed());
            }
            syncOutfitSearchClearVisible();
            document.getElementById('outfitList').innerHTML = rentalsOutfitListLoadingHtml;
            fetch('/outfits/available?start=' + sd() + '&end=' + ed() + (window.__rentalTutorialMode ? '&tutorial=1' : '') + '&_=' + Date.now(), { cache: 'no-store' })
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function(res) {
                    allOutfits = Array.isArray(res.outfits) ? res.outfits : [];
                    reconcileCartCooldownFlagsFromAllOutfits();
                    renderOutfitList();
                    renderCart();
                    clearCreateErrorSummary();
                    if (callback) callback();
                })
                .catch(function() {
                    allOutfits = [];
                    document.getElementById('outfitList').innerHTML = rentalsOutfitListLoadErrorHtml;
                    showCreateErrorSummary(['Failed to load item availability. Please retry.']);
                    syncOutfitSearchClearVisible();
                    syncCooldownApprovalOnlyUi();
                });
        }

        function fetchEditOutfits(sdVal, edVal, excludeId, selectedIds, opts) {
            opts = opts || {};
            var fullModalOverlay = !!opts.fullModalOverlay;
            editSelectedIds = (selectedIds || []).map(rentalNumericOutfitId).filter(function(n) { return n !== null; });
            const search = document.getElementById('editOutfitSearch');
            const tabs = document.getElementById('editOutfitFilterTabs');
            const dateHint = document.getElementById('editDateHint');
            const listHost = document.getElementById('editOutfitCheckboxList');
            if (!sdVal || !edVal) {
                if (fullModalOverlay) setEditModalLoading(false);
                if (listHost) listHost.innerHTML = emptyEditOutfitListHtml;
                if (search) search.disabled = true;
                if (tabs) tabs.classList.add('hidden');
                if (dateHint) {
                    dateHint.className = 'mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-950 shadow-sm';
                    dateHint.innerHTML = rentalsOutfitHintDatesRequiredChangeHtml;
                }
                return;
            }
            if (search) search.disabled = false;
            if (dateHint) {
                dateHint.className = 'mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-950 shadow-sm';
                dateHint.innerHTML = rentalsOutfitHintDatesSetTemplateHtml.replace('__SD__', sdVal).replace('__ED__', edVal);
            }
            if (tabs) {
                tabs.classList.remove('hidden');
            }
            if (fullModalOverlay) {
                setEditModalLoading(true);
            } else if (listHost) {
                listHost.innerHTML = editOutfitListInlineLoadingHtml;
            }
            fetch('/outfits/available?start=' + sdVal + '&end=' + edVal + (excludeId ? '&exclude=' + excludeId : '') + (window.__rentalTutorialMode ? '&tutorial=1' : '') + '&_=' + Date.now(), { cache: 'no-store' })
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function(res) {
                    editAllOutfits = Array.isArray(res.outfits) ? res.outfits : [];
                    renderOutfitCheckboxes('editOutfitCheckboxList', editAllOutfits, editSelectedIds);
                    if (tabs) {
                        var f = currentEditOutfitFilter || 'available';
                        var tabBtn = tabs.querySelector('button[onclick*="\'' + f + '\'"]');
                        if (tabBtn) setEditOutfitFilter(f, tabBtn);
                    }
                    editUpdateConflictInfo();
                    applyEditModalViewOnlyState();
                })
                .catch(function() {
                    editAllOutfits = [];
                    if (listHost) {
                        listHost.innerHTML = rentalsOutfitListLoadErrorCompactHtml;
                    }
                    editUpdateConflictInfo();
                    applyEditModalViewOnlyState();
                })
                .finally(function() {
                    if (fullModalOverlay) setEditModalLoading(false);
                    applyEditModalViewOnlyState();
                });
        }

        function ymdFromLocalDate(d) {
            return d.getFullYear()
                + '-' + String(d.getMonth() + 1).padStart(2, '0')
                + '-' + String(d.getDate()).padStart(2, '0');
        }

        function localDateFromYmd(ymd) {
            var m = String(ymd || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (!m) return null;
            return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
        }

        function debugTodayYmd() {
            var raw = __RENTAL_DEBUG_TODAY_YMD;
            if (!raw) return '';
            var s = String(raw).trim();
            return /^\d{4}-\d{2}-\d{2}$/.test(s) ? s : '';
        }

        function rentalsDebugTodayQuery() {
            var d = debugTodayYmd();
            return d ? '&debug_today=' + encodeURIComponent(d) : '';
        }

        /** Per-day overdue fine from settings: fixed Rp, or % of the edit cart's item list prices. */
        function overdueFineRatePerDay() {
            if (_overdueFineType === 'manual') {
                var manualEl = document.getElementById('editOverdueManualRate');
                var v = manualEl ? (rentalMoneyParse(String(manualEl.value || '')) || 0) : 0;
                return Math.max(0, Number(v) || 0);
            }
            if (_overdueFineType === 'percent') {
                var base = 0;
                Object.values(editCart || {}).forEach(function(item) {
                    base += rentalNumericOutfitPrice(item.price);
                });
                return Math.max(0, base * (Number(_overdueFinePercentPerDay) || 0) / 100);
            }
            return Math.max(0, Number(_overdueFinePerDay) || 0);
        }

        function todayYmdLocal() {
            var forced = debugTodayYmd();
            if (forced) return forced;
            return ymdFromLocalDate(new Date());
        }

        // Client-side date validation: end >= start
        function syncDates() {
            const sd = document.getElementById('rsd');
            const ed = document.getElementById('red');
            if (!sd || !ed) {
                return;
            }
            var today = todayYmdLocal();
            if (sd) sd.min = today;
            if (ed && !sd.value) ed.min = today;
            if (sd.value) {
                ed.min = sd.value;
                if (!ed.value || ed.value < sd.value) {
                    const next = localDateFromYmd(sd.value);
                    if (next) {
                        next.setDate(next.getDate() + 2);
                        ed.value = ymdFromLocalDate(next);
                    }
                }
            }
            fetchOutfits();
            if (typeof renderCart === 'function') {
                renderCart();
            }
            syncCooldownApprovalOnlyUi();
        }
        var rsdEl = document.getElementById('rsd');
        var redEl = document.getElementById('red');
        if (rsdEl && redEl) {
            rsdEl.addEventListener('change', syncDates);
            redEl.addEventListener('change', syncDates);
            syncDates();
        }

        // Edit modal outfit rendering — cart-style UI (per-item: code, description, stock, price, line discount)
        function editCartAssignFromOutfit(s) {
            var sid = rentalNumericOutfitId(s.id);
            var meta = sid !== null ? currentEditRentalOutfitsMeta[sid] : undefined;
            var rawPrice = s.price;
            // An item already on the rent keeps the price it was booked at (the server does the
            // same on Save/Pay), so the booked price wins over the catalog price from /outfits/available.
            if (meta && meta.price != null && meta.price !== '') {
                rawPrice = meta.price;
            }
            var item = {
                id: sid !== null ? sid : s.id,
                code: s.code || '',
                new_code: s.new_code || '',
                description: s.description || '',
                price: rentalNumericOutfitPrice(rawPrice),
                stock: s.stock != null ? Number(s.stock) : 1,
                discount: '',
                discountType: 'fixed',
                discountNote: ''
            };
            if (meta) {
                if (meta.pivot_discount != null && meta.pivot_discount !== '') {
                    item.discount = meta.pivot_discount;
                }
                item.discountType = meta.pivot_discount_type || 'fixed';
                item.discountNote = meta.pivot_discount_note || '';
            }
            return item;
        }

        function rentalEditHasServerOutfitPayload(rental) {
            return !!(rental && Array.isArray(rental.outfits) && rental.outfits.length);
        }

        function seedEditCartFromRentalOutfits(rental) {
            if (!rentalEditHasServerOutfitPayload(rental)) {
                return false;
            }
            rental.outfits.forEach(function(s) {
                var sid = rentalNumericOutfitId(s.id);
                if (sid === null) return;
                editCart[sid] = editCartAssignFromOutfit(s);
            });
            return Object.keys(editCart).length > 0;
        }

        function setEditModalLoading(show) {
            var overlay = document.getElementById('editModalLoadingOverlay');
            if (!overlay) return;
            overlay.classList.toggle('hidden', !show);
            overlay.setAttribute('aria-busy', show ? 'true' : 'false');
        }

        var editOutfitListInlineLoadingHtml = '<div class="flex flex-col items-center justify-center gap-4 py-10 px-4 text-gray-600 bg-white rounded-md border border-gray-200/90" role="status"><div class="rental-loading-track w-full max-w-xs h-1 rounded-full" aria-hidden="true"><div class="rental-loading-stripe"></div></div><p class="text-sm font-medium">Loading item availability…</p></div>';

        function updateEditCartDiscount(id, val) {
            var sid = rentalNumericOutfitId(id);
            if (sid !== null && editCart[sid]) { editCart[sid].discount = val; renderEditCart(); }
        }
        function updateEditCartDiscountType(id, val) {
            var sid = rentalNumericOutfitId(id);
            if (sid !== null && editCart[sid]) { editCart[sid].discountType = val; renderEditCart(); }
        }
        function updateEditCartDiscountNote(id, val) {
            var sid = rentalNumericOutfitId(id);
            if (sid !== null && editCart[sid]) { editCart[sid].discountNote = val; }
        }

        function calcEditEffectivePrice(item) {
            var _ebi2 = editRentalBillingInfo();
            var _ed = _ebi2.days;
            var _em = _ebi2.mode === 'block' ? Math.floor(Math.max(3, _ed) / 3) : _ed;
            return calcEffectivePrice(item, _em);
        }

        function renderOutfitCheckboxes(containerId, outfits, selectedIds) {
            selectedIds = (selectedIds || []).map(rentalNumericOutfitId).filter(function(n) { return n !== null; });
            var prevCart = Object.assign({}, editCart);
            editCart = {};
            takeoverFrom = {};
            // initialize cart with selectedIds
            selectedIds.forEach(function(id) {
                const s = outfits.find(function(x) { return outfitsSameId(x.id, id); });
                if (s) {
                    var sid = rentalNumericOutfitId(s.id);
                    if (sid === null) return;
                    editCart[sid] = editCartAssignFromOutfit(s);
                    var prev = prevCart[sid] || prevCart[id] || prevCart[String(id)];
                    if (prev) {
                        editCart[sid].discount = prev.discount;
                        editCart[sid].discountType = prev.discountType || 'fixed';
                    }
                }
            });

            const container = document.getElementById(containerId);
            const filter = containerId === 'editOutfitCheckboxList' ? currentEditOutfitFilter : 'all';
            if (!outfits.length) {
                container.innerHTML = rentalsOutfitListEmptyAvailableHtml;
                return;
            }

            // Render the left list only; cart is rendered into a separate DOM section so it can be larger and not constrained by the list container.
            // #editOutfitListPane deliberately has no height/overflow of its own — #editOutfitCheckboxList
            // (the outer container) is already the scroll boundary; nesting a second one here caused
            // two independent scrollbars stacked on top of each other.
            container.innerHTML = `
                <div class="space-y-3">
                    <div id="editOutfitListPane">
                        <div id="editOutfitListInner" class="space-y-1"></div>
                    </div>
                </div>
            `;

            renderEditOutfitList(outfits, filter);
            renderEditCart();
            syncEditOverdueReturnUi();

            var clearBtn = document.getElementById('clearEditCart');
            if (clearBtn) {
                clearBtn.onclick = function() {
                    editCart = {};
                    takeoverFrom = {};
                    renderEditCart();
                    syncEditOverdueReturnUi();
                    // Use the current active tab, not a stale captured filter value.
                    renderEditOutfitList(outfits, currentEditOutfitFilter);
                    editUpdateConflictInfo();
                    var currentSearch = document.getElementById('editOutfitSearch') ? document.getElementById('editOutfitSearch').value : '';
                    if (currentSearch) filterOutfitCheckboxes('editOutfitCheckboxList', currentSearch);
                    // scroll to top of list after clearing
                    var listPane = document.getElementById('editOutfitListPane');
                    if (listPane) listPane.scrollTop = 0;
                };
            }

            // Apply search filter if present
            var searchVal = document.getElementById('editOutfitSearch') ? document.getElementById('editOutfitSearch').value : '';
            if (searchVal) filterOutfitCheckboxes('editOutfitCheckboxList', searchVal);
        }

        function renderEditOutfitList(outfits, filter) {
            const list = document.getElementById('editOutfitListInner');
            list.innerHTML = '';
            var viewOnly = !!editModalViewOnly;
            var filtered = outfits.slice();
            if (filter !== 'all') filtered = filtered.filter(function(s) { return filter === 'available' ? outfitSelectable(s) : !s.available; });
            // Items already in the "Selected items" panel on the right showed here too
            // with no Add button and no click reaction — reads as broken/disabled
            // rather than "already added". Same filter the New Rent item list already
            // applies against its own cart.
            filtered = filtered.filter(function(s) {
                var sid = rentalNumericOutfitId(s.id);
                return sid === null || !editCart[sid];
            });
            if (!filtered.length) { list.innerHTML = rentalsOutfitListEmptyFilterHtml; return; }

            filtered.forEach(function(s) {
                const row = document.createElement('div');
                const clickable = outfitSelectable(s) || (s.rental && s.rental.id);
                row.className = 'flex items-center justify-between gap-2 px-2 py-1 rounded hover:bg-gray-50' + (clickable ? ' cursor-pointer' : '');
                const left = document.createElement('div');
                left.className = 'flex-1 min-w-0';
                const title = document.createElement('div');
                title.className = 'font-medium text-sm ' + (outfitSelectable(s) ? 'text-gray-900' : 'text-gray-500');
                title.textContent = s.code + (s.new_code ? ' | ' + s.new_code : '');
                left.appendChild(title);
                if (s.description) {
                    var sub = document.createElement('div');
                    sub.className = 'text-xs text-gray-500 truncate max-w-[14rem] sm:max-w-none';
                    sub.textContent = s.description;
                    left.appendChild(sub);
                }
                var sid = rentalNumericOutfitId(s.id);
                if (_pricingEnabled && sid !== null) {
                    var listRaw = s.price;
                    if ((listRaw == null || listRaw === '') && currentEditRentalOutfitsMeta[sid]) {
                        var mp = currentEditRentalOutfitsMeta[sid].price;
                        if (mp != null && mp !== '') listRaw = mp;
                    }
                    if (listRaw != null && listRaw !== '') {
                        var priceHint = document.createElement('div');
                        priceHint.className = 'text-xs text-gray-600 mt-0.5 tabular-nums';
                        var pTxt = 'Rp ' + rentalNumericOutfitPrice(listRaw).toLocaleString('id-ID');
                        if (s.stock !== undefined && s.stock !== null) {
                            pTxt += ' · Stock ' + s.stock;
                        }
                        priceHint.textContent = pTxt;
                        left.appendChild(priceHint);
                    }
                }
                if (!s.available && s.rental && filter === 'rented') {
                    const detail = document.createElement('div');
                    detail.className = 'text-xs text-red-600 mt-1';
                    detail.textContent = s.rental.customer + ' ' + s.rental.start + ' - ' + s.rental.end;
                    left.appendChild(detail);
                }
                row.appendChild(left);

                const right = document.createElement('div');
                if (viewOnly) {
                    row.className = 'flex items-center justify-between gap-2 px-2 py-1 rounded';
                    row.appendChild(right);
                    list.appendChild(row);
                    return;
                }
                if (sid !== null && editCart[sid]) {
                    // If we're viewing the Available filter, don't show a Remove/Add badge — cart is the single source of truth
                    if (filter === 'available') {
                        // leave right empty
                    } else {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'px-2 py-0.5 text-xs bg-red-50 text-red-700 rounded';
                        btn.textContent = 'Remove';
                        btn.addEventListener('click', function(e) { e.stopPropagation(); removeFromEditCart(sid); });
                        right.appendChild(btn);
                    }
                } else {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    if (outfitSelectable(s)) {
                        btn.className = 'px-2 py-0.5 text-xs bg-indigo-600 text-white rounded';
                        btn.textContent = s.needs_manager_approval ? 'Add (approval)' : 'Add';
                        btn.title = s.needs_manager_approval ? 'Adds item and sends this Quick edit to management for cool-down approval' : '';
                        btn.addEventListener('click', function(e) { e.stopPropagation(); addToEditCartAndSubmitIfCooldownApproval(s); });
                    } else if (s.rental && s.rental.id) {
                        btn.className = 'px-2 py-0.5 text-xs bg-amber-600 text-white rounded';
                        btn.textContent = 'Switch This Item';
                        btn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            switchFromOtherRental(s);
                        });
                    } else {
                        btn.disabled = true;
                        btn.className = 'px-2 py-0.5 text-xs bg-gray-200 text-gray-500 rounded cursor-not-allowed';
                        btn.textContent = 'Unavailable';
                        btn.title = 'Item is currently rented';
                    }
                    right.appendChild(btn);
                }

                // Make the whole row clickable: add when selectable (incl. cool-down approval case), else switch takeover
                row.addEventListener('click', function(e) {
                    // Ignore clicks on buttons/inputs/links inside the row
                    if (e.target.closest('button') || e.target.closest('input') || e.target.closest('a')) return;
                    if (outfitSelectable(s)) {
                        addToEditCartAndSubmitIfCooldownApproval(s);
                    } else if (s.rental && s.rental.id) {
                        switchFromOtherRental(s);
                    }
                });

                row.appendChild(right);
                list.appendChild(row);
            });
            applyEditModalViewOnlyState();
        }

        function renderEditCart() {
            const cartEl = document.getElementById('editOutfitCart');
            const section = document.getElementById('editCartSection');
            const countEl = document.getElementById('editCartCount');
            const emptyEl = document.getElementById('editCartEmpty');
            cartEl.innerHTML = '';
            const ids = Object.keys(editCart);
            var editBillingInfo = editRentalBillingInfo();
            var days = editBillingInfo.days;
            var _editMult = editBillingInfo.mode === 'block' ? Math.floor(Math.max(3, days) / 3) : days;
            if (!ids.length) {
                if (countEl) countEl.textContent = '0';
                if (emptyEl) emptyEl.classList.remove('hidden');
                if (section) section.classList.add('hidden');
                updateEditPricingDisplays();
                // Remove any previous hidden inputs
                document.querySelectorAll('#editForm input.edit-outfit-hidden').forEach(function(el) { el.remove(); });
                editUpdateConflictInfo();
                applyEditModalViewOnlyState();
                return;
            }
            if (countEl) countEl.textContent = ids.length;
            if (emptyEl) emptyEl.classList.add('hidden');
            if (section) section.classList.remove('hidden');

            ids.forEach(function(id) {
                const item = editCart[id];
                const row = document.createElement('div');
                row.className = 'flex flex-col gap-2 px-3 py-2 bg-gradient-to-r from-gray-50 to-white rounded-xl border border-gray-200 shadow-sm';
                const switchedFrom = takeoverFrom[id] ? ('<div class="text-xs text-amber-700">Switched from rent #' + takeoverFrom[id] + '</div>') : '';
                var descLine = item.description ? '<div class="text-xs text-gray-600 mt-0.5">' + escRentalHtml(item.description) + '</div>' : '';
                var stockLine = '<div class="text-xs text-gray-500 mt-0.5">Stock: ' + (item.stock != null ? item.stock : 0) + '</div>';
                var priceLine = '';
                var discHtml = '';
                if (_pricingEnabled) {
                    var unitPrice = rentalNumericOutfitPrice(item.price);
                    var lineGross = unitPrice * _editMult;
                    var effective = calcEditEffectivePrice(item);
                    var grossLabel = rentalItemPriceLabel(unitPrice, editBillingInfo);
                    if (effective < lineGross - 1e-9 && (Number(item.discount) || 0) > 0) {
                        priceLine = '<div class="mt-1"><span class="text-xs text-gray-400 line-through">' + grossLabel + '</span> <span class="text-sm text-green-700 font-medium">Rp ' + Math.max(0, effective).toLocaleString('id-ID') + '</span></div>';
                    } else {
                        priceLine = '<div class="mt-1 text-sm text-gray-700">' + grossLabel + '</div>';
                    }
                    var _discLocked = !_viewerDiscountLockExempt && (editModalPaymentFieldsLocked() || (!_adminTransactionEditMode && currentEditRentalStoredPaymentKind === 'down_payment'));
                    var _discNumE = rentalMoneyParse(String(item.discount == null ? '' : item.discount));
                    var dVal = (Number.isFinite(_discNumE) && _discNumE > 0) ? rentalMoneyFormatNum(_discNumE).replace(/"/g, '&quot;') : '';
                    var dNoteEsc = String(item.discountNote || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    var _dDis = _discLocked ? ' disabled' : '';
                    // Highlight per-item discount fields the user has changed from the saved rental,
                    // same visual pattern as the top-level Step 3 fields (snapshotEditFieldsForHighlight).
                    var _origMeta = currentEditRentalOutfitsMeta[id] || {};
                    var _origDiscNum = rentalMoneyParse(String(_origMeta.pivot_discount == null ? '' : _origMeta.pivot_discount));
                    var _origDiscNumSafe = Number.isFinite(_origDiscNum) ? _origDiscNum : 0;
                    var _curDiscNumSafe = Number.isFinite(_discNumE) ? _discNumE : 0;
                    var _origDiscType = _origMeta.pivot_discount_type || 'fixed';
                    var _origDiscNote = String(_origMeta.pivot_discount_note || '');
                    var _discAmtChangedCls = (_origDiscNumSafe !== _curDiscNumSafe) ? ' field-changed' : '';
                    var _discTypeChangedCls = (_origDiscType !== item.discountType) ? ' field-changed' : '';
                    var _discNoteChangedCls = (_origDiscNote !== String(item.discountNote || '')) ? ' field-changed' : '';
                    discHtml = '<div class="flex items-center gap-1 flex-wrap mt-1"><input type="text" value="' + dVal + '" data-outfit-id="' + item.id + '" oninput="rentalMoneyLiveEditCartDiscount(this,' + item.id + ')" onblur="rentalMoneyBlurEditCartDiscount(this,' + item.id + ')" onchange="updateEditCartDiscount(' + item.id + ', this.value)" class="form-input-compact w-[5.5rem] text-center' + _discAmtChangedCls + '" placeholder="0"' + _dDis + '><select data-outfit-id="' + item.id + '" onchange="updateEditCartDiscountType(' + item.id + ', this.value)" class="form-input-compact min-w-[4.5rem] cursor-pointer bg-white' + _discTypeChangedCls + '"' + _dDis + '><option value="fixed" ' + (item.discountType === 'fixed' ? 'selected' : '') + '>Rp</option><option value="percent" ' + (item.discountType === 'percent' ? 'selected' : '') + '>%</option></select></div>';
                    var _origDiscNoteEsc = _origDiscNote.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                    discHtml += '<textarea rows="1" data-outfit-id="' + item.id + '" data-orig-note="' + _origDiscNoteEsc + '" oninput="updateEditCartDiscountNote(' + item.id + ', this.value);this.classList.toggle(\'field-changed\', this.value !== this.dataset.origNote);this.style.height=\'auto\';this.style.height=this.scrollHeight+\'px\'" class="form-input w-full text-xs mt-1 resize-none' + _discNoteChangedCls + '" placeholder="Discount reason" style="min-height:1.75rem"' + _dDis + '>' + dNoteEsc + '</textarea>';
                }
                var top = document.createElement('div');
                top.className = 'flex items-start justify-between gap-2';
                top.innerHTML = '<div class="flex-1 min-w-0"><div class="font-medium text-sm text-gray-900">' + item.code + (item.new_code ? ' | ' + item.new_code : '') + '</div>' + descLine + stockLine + priceLine + switchedFrom + (_pricingEnabled ? discHtml.split('<textarea')[0] : '') + '</div>';
                var btnWrap = document.createElement('div');
                if (!editModalViewOnly) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'px-2 py-0.5 text-xs bg-red-50 text-red-700 rounded shrink-0';
                    btn.textContent = 'Remove';
                    btn.addEventListener('click', function() { removeFromEditCart(item.id); });
                    btnWrap.appendChild(btn);
                }
                top.appendChild(btnWrap);
                row.appendChild(top);
                if (_pricingEnabled && discHtml.indexOf('<textarea') !== -1) {
                    var noteWrap = document.createElement('div');
                    noteWrap.innerHTML = '<textarea' + discHtml.split('<textarea')[1];
                    row.appendChild(noteWrap);
                }
                cartEl.appendChild(row);
            });

            updateEditPricingDisplays();

            // Ensure hidden inputs are present in the form for submission
            document.querySelectorAll('#editForm input.edit-outfit-hidden').forEach(function(el) { el.remove(); });
            ids.forEach(function(id) {
                var h = document.createElement('input');
                h.type = 'hidden';
                h.name = 'outfit_ids[]';
                h.value = id;
                h.className = 'edit-outfit-hidden';
                document.getElementById('editForm').appendChild(h);
            });

            editUpdateConflictInfo();
            applyEditModalViewOnlyState();
            applyPendingRequestFieldHighlights();
        }

        function addToEditCart(id) {
            var sid = rentalNumericOutfitId(id);
            if (sid === null) return;
            var s = editAllOutfits.find(function(x) { return outfitsSameId(x.id, sid); });
            if (s) editCart[sid] = editCartAssignFromOutfit(s);
            renderEditCart();
            renderEditOutfitList(editAllOutfits, currentEditOutfitFilter);
        }

        function addToEditCartAndSubmitIfCooldownApproval(s) {
            // Checked again here (not just at modal-open time) because the shift can
            // close/expire while the modal is already open — without this, clicking
            // an item silently did nothing at all, no message, reading as a dead or
            // disabled control. The onboarding tour's practice rental is exempt from
            // the shift requirement entirely (see rejectUnlessOpenShift), so this
            // guard doesn't apply while the tour is driving the edit flow either.
            if (rentalMutationsLocked && !window.__rentalTutorialMode) {
                showRentalsListToast('Open a shift before editing rents.', 'warning');
                return;
            }
            var sid = rentalNumericOutfitId(s.id);
            if (sid === null) return;
            if (s.needs_manager_approval && editCooldownPendingActiveForCurrentRental()) {
                showRentalsListToast('A cool-down approval is already pending for this rent.', 'warning');
                return;
            }
            addToEditCart(sid);
            if (s.needs_manager_approval) {
                submitEditCooldownApprovalRequest();
            }
        }
        function removeFromEditCart(id) {
            var sid = rentalNumericOutfitId(id);
            if (sid === null) return;
            delete editCart[sid];
            delete takeoverFrom[sid];
            renderEditCart();
            renderEditOutfitList(editAllOutfits, currentEditOutfitFilter);
        }

        function switchFromOtherRental(outfit) {
            if (!outfit || !outfit.rental || !outfit.rental.id) {
                window.rentalAlert('Could not switch this item. Source rental data is missing.', { icon: 'error', title: 'Switch failed' });
                return;
            }

            const label = outfit.code + (outfit.new_code ? (' | ' + outfit.new_code) : '');
            ensureSwal(function() {
                Swal.fire({
                    title: 'Switch from other rent?',
                    html: `<div class="text-sm text-gray-700">Take <b>${label}</b> from <b>${outfit.rental.customer}</b> (${outfit.rental.start} - ${outfit.rental.end}) and add it to this rent?</div>`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, switch item',
                    cancelButtonText: 'Cancel'
                }).then(function(result) {
                    if (!result.isConfirmed) return;
                    var swSid = rentalNumericOutfitId(outfit.id);
                    if (swSid === null) return;
                    takeoverFrom[swSid] = outfit.rental.id;
                    editCart[swSid] = editCartAssignFromOutfit(outfit);
                    renderEditCart();
                    renderEditOutfitList(editAllOutfits, currentEditOutfitFilter);
                    window.rentalAlert('Item switched into this rent. Save changes to apply.', { icon: 'success', title: 'Switched' });
                });
            });
        }

        function submitEditCooldownApprovalRequest() {
            if (rentalMutationsLocked && !window.__rentalTutorialMode) return;
            if (editCooldownApprovalSending) return;
            clearEditErrorSummary();
            if (currentRentalId && getEditCooldownPendingApprovalIdForRental(currentRentalId)) {
                showEditErrorSummary(['A cool-down approval is already pending for this rent. Wait for management.']);
                return;
            }
            var btnRef = document.getElementById('btnCooldownApprovalOnly');
            var url = btnRef ? btnRef.getAttribute('data-action-url') : null;
            if (!url) {
                showEditErrorSummary(['Approval is not available. Refresh the page and try again.']);
                return;
            }
            if (!currentRentalId) {
                showEditErrorSummary(['Open a rent in Quick edit first.']);
                return;
            }
            var sdEl = document.getElementById('editSd');
            var edEl = document.getElementById('editEd');
            if (!sdEl || !edEl || !sdEl.value || !edEl.value) {
                showEditErrorSummary(['Set start and end dates in Step 1 first.']);
                return;
            }
            var ids = Object.keys(editCart);
            if (!ids.length) {
                showEditErrorSummary(['Select at least one item for this rent.']);
                return;
            }
            var fd = new FormData();
            fd.append('_token', getRentalsCsrfToken());
            fd.append('rental_id', String(currentRentalId));
            fd.append('start_date', sdEl.value);
            fd.append('end_date', edEl.value);
            fd.append('customer_name', document.getElementById('editCust').value);
            fd.append('phone', document.getElementById('editPhone').value);
            fd.append('instagram', document.getElementById('editIg').value);
            fd.append('notes', document.getElementById('editNotes').value);
            if (_pricingEnabled) {
                var pm = document.getElementById('editPaymentMethod');
                fd.append('payment_method', pm ? pm.value : '');
                var pkStored = (editPaymentLockSnapshot && editPaymentLockSnapshot.kind)
                    ? editPaymentLockSnapshot.kind
                    : (currentEditRentalStoredPaymentKind || 'full');
                var ekfpEl = document.getElementById('editPaymentKindFinalPayment');
                var pe = document.getElementById('editPaymentEntry');
                var payEntry = pe && pe.value === 'increment' ? 'increment' : 'replace';
                // With a balance left, "Amount to pay" only SHOWS the remaining balance
                // (read-only); money is taken with the Pay button. Saving an edit must
                // never record it as paid — that settled DP rents whose items were
                // merely changed (seen on two real rentals).
                var pkSelected = payEntry === 'increment'
                    ? (pkStored === 'down_payment' ? 'down_payment' : pkStored)
                    : ((ekfpEl && ekfpEl.checked) ? 'final_payment' : pkStored === 'down_payment' ? 'down_payment' : 'full');
                fd.append('payment_kind', pkSelected);
                fd.append('payment_entry', payEntry);
                var eap = document.getElementById('editAmountPaid');
                if (payEntry === 'increment') {
                    fd.append('payment_amount_add', '0');
                } else {
                    fd.append('amount_paid', eap ? eap.value : '');
                }
                var ed = document.getElementById('editDiscount');
                var edt = document.getElementById('editDiscountType');
                fd.append('discount', ed ? ed.value : '');
                fd.append('discount_type', edt ? edt.value : 'fixed');
                fd.append('extra_charge', String(editExtraChargeNum()));
                var ednEl = document.getElementById('editDiscountNote');
                if (ednEl) fd.append('discount_note', ednEl.value || '');
                patchFormDataForEditPaymentLock(fd);
            }
            ids.forEach(function(k) {
                var item = editCart[k];
                fd.append('outfit_ids[]', item.id);
                if (_pricingEnabled) {
                    fd.append('item_discounts[' + item.id + ']', item.discount != null && item.discount !== '' ? item.discount : '');
                    fd.append('item_discount_types[' + item.id + ']', item.discountType || 'fixed');
                    fd.append('item_discount_notes[' + item.id + ']', item.discountNote || '');
                }
            });
            ids.forEach(function(k) {
                var sid = parseInt(k, 10);
                if (Number.isNaN(sid)) return;
                var s = editAllOutfits.find(function(x) { return outfitsSameId(x.id, sid); });
                if (s && s.needs_manager_approval) {
                    fd.append('approval_focus_outfit_ids[]', String(sid));
                }
            });
            Object.keys(takeoverFrom).forEach(function(k) {
                fd.append('takeover_from[' + k + ']', takeoverFrom[k]);
            });
            editCooldownApprovalSending = true;
            fetch(url, {
                method: 'POST',
                body: fd,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getRentalsCsrfToken()
                },
                credentials: 'same-origin'
            }).then(function(r) {
                return r.text().then(function(text) {
                    var data = {};
                    try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { message: text ? String(text).slice(0, 240) : 'Invalid response' }; }
                    return { r: r, data: data };
                });
            }).then(function(res) {
                if (res.r.ok && res.data && res.data.ok) {
                    if (res.data.mode === 'approval_request_sent') {
                        if (typeof appendMyPendingApprovalRow === 'function' && res.data.pending_summary) {
                            appendMyPendingApprovalRow(res.data.pending_summary);
                        }
                        var ps = res.data.pending_summary || {};
                        if (ps.approval_id != null && currentRentalId) {
                            editCooldownApprovalTracked = { approvalId: Number(ps.approval_id), rentalId: Number(currentRentalId) };
                        }
                        syncEditCooldownPendingUi();
                        editUpdateConflictInfo();
                        showRentalsListToast('Queued for management. Quick edit stays open; the list still shows the saved rent until approved.', 'warning');
                        if (typeof loadRentals === 'function') loadRentals(1);
                        if (typeof rentalTryStartPendingApprovalPoll === 'function') rentalTryStartPendingApprovalPoll();
                        return;
                    }
                    if (res.data.mode === 'approval_duplicate') {
                        syncEditCooldownTrackedFromListForRental(currentRentalId);
                        syncEditCooldownPendingUi();
                        editUpdateConflictInfo();
                        showRentalsListToast('You already have a pending approval for these dates and items.', 'warning');
                        return;
                    }
                }
                var msgs = [];
                if (res.data && res.data.errors) {
                    Object.keys(res.data.errors).forEach(function(k) {
                        var v = res.data.errors[k];
                        if (Array.isArray(v)) msgs = msgs.concat(v);
                        else msgs.push(String(v));
                    });
                }
                if (res.data && res.data.message) msgs.push(res.data.message);
                if (msgs.length) showEditErrorSummary(msgs);
                else showEditErrorSummary(['Could not send approval request.']);
            }).catch(function() {
                showEditErrorSummary(['Network error. Try again.']);
            }).finally(function() {
                editCooldownApprovalSending = false;
            });
        }

        function editUpdateConflictInfo() {
            var info = document.getElementById('editConflictInfo');
            if (!info) return;
            if (editCooldownPendingActiveForCurrentRental()) {
                info.innerHTML = '';
                info.classList.add('hidden');
                return;
            }
            var approvalLines = [];
            var hardLines = [];
            Object.keys(editCart).forEach(function(id) {
                var outfit = editAllOutfits.find(function(s) { return s.id === parseInt(id); });
                if (outfit && outfit.rental) {
                    var line = outfit.rental.customer + ' ' + outfit.rental.start + ' - ' + outfit.rental.end;
                    if (outfit.needs_manager_approval) approvalLines.push(line);
                    else hardLines.push(line);
                }
            });
            if (approvalLines.length || hardLines.length) {
                var html = '';
                if (approvalLines.length) {
                    html += '<div class="p-2 bg-amber-50 border border-amber-200 rounded text-amber-950 text-sm mt-2"><span class="font-medium">Return cool-down on selected items.</span> Use <strong>Add (approval)</strong> on a cool-down row to queue this Quick edit for management—no separate send step.<br><span class="text-xs text-amber-900/90 mt-1 block">' + approvalLines.join('<br>') + '</span></div>';
                }
                if (hardLines.length) {
                    html += '<div class="p-2 bg-red-50 border border-red-300 rounded text-red-700 mt-2">⚠ ' + hardLines.join('<br>') + '</div>';
                }
                info.innerHTML = html;
                info.classList.remove('hidden');
            } else {
                info.classList.add('hidden');
            }
        }

        function filterOutfitCheckboxes(containerId, query) {
            var q = query.toLowerCase();
            var anyVisible = false;
            // filter editOutfitListInner rows
            document.querySelectorAll('#editOutfitListInner > div').forEach(function(row) {
                var match = row.textContent.toLowerCase().includes(q);
                row.style.display = match ? '' : 'none';
                if (match) anyVisible = true;
            });
            var existing = document.getElementById('editQuickAddRow');
            if (existing) existing.remove();
            if (q && !anyVisible) {
                var list = document.getElementById('editOutfitListInner');
                var row = document.createElement('div');
                row.id = 'editQuickAddRow';
                var exists = editAllOutfits.some(function(s) { return s.code.toLowerCase().includes(q); });
                if (exists) {
                    row.className = 'px-2 py-1.5 text-xs text-gray-400';
                    row.textContent = 'Not available';
                } else {
                    row.className = 'flex items-center gap-2 px-2 py-1.5 bg-gray-50';
                    var eq = String(q).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    row.innerHTML = '<div class="flex flex-col gap-1 w-full min-w-0">'
                        + '<p class="text-xs text-gray-500">Shortcut: <kbd class="px-0.5 rounded border border-gray-200 bg-white font-mono">Enter</kbd> in any field to add</p>'
                        + '<div class="flex flex-wrap items-center gap-1.5">'
                        + '<input type="text" id="editQuickAddInput" value="' + eq.toUpperCase() + '" class="form-input-compact flex-1 min-w-[6rem]" placeholder="Code" autocomplete="off" onkeydown="if(event.key===\'Enter\'){event.preventDefault();quickAddEditOutfit();}">'
                        + '<input type="text" id="editQuickAddNewCodeInput" class="form-input-compact flex-1 min-w-[6rem]" placeholder="New code (opt.)" autocomplete="off" onkeydown="if(event.key===\'Enter\'){event.preventDefault();quickAddEditOutfit();}">'
                        + '<button type="button" onclick="quickAddEditOutfit()" class="px-2.5 py-1 bg-indigo-500 text-white rounded text-xs font-medium hover:bg-indigo-600 transition cursor-pointer whitespace-nowrap">+ Add</button>'
                        + '</div>'
                        + '<input type="text" id="editQuickAddDescInput" maxlength="255" class="form-input-compact w-full" placeholder="Description (optional)" autocomplete="off" onkeydown="if(event.key===\'Enter\'){event.preventDefault();quickAddEditOutfit();}">'
                        + '</div>';
                }
                list.appendChild(row);
            }
        }

        function quickAddEditOutfit() {
            if (!mayMutateItems) {
                window.rentalAlert('Your account cannot create items.', { icon: 'warning', title: 'Not allowed' });
                return;
            }
            var codeEl = document.getElementById('editQuickAddInput');
            var newEl = document.getElementById('editQuickAddNewCodeInput');
            var descEl = document.getElementById('editQuickAddDescInput');
            var code = codeEl ? codeEl.value.trim() : '';
            var newCode = newEl ? newEl.value.trim() : '';
            var description = descEl ? descEl.value.trim() : '';
            if (!code) return;
            var payload = { code: code };
            if (newCode) payload.new_code = newCode;
            if (description) payload.description = description;
            var token = rentalsCsrfForJson();
            if (!token) {
                window.rentalAlert('Missing security token. Refresh the page and try again.', { icon: 'error', title: 'Session issue' });
                return;
            }
            fetch('/outfits/quick-add', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            }).then(function(r) {
                return r.text().then(function(text) {
                    var data = {};
                    try {
                        data = text ? JSON.parse(text) : {};
                    } catch (e) {
                        data = {};
                    }
                    return { r: r, data: data };
                });
            }).then(function(out) {
                var res = out.data;
                if (!out.r.ok) {
                    var msg = res.message || ('Request failed (' + out.r.status + ')');
                    if (res.errors && typeof res.errors === 'object') {
                        var flat = [];
                        Object.keys(res.errors).forEach(function(k) {
                            var v = res.errors[k];
                            if (Array.isArray(v)) flat = flat.concat(v);
                            else flat.push(String(v));
                        });
                        if (flat.length) msg = flat.join(' ');
                    }
                    window.rentalAlert(msg, { icon: 'error', title: 'Request failed' });
                    return;
                }
                if (res.outfit) {
                    document.getElementById('editOutfitSearch').value = '';
                    editAllOutfits.push(res.outfit);
                    editCart[Number(res.outfit.id)] = editCartAssignFromOutfit(res.outfit);
                    renderEditCart();
                    renderEditOutfitList(editAllOutfits, currentEditOutfitFilter);
                }
            }).catch(function() { window.rentalAlert('Failed to add item.', { icon: 'error', title: 'Add item failed' }); });
        }

        (function wireQuickAddCreateInput() {
            function onEnter(e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                quickAddOutfit();
            }
            var codeIn = document.getElementById('quickAddCreateInput');
            var newIn = document.getElementById('quickAddCreateNewCode');
            var descIn = document.getElementById('quickAddCreateDescription');
            if (codeIn) codeIn.addEventListener('keydown', onEnter);
            if (newIn) newIn.addEventListener('keydown', onEnter);
            if (descIn) descIn.addEventListener('keydown', onEnter);
        })();

        // editUpdateConflictInfo is called from renderEditCart whenever cart changes
        // document.getElementById('editOutfitCheckboxList').addEventListener('change', editUpdateConflictInfo);


        function syncEditDates() {
            const sd = document.getElementById('editSd');
            const ed = document.getElementById('editEd');
            var today = typeof todayYmdLocal === 'function' ? todayYmdLocal() : null;
            var baseEl = document.getElementById('editOverdueBaseEndDate');
            var thruEl = document.getElementById('editOverdueChargeThroughDate');
            var feeRateEl = document.getElementById('editOverdueFeeRate');
            var feeDaysEl = document.getElementById('editOverdueFeeDays');
            var feeAmtEl = document.getElementById('editOverdueFeeAmount');
            if (baseEl) baseEl.value = '';
            if (thruEl) thruEl.value = '';
            if (feeRateEl) feeRateEl.value = '';
            if (feeDaysEl) feeDaysEl.value = '';
            if (feeAmtEl) feeAmtEl.value = '';
            editOverdueMergedOnce = false;
            editOriginalEndDateYmd = ed ? String(ed.value || '') : null;
            if (currentRentalId) {
                saveOverdueMergeRecord(currentRentalId, '', '');
            }
            if (today && !_adminTransactionEditMode) {
                if (sd && !sd.value) sd.min = today;
                if (ed && !sd.value) ed.min = today;
            }
            if (sd.value) {
                if (!_adminTransactionEditMode) ed.min = sd.value;
                if (!ed.value || ed.value < sd.value) {
                    const next = typeof localDateFromYmd === 'function' ? localDateFromYmd(sd.value) : null;
                    if (next && typeof ymdFromLocalDate === 'function') {
                        next.setDate(next.getDate() + 2);
                        ed.value = ymdFromLocalDate(next);
                    }
                }
            }
            editOriginalEndDateYmd = ed ? String(ed.value || '') : null;
            // Collect currently-selected outfit ids from editCart before re-fetching available outfits
            var selectedIds = [];
            if (typeof editCart !== 'undefined') selectedIds = Object.keys(editCart).map(function(i){ return parseInt(i); });
            fetchEditOutfits(sd.value, ed.value, currentRentalId, selectedIds);
            if (typeof renderEditCart === 'function') {
                renderEditCart();
            }
            syncEditOverdueReturnUi();
        }
        document.getElementById('editSd').addEventListener('change', syncEditDates);
        document.getElementById('editEd').addEventListener('change', syncEditDates);
        bindRentalMoneyThousandsInput(document.getElementById('editOverdueManualRate'), function() {
            if (typeof syncEditOverdueReturnUi === 'function') syncEditOverdueReturnUi();
        });
        var editApplyOverdueBtn = document.getElementById('editApplyOverdueChargeBtn');
        if (editApplyOverdueBtn) editApplyOverdueBtn.addEventListener('click', function() {
            if (typeof todayYmdLocal !== 'function') {
                return;
            }
            var baseEl = document.getElementById('editOverdueBaseEndDate');
            var thruEl = document.getElementById('editOverdueChargeThroughDate');
            var feeRateEl = document.getElementById('editOverdueFeeRate');
            var feeDaysEl = document.getElementById('editOverdueFeeDays');
            var feeAmtEl = document.getElementById('editOverdueFeeAmount');
            var edEl = document.getElementById('editEd');
            var dueEnd = String(editOriginalEndDateYmd || (edEl ? edEl.value : '') || '');
            var today = String(todayYmdLocal() || '');
            var msDue = rentalYmdUtcMs(dueEnd);
            var msToday = rentalYmdUtcMs(today);
            var rate = overdueFineRatePerDay();
            if (_overdueFineType === 'manual' && !(rate > 0)) {
                var manualEl = document.getElementById('editOverdueManualRate');
                if (manualEl) { manualEl.focus(); manualEl.classList.add('border-red-400'); }
                return;
            }
            if (!Number.isFinite(msDue) || !Number.isFinite(msToday)) {
                return;
            }
            if (msToday <= msDue) {
                syncEditOverdueReturnUi();
                return;
            }

            var fee = editOverdueFeeAmountNum();
            var paid = Number(editOverduePaymentBasePaid) || 0;
            var alreadyCharged = !!editOverdueMergedOnce || fee > 0.004 || paid > 0.004;
            if (alreadyCharged) {
                var lastThru = thruEl ? String(thruEl.value || '').trim() : '';
                var msLastThru = lastThru ? rentalYmdUtcMs(lastThru) : NaN;
                var msSince = Number.isFinite(msLastThru) ? msLastThru : msDue;
                var additionalDays = msToday > msSince ? Math.max(1, Math.floor((msToday - msSince) / 86400000)) : 0;
                if (!(additionalDays > 0)) {
                    syncEditOverdueReturnUi();
                    syncEditOverduePaymentUi();
                    return;
                }

                var addAmount = Math.max(0, rate * additionalDays);
                var nextFee = Math.max(0, fee + addAmount);
                var existingDays = feeDaysEl ? (parseInt(String(feeDaysEl.value || '0'), 10) || 0) : 0;
                var nextDays = Math.max(1, existingDays + additionalDays);

                if (baseEl && !String(baseEl.value || '').trim()) {
                    baseEl.value = dueEnd;
                }
                if (thruEl) {
                    thruEl.value = today;
                }
                if (currentRentalId) {
                    saveOverdueMergeRecord(currentRentalId, baseEl ? String(baseEl.value || '').trim() : dueEnd, today);
                }
                if (feeRateEl) feeRateEl.value = String(rate);
                if (feeDaysEl) feeDaysEl.value = String(nextDays);
                if (feeAmtEl) feeAmtEl.value = String(nextFee);
                editOverdueMergedOnce = true;
                editOverdueDismissed = false;
                updateEditPricingDisplays();
                syncEditOverdueReturnUi();
                syncEditOverduePaymentUi();
                return;
            }

            var daysLate = Math.max(1, Math.floor((msToday - msDue) / 86400000));
            var amount = Math.max(0, rate * daysLate);
            if (baseEl) {
                baseEl.value = dueEnd;
            }
            if (thruEl) {
                thruEl.value = today;
            }
            if (currentRentalId) {
                saveOverdueMergeRecord(currentRentalId, dueEnd, today);
            }
            if (feeRateEl) feeRateEl.value = String(rate);
            if (feeDaysEl) feeDaysEl.value = String(daysLate);
            if (feeAmtEl) feeAmtEl.value = String(amount);
            editOverdueMergedOnce = true;
            editOverdueDismissed = false;
            updateEditPricingDisplays();
            syncEditOverdueReturnUi();
            syncEditOverduePaymentUi();
        });
        var editDismissOverdueBtn = document.getElementById('editDismissOverdueBtn');
        if (editDismissOverdueBtn) editDismissOverdueBtn.addEventListener('click', function() {
            editOverdueDismissed = true;
            syncEditOverdueReturnUi();
        });

        // Invoice generation + WhatsApp helpers
        function rentalsInvoiceCsrfToken() {
            var el = document.querySelector('#editForm input[name="_token"], #rentalForm input[name="_token"], input[name="_token"]');
            return el ? el.value : '';
        }
        function parseInvoiceGenerateJson(text) {
            var t = String(text || '').trim();
            if (!t) return {};
            try {
                return JSON.parse(t);
            } catch (e) {
                return { _notJson: true };
            }
        }
        function ensureInvoicePrepared() {
            if (!currentRentalId) return Promise.reject(new Error('Open a rent first.'));
            var token = rentalsInvoiceCsrfToken();
            if (!token) return Promise.reject(new Error('Missing security token. Refresh the page and try again.'));
            var invoiceReqTimeoutMs = __rentalsInvoiceTimeoutMs;
            var ctrl = new AbortController();
            var tid = setTimeout(function() { ctrl.abort(); }, invoiceReqTimeoutMs);
            return fetch('/rentals/' + currentRentalId + '/invoice/generate' + rentalTutorialQs(), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                signal: ctrl.signal
            }).then(function(r) {
                return r.text().then(function(text) {
                    var data = parseInvoiceGenerateJson(text);
                    return { ok: r.ok, status: r.status, data: data, rawLen: text ? text.length : 0 };
                });
            }).then(function(res) {
                if (res.data && res.data._notJson) {
                    throw new Error('Server did not return JSON (status ' + res.status + '). You may need to log in again — refresh the page.');
                }
                if (!res.ok) {
                    throw new Error((res.data && (res.data.error || res.data.message)) ? (res.data.error || res.data.message) : ('Failed to generate receipt (' + res.status + ').'));
                }
                var genBtn = document.getElementById('generateInvoiceBtn');
                if (genBtn && res.data && res.data.signed_url) {
                    genBtn.dataset.signedUrl = res.data.signed_url;
                    if (res.data.signed_png_url) genBtn.dataset.signedPngUrl = res.data.signed_png_url;
                }
                var openBtn = document.getElementById('openInvoicePageBtn');
                if (openBtn && res.data && res.data.signed_view_url) {
                    openBtn.dataset.signedViewUrl = res.data.signed_view_url;
                }
                return res.data;
            }).catch(function(err) {
                if (err && err.name === 'AbortError') {
                    throw new Error('Receipt preparation timed out (image step can take a while). Try again or check server logs.');
                }
                throw err;
            }).finally(function() {
                clearTimeout(tid);
            });
        }

        function generateInvoice() {
            // Kept for backward compatibility if called from stale cached JS.
            return ensureInvoicePrepared();
        }

        function rentalOpenNewTab(url) {
            if (!url) return false;
            try {
                var a = document.createElement('a');
                a.href = url;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.referrerPolicy = 'no-referrer';
                document.body.appendChild(a);
                a.click();
                a.remove();
                return true;
            } catch (e) {}
            try {
                return !!window.open(url, '_blank', 'noopener,noreferrer');
            } catch (e) {}
            return false;
        }

        function rentalRememberEditModalStateForReceipt() {
            try {
                if (!currentRentalId) return;
                var modal = document.getElementById('editModal');
                var isOpen = !!(modal && !modal.classList.contains('hidden'));
                if (!isOpen) return;
                var payload = {
                    t: Date.now(),
                    rental_id: Number(currentRentalId),
                    scroll_y: window.scrollY || 0,
                };
                sessionStorage.setItem('__RENTAL_RECEIPT_RETURN', JSON.stringify(payload));
            } catch (e) {}
        }

        function openInvoicePage() {
            if (!currentRentalId) { window.rentalAlert('Open a rent first.', { icon: 'warning', title: 'No active rent' }); return; }
            var btn = document.getElementById('openInvoicePageBtn');
            var token = rentalsInvoiceCsrfToken();
            if (!token) {
                window.rentalAlert('Missing security token. Refresh the page and try again.', { icon: 'error', title: 'Session issue' });
                return;
            }
            var invoiceReqTimeoutMs = __rentalsInvoiceTimeoutMs;
            var labelDone = 'Open Secure Receipt Page';
            btn.disabled = true;
            btn.textContent = 'Preparing...';
            var ctrl = new AbortController();
            var tid = setTimeout(function() { ctrl.abort(); }, invoiceReqTimeoutMs);
            ensureInvoicePrepared()
                .then(function(res) {
                    if (res && res.signed_view_url) {
                        rentalRememberEditModalStateForReceipt();
                        btn.dataset.signedViewUrl = res.signed_view_url;
                        window.location.assign(res.signed_view_url);
                        return;
                    }
                    window.rentalAlert((res && res.error) ? res.error : 'Failed to prepare receipt page.', { icon: 'error', title: 'Receipt failed' });
                })
                .catch(function(err) {
                    if (err && err.name === 'AbortError') {
                        window.rentalAlert('Receipt preparation timed out. The image step can take minutes — try again or check server logs.', { icon: 'warning', title: 'Timeout' });
                    } else {
                        window.rentalAlert((err && err.message) ? err.message : 'Failed to prepare receipt page.', { icon: 'error', title: 'Receipt failed' });
                    }
                })
                .finally(function() {
                    clearTimeout(tid);
                    btn.disabled = false;
                    btn.textContent = labelDone;
                });
        }

        function openInvoiceImage() {
            if (!currentRentalId) { window.rentalAlert('Open a rent first.', { icon: 'warning', title: 'No active rent' }); return; }
            var btn = document.getElementById('openInvoiceImageBtn');
            var token = rentalsInvoiceCsrfToken();
            if (!token) {
                window.rentalAlert('Missing security token. Refresh the page and try again.', { icon: 'error', title: 'Session issue' });
                return;
            }
            var labelDone = 'Open Receipt Image';
            btn.disabled = true;
            btn.textContent = 'Preparing...';
            ensureInvoicePrepared()
                .then(function(res) {
                    if (res && res.signed_png_url) {
                        rentalOpenNewTab(res.signed_png_url);
                        return;
                    }
                    window.rentalAlert((res && res.error) ? res.error : 'Receipt image is not ready yet. Try again.', { icon: 'warning', title: 'Receipt image missing' });
                })
                .catch(function(err) {
                    window.rentalAlert((err && err.message) ? err.message : 'Failed to prepare receipt image.', { icon: 'error', title: 'Receipt failed' });
                })
                .finally(function() {
                    btn.disabled = false;
                    btn.textContent = labelDone;
                });
        }

        function sendInvoiceWhatsapp() {
            if (!currentRentalId) { window.rentalAlert('Open a rent first.', { icon: 'warning', title: 'No active rent' }); return; }
            ensureSwal(function() {
                Swal.fire({
                    title: 'Send receipt to WhatsApp?',
                    text: 'This will send the receipt image via Fonnte.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Send',
                    cancelButtonText: 'Cancel'
                }).then(function(result) {
                    if (!result.isConfirmed) return;
                    var maxTries = 3;
                    function postSendWhatsapp() {
                        return fetch('/rentals/' + currentRentalId + '/invoice/send-whatsapp' + rentalTutorialQs(), {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value, 'Accept': 'application/json' }
                        }).then(function(r) {
                            return r.json().then(function(d) { return { ok: r.ok, data: d }; }).catch(function() {
                                return { ok: false, data: { error: 'Invalid response from server.' } };
                            });
                        });
                    }
                    function failMessage(res) {
                        var msg = (res.data && (res.data.error || res.data.message)) ? (res.data.error || res.data.message) : 'Failed to send.';
                        var det = (res.data && res.data.details) ? String(res.data.details) : '';
                        if (det.length > 400) det = det.slice(0, 400) + '…';
                        return det ? (msg + '\n\n' + det) : msg;
                    }
                    function promptRetry(text, attemptIndex) {
                        return Swal.fire({
                            icon: 'error',
                            title: 'Failed',
                            text: text,
                            showCancelButton: true,
                            confirmButtonText: 'Try again',
                            cancelButtonText: 'Close'
                        }).then(function(r) {
                            return r.isConfirmed && (attemptIndex + 1) < maxTries;
                        });
                    }
                    function attempt(attemptIndex) {
                        var isRetry = attemptIndex > 0;
                        Swal.fire({
                            title: isRetry ? 'Retrying send…' : 'Preparing receipt…',
                            text: isRetry ? 'Sending to Fonnte again' : 'Generating receipt image',
                            allowOutsideClick: false,
                            didOpen: function() { Swal.showLoading(); }
                        });
                        var prep = isRetry
                            ? Promise.resolve()
                            : ensureInvoicePrepared();
                        return prep
                            .then(function() { return postSendWhatsapp(); })
                            .then(function(res) {
                                if (res.ok) {
                                    Swal.fire('Sent', 'Receipt generated and WhatsApp send requested.', 'success');
                                    return;
                                }
                                return promptRetry(failMessage(res), attemptIndex).then(function(again) {
                                    if (again) return attempt(attemptIndex + 1);
                                });
                            })
                            .catch(function(err) {
                                var t = (err && err.message) ? err.message : 'Failed to send.';
                                return promptRetry(t, attemptIndex).then(function(again) {
                                    if (again) return attempt(attemptIndex + 1);
                                });
                            });
                    }
                    attempt(0);
                });
            });
        }

        function restoreCartFromOld() {
            var oldIds = __rentalsOldOutfitIds;
            var oldDiscs = __rentalsOldItemDiscounts;
            var oldDiscTypes = __rentalsOldItemDiscountTypes;
            if (Array.isArray(oldIds) && oldIds.length && allOutfits.length) {
                oldIds.forEach(function(id) {
                    var outfit = allOutfits.find(function(s) { return s.id == id; });
                    if (outfit && !cart[id]) {
                        cart[id] = {
                            id: outfit.id, code: outfit.code, new_code: outfit.new_code || '',
                            price: rentalNumericOutfitPrice(outfit.price), description: outfit.description || '',
                            discount: (oldDiscs && oldDiscs[id]) ? oldDiscs[id] : '',
                            discountType: (oldDiscTypes && oldDiscTypes[id]) ? oldDiscTypes[id] : 'fixed',
                            needs_manager_approval: !!outfit.needs_manager_approval
                        };
                    }
                });
                renderOutfitList();
                renderCart();
            }
        }

        if (__rentalsHasOldDates) {
            setTimeout(function() { fetchOutfits(); setTimeout(restoreCartFromOld, 200); }, 100);
        }

        function openEditModal(rental, _fresh) {
            if (!_fresh && rental && rental.id) {
                document.getElementById('editModal').classList.remove('hidden');
                document.body.style.overflow = 'hidden';
                setEditModalLoading(true);
                fetch('/rentals/search?id=' + encodeURIComponent(rental.id) + '&per_page=1&page=1&_=' + Date.now() + rentalsDebugTodayQuery() + (rental.is_tutorial ? '&tutorial=1' : ''), { cache: 'no-store', credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        var fresh = (data && data.data && data.data[0]) ? data.data[0] : null;
                        setEditModalLoading(false);
                        openEditModal(fresh || rental, true);
                    })
                    .catch(function() { setEditModalLoading(false); openEditModal(rental, true); });
                return;
            }
            editModalViewOnly = !mayOperateRentTransactions;
            editModalHardLocked = !!(rental && rental.status === 'returned' && !_adminTransactionEditMode);
            // The onboarding tour's own practice rental is exempt from the shift
            // requirement server-side (see rejectUnlessOpenShift's tutorial_practice
            // check) — without also skipping it here, the tour's "click Edit" step
            // would just show this real alert and never open the modal at all for
            // anyone who hasn't opened a shift yet.
            // Opening a tutorial-tagged rental should never hit the real
            // shift-required block — whether that's the guided tour driving it
            // (window.__rentalTutorialMode already true) or a direct link, e.g.
            // "My Requests" pointing at a leftover tutorial edit request. Turn
            // the same mode flag on here too if nothing already has it on, so
            // this reuses the tour's existing exemption instead of a second
            // parallel one — and remember WE turned it on, so closeModal()
            // only turns it back off for opens that actually needed it.
            if (rental && rental.is_tutorial && !window.__rentalTutorialMode) {
                window.__rentalTutorialMode = true;
                modalForcedTutorialMode = true;
            }
            if (mayOperateRentTransactions && rentalMutationsLocked && !window.__rentalTutorialMode) {
                window.rentalAlert('Open a shift before editing rents.', { icon: 'warning', title: 'Shift required' });
                return;
            }
            applyEditModalViewOnlyState();
            var handledRow = document.getElementById('editHandledByRow');
            var handledDisplay = document.getElementById('editHandledByDisplay');
            if (handledRow && handledDisplay) {
                if (rental.handled_by_name) {
                    handledDisplay.textContent = formatHandledByDisplay(rental.handled_by_name, rental.handled_by_role);
                    handledRow.classList.remove('hidden');
                } else {
                    handledDisplay.textContent = '';
                    handledRow.classList.add('hidden');
                }
            }
            var createdByRow = document.getElementById('editCreatedByRow');
            var createdByDisplay = document.getElementById('editCreatedByDisplay');
            if (createdByRow && createdByDisplay) {
                if (rental.created_by_name) {
                    var createdByText = formatHandledByDisplay(rental.created_by_name, rental.created_by_role);
                    if (rental.created_label) createdByText += ' · ' + rental.created_label;
                    createdByDisplay.textContent = createdByText;
                    createdByRow.classList.remove('hidden');
                } else {
                    createdByDisplay.textContent = '';
                    createdByRow.classList.add('hidden');
                }
            }
            currentRentalId = rental.id;
            var freeBadge = document.getElementById('editFreeBadge');
            if (freeBadge) {
                var fr = rental.free || null;
                freeBadge.classList.toggle('hidden', !fr);
                var frText = document.getElementById('editFreeBadgeText');
                if (fr && frText) {
                    frText.textContent = 'Approved by ' + (fr.approved_by || '—') + ', requested by ' + (fr.requested_by || '—')
                        + (fr.approved_at ? ' · ' + fr.approved_at : '')
                        + (fr.reason ? ' · Reason: ' + fr.reason : '')
                        + (Number(fr.refund_amount || 0) > 0 ? ' · Refunded Rp ' + Number(fr.refund_amount).toLocaleString('id-ID') : '');
                }
            }
            currentRentalPickedUp = !!rental.picked_up;
            var lateBtn = document.getElementById('editLateDoneBtn');
            if (lateBtn) {
                lateBtn.classList.toggle('hidden', !(mayOperateRentTransactions && (!rental.is_tutorial || window.__rentalTutorialMode) && rental.status === 'active'));
            }
            var freeBtn = document.getElementById('editRequestFreeBtn');
            if (freeBtn) {
                freeBtn.classList.toggle('hidden', !(mayOperateRentTransactions && !rental.is_tutorial
                    && (rental.status === 'active' || rental.status === 'returned')
                    && Number(rental.total_price || 0) > 0));
            }
            currentRentalHasItemRemovalApproved = !!rental.has_item_removal_approved;
            currentRentalItemRemovalChangeAmount = (rental.item_removal_change_amount != null && Number.isFinite(Number(rental.item_removal_change_amount))) ? Number(rental.item_removal_change_amount) : null;
            currentRentalItemRemovalRefundAmount = (rental.item_removal_refund_amount != null && Number.isFinite(Number(rental.item_removal_refund_amount))) ? Number(rental.item_removal_refund_amount) : null;
            currentEditRentalCreatedAt = rental.created_at ? String(rental.created_at).substring(0, 10) : null;
            clearInvoiceActionCache();
            editSelectedIds = (rental.outfit_ids || []).map(rentalNumericOutfitId).filter(function(n) { return n !== null; });
            editCart = {};
            takeoverFrom = {};
            currentEditRentalOutfitsMeta = {};
            if (_pricingEnabled) {
            editSnapshotTotalDue = (rental.total_price != null && rental.total_price !== '' && Number.isFinite(Number(rental.total_price)))
                ? (Number(rental.total_price) + (rental.overdue_fee_amount != null && rental.overdue_fee_amount !== '' && Number.isFinite(Number(rental.overdue_fee_amount)) ? Number(rental.overdue_fee_amount) : 0))
                : null;
            } else {
            editSnapshotTotalDue = null;
            }
            // Fixed baseline (unlike editSnapshotTotalDue, which gets overwritten
            // by the live cart total as items change) — needed to split any
            // overpayment into "customer already overpaid this" vs. "this only
            // appeared because an item got removed" once diff > 0.
            editOriginalDueAtOpen = editSnapshotTotalDue;
            if (!_pricingEnabled) {
                editPaymentBasePaid = 0;
                editDownPaymentAmount = null;
                editPaymentFieldMode = null;
                editSnapshotTotalDue = null;
                resetEditModalPaymentLockUi();
            }
            if (rental.outfits && Array.isArray(rental.outfits)) {
                rental.outfits.forEach(function(s) {
                    var msid = rentalNumericOutfitId(s.id);
                    if (msid === null) return;
                    currentEditRentalOutfitsMeta[msid] = {
                        pivot_discount: s.pivot_discount,
                        pivot_discount_type: s.pivot_discount_type || 'fixed',
                        pivot_discount_note: s.pivot_discount_note || '',
                        price: s.price,
                    };
                });
            }
            seedEditCartFromRentalOutfits(rental);
            if (typeof renderEditCart === 'function') {
                renderEditCart();
            }
            clearEditErrorSummary();
            document.getElementById('editForm').action = '/rentals/' + rental.id + (rental.is_tutorial ? '?tutorial=1' : '');
            document.getElementById('editSd').value = rental.start_date;
            document.getElementById('editEd').value = rental.end_date;
            if (_adminTransactionEditMode) {
                document.getElementById('editSd').removeAttribute('min');
                document.getElementById('editEd').removeAttribute('min');
            } else {
                lowerEditDateMinToValue(document.getElementById('editSd'), rental.start_date);
                lowerEditDateMinToValue(document.getElementById('editEd'), rental.end_date);
            }
            (function() {
                var base = (rental && rental.overdue_base_end_date) ? String(rental.overdue_base_end_date || '') : '';
                var thru = (rental && rental.overdue_charge_through_date) ? String(rental.overdue_charge_through_date || '') : '';
                if (!base || !thru) {
                    var rec = loadOverdueMergeRecord(rental ? rental.id : null);
                    if (rec) {
                        if (!base && rec.base_end) base = String(rec.base_end || '');
                        if (!thru && rec.through) thru = String(rec.through || '');
                    }
                }
                if (!base && thru) {
                    base = String(rental.end_date || '');
                }
                var feeApplied = (rental && rental.overdue_fee_amount != null && Number(rental.overdue_fee_amount) > 0.004)
                    || (rental && rental.overdue_fee_applied_at);
                if (!base && feeApplied) {
                    base = String(rental.end_date || '');
                }
                editOverdueMergedOnce = !!feeApplied;
                var baseEl = document.getElementById('editOverdueBaseEndDate');
                if (baseEl) baseEl.value = base || '';
                var thruEl = document.getElementById('editOverdueChargeThroughDate');
                if (thruEl) thruEl.value = thru || '';
                var feeRateEl = document.getElementById('editOverdueFeeRate');
                if (feeRateEl) feeRateEl.value = (rental && rental.overdue_fee_rate != null) ? String(rental.overdue_fee_rate) : '';
                var feeDaysEl = document.getElementById('editOverdueFeeDays');
                if (feeDaysEl) feeDaysEl.value = (rental && rental.overdue_fee_days != null) ? String(rental.overdue_fee_days) : '';
                var feeAmtEl = document.getElementById('editOverdueFeeAmount');
                if (feeAmtEl) feeAmtEl.value = (rental && rental.overdue_fee_amount != null) ? String(rental.overdue_fee_amount) : '';
                editOriginalEndDateYmd = String(rental.end_date || '');
            })();
            editOverdueDismissed = false;
            currentEditRentalStatus = rental.status || null;
            document.getElementById('editCust').value = rental.customer_name;
            document.getElementById('editPhone').value = rental.phone;
            document.getElementById('editIg').value = rental.instagram || '';
            document.getElementById('editNotes').value = rental.notes || rental.address || '';
            document.getElementById('editPaymentMethod').value = rental.payment_method || '';
            if (_pricingEnabled) {
            (function() {
                editPaymentBasePaid = (rental.amount_paid != null && rental.amount_paid !== '') ? Number(rental.amount_paid) : 0;
                editOverduePaymentBasePaid = (rental.overdue_amount_paid != null && rental.overdue_amount_paid !== '') ? Number(rental.overdue_amount_paid) : 0;
                editOverduePaymentBaseChange = (rental.overdue_change_amount != null && rental.overdue_change_amount !== '') ? Number(rental.overdue_change_amount) : 0;
                editPaymentFieldMode = null;
                currentEditRentalStoredPaymentKind = rental.payment_kind === 'down_payment' ? 'down_payment' : (rental.payment_kind === 'final_payment' ? 'final_payment' : 'full');
                editDownPaymentAmount = (rental.down_payment_amount != null && rental.down_payment_amount !== '') ? Number(rental.down_payment_amount) : null;
                editPaymentLockSnapshot = {
                    method: rental.payment_method || '',
                    kind: currentEditRentalStoredPaymentKind,
                    amountNum: editPaymentBasePaid,
                    amountFormatted: editPaymentBasePaid > 0.004 ? rentalMoneyFormatNum(editPaymentBasePaid) : '',
                };
                var ovMethod = document.getElementById('editOverduePaymentMethod');
                if (ovMethod) ovMethod.value = rental.overdue_payment_method || '';
                var ovAmt = document.getElementById('editOverdueAmountPaid');
                if (ovAmt) ovAmt.value = '';
                var ovEntry = document.getElementById('editOverduePaymentEntry');
                if (ovEntry) ovEntry.value = 'replace';
                var pk = rental.payment_kind === 'down_payment' ? 'down_payment' : (rental.payment_kind === 'final_payment' ? 'final_payment' : 'full');
                var ekf = document.getElementById('editPaymentKindFull');
                var ekd = document.getElementById('editPaymentKindDown');
                var ekfp = document.getElementById('editPaymentKindFinalPayment');
                if (ekf && ekd) {
                    ekf.checked = pk === 'full';
                    ekd.checked = pk === 'down_payment';
                    if (ekfp) ekfp.checked = pk === 'final_payment';
                }
                syncEditPaymentKindSettlementVisibility(rental);
                var ed = document.getElementById('editDiscount');
                var edt = document.getElementById('editDiscountType');
                if (edt) edt.value = rental.discount_type || 'fixed';
                if (ed) {
                    var rd = rental.discount;
                    if (rd !== null && rd !== undefined && rd !== '') {
                        ed.value = (rental.discount_type || 'fixed') === 'fixed'
                            ? rentalMoneyFormatNum(Number(rd))
                            : String(parseFloat(String(rd)));
                    } else ed.value = '';
                }
                var exc = document.getElementById('editExtraCharge');
                if (exc) {
                    var rc = rental.extra_charge;
                    exc.value = (rc !== null && rc !== undefined && rc !== '' && Number(rc) > 0)
                        ? rentalMoneyFormatNum(Number(rc))
                        : '';
                }
                var edn = document.getElementById('editDiscountNote');
                if (edn) edn.value = rental.discount_note || '';
                var ednErr = document.getElementById('editDiscountNoteError');
                if (ednErr) { ednErr.textContent = ''; ednErr.classList.add('hidden'); }
            })();
            updateEditPricingDisplays();
            }
            syncEditOverdueReturnUi();
            syncEditOverduePaymentUi();
            // Reset tab initialization so it always defaults to "Available" on open
            var tabs = document.getElementById('editOutfitFilterTabs');
            if (tabs) delete tabs.dataset.initialized;
            currentEditOutfitFilter = 'available';
            document.getElementById('editModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            syncEditCooldownTrackedFromListForRental(rental.id);
            syncEditCooldownPendingUi();
            fetchEditOutfits(rental.start_date, rental.end_date, rental.id, editSelectedIds, { fullModalOverlay: !rentalEditHasServerOutfitPayload(rental) });
            applyEditModalViewOnlyState();
            snapshotEditFieldsForHighlight();
        }

        function refreshEditModalFields(rental) {
            if (!rental || !currentRentalId || Number(rental.id) !== Number(currentRentalId)) return;
            document.getElementById('editSd').value = rental.start_date;
            document.getElementById('editEd').value = rental.end_date;
            if (_adminTransactionEditMode) {
                document.getElementById('editSd').removeAttribute('min');
                document.getElementById('editEd').removeAttribute('min');
            } else {
                lowerEditDateMinToValue(document.getElementById('editSd'), rental.start_date);
                lowerEditDateMinToValue(document.getElementById('editEd'), rental.end_date);
            }
            document.getElementById('editCust').value = rental.customer_name;
            document.getElementById('editPhone').value = rental.phone;
            document.getElementById('editIg').value = rental.instagram || '';
            document.getElementById('editNotes').value = rental.notes || rental.address || '';
            document.getElementById('editPaymentMethod').value = rental.payment_method || '';
            if (_pricingEnabled) {
            (function() {
                editPaymentBasePaid = (rental.amount_paid != null && rental.amount_paid !== '') ? Number(rental.amount_paid) : 0;
                editOverduePaymentBasePaid = (rental.overdue_amount_paid != null && rental.overdue_amount_paid !== '') ? Number(rental.overdue_amount_paid) : 0;
                editOverduePaymentBaseChange = (rental.overdue_change_amount != null && rental.overdue_change_amount !== '') ? Number(rental.overdue_change_amount) : 0;
                currentEditRentalStoredPaymentKind = rental.payment_kind === 'down_payment' ? 'down_payment' : (rental.payment_kind === 'final_payment' ? 'final_payment' : 'full');
                editDownPaymentAmount = (rental.down_payment_amount != null && rental.down_payment_amount !== '') ? Number(rental.down_payment_amount) : null;
                editPaymentLockSnapshot = {
                    method: rental.payment_method || '',
                    kind: currentEditRentalStoredPaymentKind,
                    amountNum: editPaymentBasePaid,
                    amountFormatted: editPaymentBasePaid > 0.004 ? rentalMoneyFormatNum(editPaymentBasePaid) : '',
                };
                var pk = currentEditRentalStoredPaymentKind;
                var ekf = document.getElementById('editPaymentKindFull');
                var ekd = document.getElementById('editPaymentKindDown');
                var ekfp = document.getElementById('editPaymentKindFinalPayment');
                if (ekf && ekd) {
                    ekf.checked = pk === 'full';
                    ekd.checked = pk === 'down_payment';
                    if (ekfp) ekfp.checked = pk === 'final_payment';
                }
                syncEditPaymentKindSettlementVisibility(rental);
                var ed = document.getElementById('editDiscount');
                var edt = document.getElementById('editDiscountType');
                if (edt) edt.value = rental.discount_type || 'fixed';
                if (ed) {
                    var rd = rental.discount;
                    if (rd !== null && rd !== undefined && rd !== '') {
                        ed.value = (rental.discount_type || 'fixed') === 'fixed'
                            ? rentalMoneyFormatNum(Number(rd))
                            : String(parseFloat(String(rd)));
                    } else ed.value = '';
                }
                var exc = document.getElementById('editExtraCharge');
                if (exc) {
                    var rc = rental.extra_charge;
                    exc.value = (rc !== null && rc !== undefined && rc !== '' && Number(rc) > 0)
                        ? rentalMoneyFormatNum(Number(rc))
                        : '';
                }
                var edn = document.getElementById('editDiscountNote');
                if (edn) edn.value = rental.discount_note || '';
                var ovMethod = document.getElementById('editOverduePaymentMethod');
                if (ovMethod) ovMethod.value = rental.overdue_payment_method || '';
            })();
            updateEditPricingDisplays();
            }
            if (rental.outfits && Array.isArray(rental.outfits)) {
                rental.outfits.forEach(function(s) {
                    var msid = rentalNumericOutfitId(s.id);
                    if (msid === null) return;
                    currentEditRentalOutfitsMeta[msid] = {
                        pivot_discount: s.pivot_discount,
                        pivot_discount_type: s.pivot_discount_type || 'fixed',
                        pivot_discount_note: s.pivot_discount_note || '',
                        price: s.price,
                    };
                    if (editCart[msid]) {
                        editCart[msid].discount = s.pivot_discount != null ? s.pivot_discount : '';
                        editCart[msid].discountType = s.pivot_discount_type || 'fixed';
                        editCart[msid].discountNote = s.pivot_discount_note || '';
                    }
                });
                if (typeof renderEditCart === 'function') renderEditCart();
            }
            if (typeof applyEditModalViewOnlyState === 'function') applyEditModalViewOnlyState();
            snapshotEditFieldsForHighlight();
        }

        // Highlight fields the user has changed from what's currently saved — a quick
        // visual "here's what you're about to submit" check before Save/Submit.
        var _editFieldSnapshot = {};
        var _editHighlightFieldIds = ['editSd', 'editEd', 'editCust', 'editPhone', 'editIg', 'editNotes', 'editDiscount', 'editDiscountType', 'editDiscountNote', 'editExtraCharge', 'editPaymentMethod', 'editAmountPaid'];

        function snapshotEditFieldsForHighlight() {
            _editFieldSnapshot = {};
            _editHighlightFieldIds.forEach(function(id) {
                var el = document.getElementById(id);
                if (!el) return;
                _editFieldSnapshot[id] = el.value;
                el.classList.remove('field-changed');
            });
            var checkedRadio = document.querySelector('#editForm input[name="payment_kind"]:checked');
            _editFieldSnapshot.payment_kind = checkedRadio ? checkedRadio.value : '';
            document.querySelectorAll('#editForm input[name="payment_kind"]').forEach(function(r) {
                r.classList.remove('field-changed');
            });
            applyPendingRequestFieldHighlights();
        }

        // When this rental already has a pending edit request, show the requester
        // what they asked for right on the field itself — same green highlight as a
        // live in-progress edit — instead of making them dig into "What was requested?"
        // text to find out. Field keys/outfit ids come from the hidden diff <template>
        // rendered per pending approval in rentals/index.blade.php.
        function pendingCaptionEl(oldLabel, newLabel) {
            var cap = document.createElement('div');
            cap.setAttribute('data-pending-caption', '1');
            cap.className = 'mt-1 text-xs rounded bg-emerald-50 border border-emerald-200 px-2 py-1';
            cap.innerHTML = '<span class="text-emerald-800 font-medium">Requested:</span> '
                + '<span class="text-gray-500 line-through">' + String(oldLabel).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</span> '
                + '<span class="text-emerald-700">&#8594;</span> '
                + '<span class="text-emerald-800 font-semibold">' + String(newLabel).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</span>';
            return cap;
        }

        function applyPendingRequestFieldHighlights() {
            // Clear any captions from a previous render/highlight pass before re-adding —
            // this function runs on every cart re-render, so without this we'd stack
            // duplicate "Requested:" notes under the same field each time it's called.
            document.querySelectorAll('[data-pending-caption]').forEach(function(el) { el.remove(); });
            if (!currentRentalId) return;
            var aid = getEditCooldownPendingApprovalIdForRental(currentRentalId);
            if (!aid) return;
            var tpl = document.getElementById('pending-edit-diff-' + aid);
            if (!tpl) return;
            var changedFields = [];
            var changedItemOutfitIds = [];
            var fieldDetail = {};
            var itemDetail = {};
            try { changedFields = JSON.parse(tpl.getAttribute('data-changed-fields') || '[]'); } catch (e) {}
            try { changedItemOutfitIds = JSON.parse(tpl.getAttribute('data-changed-item-discount-outfit-ids') || '[]'); } catch (e) {}
            try { fieldDetail = JSON.parse(tpl.getAttribute('data-changed-fields-detail') || '{}'); } catch (e) {}
            try { itemDetail = JSON.parse(tpl.getAttribute('data-changed-item-discount-detail') || '{}'); } catch (e) {}
            var fieldToId = {
                customer_name: 'editCust', phone: 'editPhone', instagram: 'editIg', notes: 'editNotes',
                payment_method: 'editPaymentMethod', amount_paid: 'editAmountPaid',
                discount: 'editDiscount', discount_type: 'editDiscountType', discount_note: 'editDiscountNote',
                extra_charge: 'editExtraCharge', start_date: 'editSd', end_date: 'editEd'
            };
            // Note: unlike editHighlightCheck() (which glows the border for a LIVE
            // in-progress edit with no other explanation nearby), a pending request's
            // fields get the inline "Requested: old → new" caption instead of the
            // glowing border — the caption already carries the same signal plus the
            // actual values, so a border here would just be redundant double emphasis.
            changedFields.forEach(function(f) {
                var d = fieldDetail[f];
                if (f === 'payment_kind') {
                    var radios = document.querySelectorAll('#editForm input[name="payment_kind"]');
                    if (d && radios.length) {
                        var lastRadio = radios[radios.length - 1];
                        var radioGroup = lastRadio.closest('div') || lastRadio.parentElement;
                        (radioGroup.parentElement || radioGroup).insertBefore(pendingCaptionEl(d.old, d.new), (radioGroup.parentElement ? radioGroup.nextSibling : null));
                    }
                    return;
                }
                var elId = fieldToId[f];
                var el = elId ? document.getElementById(elId) : null;
                if (!el) return;
                if (d && el.parentElement) {
                    el.insertAdjacentElement('afterend', pendingCaptionEl(d.old, d.new));
                }
            });
            changedItemOutfitIds.forEach(function(sid) {
                var els = document.querySelectorAll('[data-outfit-id="' + sid + '"]');
                if (!els.length) return;
                var d = itemDetail[sid] || itemDetail[String(sid)];
                var lastEl = els[els.length - 1];
                if (d && lastEl) {
                    lastEl.insertAdjacentElement('afterend', pendingCaptionEl(d.old, d.new));
                }
            });
        }

        function editHighlightCheck(el) {
            if (!el || !el.id || !(el.id in _editFieldSnapshot)) return;
            if (String(el.value) !== String(_editFieldSnapshot[el.id])) {
                el.classList.add('field-changed');
            } else {
                el.classList.remove('field-changed');
            }
        }

        document.addEventListener('input', function(e) {
            var t = e.target;
            if (t && t.closest && t.closest('#editForm') && _editHighlightFieldIds.indexOf(t.id) !== -1) {
                editHighlightCheck(t);
            }
        });
        document.addEventListener('change', function(e) {
            var t = e.target;
            if (!t || !t.closest || !t.closest('#editForm')) return;
            if (_editHighlightFieldIds.indexOf(t.id) !== -1) {
                editHighlightCheck(t);
            } else if (t.name === 'payment_kind') {
                var changed = t.checked && t.value !== _editFieldSnapshot.payment_kind;
                document.querySelectorAll('#editForm input[name="payment_kind"]').forEach(function(r) {
                    r.classList.toggle('field-changed', r === t && changed);
                });
            }
        });

        function clearInvoiceActionCache() {
            var openBtn = document.getElementById('openInvoicePageBtn');
            if (openBtn && openBtn.dataset) {
                delete openBtn.dataset.signedViewUrl;
            }
            var genBtn = document.getElementById('generateInvoiceBtn');
            if (genBtn && genBtn.dataset) {
                delete genBtn.dataset.signedUrl;
                delete genBtn.dataset.signedPngUrl;
            }
        }

        function syncEditPayButtonUi() {
            var btn = document.getElementById('editPayBtn');
            if (!btn) {
                return;
            }
            if (!_pricingEnabled || !currentRentalId) {
                btn.classList.add('hidden');
                return;
            }
            if (editCooldownPendingActiveForCurrentRental()) {
                btn.classList.add('hidden');
                return;
            }
            var locked = typeof editModalPaymentFieldsLocked === 'function' && editModalPaymentFieldsLocked();
            var due = null;
            var ids = Object.keys(editCart || {});
            if (ids.length) {
                var breakdown = computeEditPricingBreakdown();
                due = breakdown && Number.isFinite(breakdown.grand) ? breakdown.grand : null;
            } else if (editSnapshotTotalDue != null && Number.isFinite(Number(editSnapshotTotalDue))) {
                due = Number(editSnapshotTotalDue);
            }
            // Overdue change is cash handed back to the customer, so it never counts as paid.
            var overduePaid = Number(editOverduePaymentBasePaid) || 0;
            var totalPaid = (Number(editPaymentBasePaid) || 0) + overduePaid;
            var settled = due != null && Number.isFinite(due) && (totalPaid + 0.004 >= due);
            // Any rent with a recorded payment and an outstanding balance can take
            // an additional payment (down payments, but also underpaid full/final
            // payments whose total drifted after they were recorded).
            var show = !locked
                && editPaymentFieldMode === 'increment'
                && !settled;
            btn.classList.toggle('hidden', !show);
            btn.disabled = !show;
            btn.classList.toggle('opacity-50', !show);
            btn.classList.toggle('cursor-not-allowed', !show);
        }

        function syncEditOverduePaymentUi() {
            var panel = document.getElementById('editOverduePayPanel');
            if (!panel) {
                return;
            }
            var info = document.getElementById('editOverduePayInfo');
            var btn = document.getElementById('editPayOverdueBtn');
            var amtEl = document.getElementById('editOverdueAmountPaid');
            var entryEl = document.getElementById('editOverduePaymentEntry');
            if (!_pricingEnabled || !currentRentalId || editModalViewOnly) {
                panel.classList.add('hidden');
                if (btn) btn.classList.add('hidden');
                if (info) info.textContent = '';
                if (amtEl) amtEl.value = '';
                if (entryEl) entryEl.value = 'replace';
                return;
            }
            if (editCooldownPendingActiveForCurrentRental()) {
                panel.classList.add('hidden');
                if (btn) btn.classList.add('hidden');
                return;
            }
            var fee = editOverdueFeeAmountNum();
            if (!(fee > 0.004)) {
                panel.classList.add('hidden');
                if (btn) btn.classList.add('hidden');
                if (info) info.textContent = '';
                return;
            }
            if (entryEl) entryEl.value = 'replace';
            var paid = Number(editOverduePaymentBasePaid) || 0;
            var rem = Math.max(0, fee - paid);
            if (!(rem > 0.004)) {
                panel.classList.add('hidden');
                if (btn) btn.classList.add('hidden');
                if (info) info.textContent = '';
                if (amtEl) amtEl.value = '';
                return;
            }
            panel.classList.remove('hidden');
            // The balance is already stated once in the overdue facts above the
            // panel; here we only prefill it so paying is a two-click action.
            if (amtEl) {
                amtEl.setAttribute('placeholder', 'Minimum Rp ' + rem.toLocaleString('id-ID'));
                if (!amtEl.value.trim() && typeof rentalMoneyFormatNum === 'function') {
                    amtEl.value = rentalMoneyFormatNum(rem);
                }
            }
            if (btn) {
                btn.textContent = 'Pay overdue Rp ' + rem.toLocaleString('id-ID');
                btn.classList.remove('hidden');
                btn.disabled = false;
                btn.classList.remove('opacity-50', 'cursor-not-allowed');
            }
        }

        function payOverdueChargeFromEditModal() {
            if (!_pricingEnabled) {
                return;
            }
            if (!currentRentalId) {
                window.rentalAlert('Open a rent first.', { icon: 'warning', title: 'No active rent' });
                return;
            }
            if (editModalViewOnly) {
                return;
            }
            if (editCooldownPendingActiveForCurrentRental()) {
                window.rentalAlert('This rent has a pending cool-down approval. Wait for management before adding overdue payment.', { icon: 'warning', title: 'Approval pending' });
                return;
            }
            var fee = editOverdueFeeAmountNum();
            var paid = Number(editOverduePaymentBasePaid) || 0;
            var rem = Math.max(0, fee - paid);
            if (!(fee > 0.004) || rem <= 0.004) {
                window.rentalAlert('No overdue balance remains for this rent.', { icon: 'info', title: 'No overdue due' });
                return;
            }
            var methodEl = document.getElementById('editOverduePaymentMethod');
            var method = methodEl ? String(methodEl.value || '') : '';
            if (!method) {
                window.rentalAlert('Select a payment method first.', { icon: 'warning', title: 'Missing method' });
                return;
            }
            var amtEl = document.getElementById('editOverdueAmountPaid');
            if (!amtEl) {
                return;
            }
            var raw = String(amtEl.value || '').trim();
            var parsed = raw === '' ? NaN : rentalMoneyParse(raw);
            if (!Number.isFinite(parsed) || parsed <= 0.004) {
                window.rentalAlert('Enter the overdue payment amount first.', { icon: 'warning', title: 'Missing amount' });
                return;
            }
            if (parsed + 0.004 < rem) {
                window.rentalAlert('Overdue payment must cover the full overdue balance (Rp ' + rem.toLocaleString('id-ID') + ').', { icon: 'warning', title: 'Amount too low' });
                return;
            }
            var formEl = document.getElementById('editForm');
            if (!formEl) {
                return;
            }
            var confirmText = 'Pay overdue Rp ' + parsed.toLocaleString('id-ID') + ' now?';
            window.rentalConfirm(confirmText, {
                title: 'Confirm overdue payment',
                confirmButtonText: 'Pay',
                cancelButtonText: 'Cancel',
                icon: 'question',
            }).then(function(confirmed) {
                if (!confirmed) {
                    return;
                }
                clearEditErrorSummary();
                var fd = new FormData(formEl);
                clearInvoiceActionCache();
                patchFormDataForEditPaymentLock(fd);
                if (_pricingEnabled) {
                    fd.set('payment_entry', 'replace');
                    fd.delete('payment_amount_add');
                    var snapAmt = null;
                    if (editPaymentLockSnapshot) {
                        snapAmt = (editPaymentLockSnapshot.amountFormatted !== '' && editPaymentLockSnapshot.amountFormatted != null)
                            ? editPaymentLockSnapshot.amountFormatted
                            : (editPaymentLockSnapshot.amountNum != null ? String(editPaymentLockSnapshot.amountNum) : null);
                        if (!fd.get('payment_method')) fd.set('payment_method', editPaymentLockSnapshot.method || '');
                        if (!fd.get('payment_kind')) fd.set('payment_kind', editPaymentLockSnapshot.kind || 'full');
                    }
                    if (snapAmt == null) {
                        snapAmt = editPaymentBasePaid > 0.004 ? rentalMoneyFormatNum(editPaymentBasePaid) : '0';
                    }
                    fd.set('amount_paid', snapAmt);
                }
                fd.set('overdue_payment_method', method);
                fd.set('overdue_payment_entry', 'increment');
                fd.set('overdue_payment_amount_add', raw);
                fd.delete('overdue_amount_paid');
                fd.delete('outfit_ids');
                fd.delete('outfit_ids[]');
                Object.keys(editCart || {}).forEach(function(id) {
                    fd.append('outfit_ids[]', id);
                });
                if (_pricingEnabled) {
                    Object.keys(editCart || {}).forEach(function(id) {
                        var item = editCart[id] || {};
                        fd.set('item_discounts[' + id + ']', item.discount != null && item.discount !== '' ? item.discount : '');
                        fd.set('item_discount_types[' + id + ']', item.discountType || 'fixed');
                        fd.set('item_discount_notes[' + id + ']', item.discountNote || '');
                    });
                }
                if (typeof takeoverFrom !== 'undefined') {
                    Object.keys(takeoverFrom || {}).forEach(function(outfitId) {
                        if (!editCart[outfitId]) return;
                        fd.set('takeover_from[' + outfitId + ']', takeoverFrom[outfitId]);
                    });
                }
                var payBtn = document.getElementById('editPayOverdueBtn');
                if (payBtn) {
                    payBtn.disabled = true;
                    payBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
                fetch(formEl.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                    .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d, status: r.status }; }); })
                    .then(function(res) {
                        if (!res.ok) {
                            if (res.data && res.data.errors) {
                                showEditErrorSummary(Object.values(res.data.errors).flat());
                            } else if (res.data && (res.data.error || res.data.message)) {
                                showEditErrorSummary([res.data.error || res.data.message]);
                            } else {
                                showEditErrorSummary(['Overdue payment failed.']);
                            }
                            return;
                        }
                        clearInvoiceActionCache();
                        loadRentals();
                        reloadEditModalFromServer(currentRentalId, null);
                        if (res.data && res.data.mode === 'edit_approval_request_sent') {
                            window.rentalAlert(res.data.message || 'Submitted for supervisor/management approval. Nothing has been saved yet.', { icon: 'info', title: 'Approval needed' });
                            return;
                        }
                        var ch = res.data && res.data.overdue_payment_change != null ? Number(res.data.overdue_payment_change) : 0;
                        if (ch > 0.004) {
                            window.rentalAlert('Overdue payment saved. Change (cash to return) Rp ' + ch.toLocaleString('id-ID') + '.', { icon: 'success', title: 'Paid' });
                        } else {
                            window.rentalAlert('Overdue payment saved.', { icon: 'success', title: 'Paid' });
                        }
                    })
                    .catch(function() { showEditErrorSummary(['Overdue payment failed. Please try again.']); })
                    .finally(function() {
                        if (payBtn) {
                            payBtn.disabled = false;
                            payBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                        }
                    });
            });
        }

        function payDownPaymentFromEditModal() {
            if (!_pricingEnabled) {
                return;
            }
            if (!currentRentalId) {
                window.rentalAlert('Open a rent first.', { icon: 'warning', title: 'No active rent' });
                return;
            }
            if (editCooldownPendingActiveForCurrentRental()) {
                window.rentalAlert('This rent has a pending cool-down approval. Wait for management before adding payment.', { icon: 'warning', title: 'Approval pending' });
                return;
            }
            if (typeof editModalPaymentFieldsLocked === 'function' && editModalPaymentFieldsLocked()) {
                window.rentalAlert('Payment is locked for this rent.', { icon: 'warning', title: 'Payment locked' });
                return;
            }
            if (editPaymentFieldMode !== 'increment') {
                window.rentalAlert('Pay is only available when a recorded payment still has a balance.', { icon: 'warning', title: 'No balance to pay' });
                return;
            }
            var due = null;
            var ids = Object.keys(editCart || {});
            if (ids.length) {
                var breakdown = computeEditPricingBreakdown();
                due = breakdown && Number.isFinite(breakdown.grand) ? breakdown.grand : null;
            } else if (editSnapshotTotalDue != null && Number.isFinite(Number(editSnapshotTotalDue))) {
                due = Number(editSnapshotTotalDue);
            }
            // Overdue change is cash handed back to the customer, so it never counts as paid.
            var overduePaid = Number(editOverduePaymentBasePaid) || 0;
            var totalPaid = (Number(editPaymentBasePaid) || 0) + overduePaid;
            if (due != null && Number.isFinite(due) && totalPaid + 0.004 >= due) {
                window.rentalAlert('This rent is already fully paid.', { icon: 'info', title: 'Already settled' });
                syncEditPayButtonUi();
                return;
            }
            var amtEl = document.getElementById('editAmountPaid');
            if (!amtEl) {
                return;
            }
            var raw = String(amtEl.value || '').trim();
            var parsed = raw === '' ? NaN : rentalMoneyParse(raw);
            if (!Number.isFinite(parsed) || parsed <= 0.004) {
                window.rentalAlert('Enter the additional payment amount first.', { icon: 'warning', title: 'Missing amount' });
                return;
            }
            var formEl = document.getElementById('editForm');
            if (!formEl) {
                return;
            }
            var confirmText = 'Pay Rp ' + parsed.toLocaleString('id-ID') + ' now?';
            window.rentalConfirm(confirmText, {
                title: 'Confirm payment',
                confirmButtonText: 'Pay',
                cancelButtonText: 'Cancel',
                icon: 'question',
            }).then(function(confirmed) {
                if (!confirmed) {
                    return;
                }
                var fd = new FormData(formEl);
                clearInvoiceActionCache();
                fd.set('payment_entry', 'increment');
                // Marks a real Pay click: the server only takes payment_amount_add as money
                // received from this button (a plain Save never records a payment).
                fd.set('pay_action', '1');
                if (amtEl.name !== 'payment_amount_add') {
                    fd.delete('amount_paid');
                    fd.set('payment_amount_add', raw);
                }
                fd.delete('outfit_ids');
                fd.delete('outfit_ids[]');
                Object.keys(editCart || {}).forEach(function(id) {
                    fd.append('outfit_ids[]', id);
                });
                if (_pricingEnabled) {
                    Object.keys(editCart || {}).forEach(function(id) {
                        var item = editCart[id] || {};
                        fd.set('item_discounts[' + id + ']', item.discount != null && item.discount !== '' ? item.discount : '');
                        fd.set('item_discount_types[' + id + ']', item.discountType || 'fixed');
                        fd.set('item_discount_notes[' + id + ']', item.discountNote || '');
                    });
                }
                if (typeof takeoverFrom !== 'undefined') {
                    Object.keys(takeoverFrom || {}).forEach(function(outfitId) {
                        if (!editCart[outfitId]) return;
                        fd.set('takeover_from[' + outfitId + ']', takeoverFrom[outfitId]);
                    });
                }
                var payBtn = document.getElementById('editPayBtn');
                if (payBtn) {
                    payBtn.disabled = true;
                    payBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
                fetch(formEl.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                    .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d, status: r.status }; }); })
                    .then(function(res) {
                        if (!res.ok) {
                            if (res.data && res.data.errors) {
                                showEditErrorSummary(Object.values(res.data.errors).flat());
                            } else if (res.data && (res.data.error || res.data.message)) {
                                showEditErrorSummary([res.data.error || res.data.message]);
                            } else {
                                showEditErrorSummary(['Payment failed.']);
                            }
                            return;
                        }
                        clearInvoiceActionCache();
                        loadRentals();
                        reloadEditModalFromServer(currentRentalId, null);
                        if (res.data && res.data.mode === 'edit_approval_request_sent') {
                            window.rentalAlert(res.data.message || 'Submitted for supervisor/management approval. Nothing has been saved yet.', { icon: 'info', title: 'Approval needed' });
                            return;
                        }
                        var ch = res.data && res.data.payment_change != null ? Number(res.data.payment_change) : 0;
                        if (ch > 0.004) {
                            window.rentalAlert('Payment saved. Change (cash to return) Rp ' + ch.toLocaleString('id-ID') + '.', { icon: 'success', title: 'Paid' });
                        } else {
                            window.rentalAlert('Payment saved.', { icon: 'success', title: 'Paid' });
                        }
                    })
                    .catch(function() { showEditErrorSummary(['Payment failed. Please try again.']); })
                    .finally(function() {
                        if (payBtn) {
                            payBtn.disabled = false;
                            payBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                        }
                    });
            });
        }

        function closeModal() {
            document.getElementById('editModal').classList.add('hidden');
            document.body.style.overflow = '';
            currentRentalHasItemRemovalApproved = false;
            clearEditErrorSummary();
            resetEditModalPaymentLockUi();
            editSnapshotTotalDue = null;
            editModalHardLocked = false;
            editModalViewOnly = false;
            setEditModalLoading(false);
            if (modalForcedTutorialMode) {
                modalForcedTutorialMode = false;
                window.__rentalTutorialMode = false;
                if (typeof window.loadRentals === 'function') window.loadRentals();
            }
        }
        document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });

        document.getElementById('editSd').addEventListener('focus', function() {
            if (_adminTransactionEditMode) { this.removeAttribute('min'); return; }
            // Don't clobber an existing date (e.g. an overdue rental's real start date,
            // now in the past) with a today floor — that leaves value < min and silently
            // blocks native form submission. Only impose the floor on an empty field.
            if (this.value) { return; }
            var t = new Date();
            this.setAttribute('min', t.getFullYear() + '-' + String(t.getMonth()+1).padStart(2,'0') + '-' + String(t.getDate()).padStart(2,'0'));
        });
        document.getElementById('editEd').addEventListener('focus', function() {
            if (_adminTransactionEditMode) { this.removeAttribute('min'); return; }
            if (this.value) { return; }
            var t = new Date();
            var today = t.getFullYear() + '-' + String(t.getMonth()+1).padStart(2,'0') + '-' + String(t.getDate()).padStart(2,'0');
            var sd = document.getElementById('editSd').value;
            this.setAttribute('min', sd > today ? sd : today);
        });
        window.ajaxSuccess = function(msg) {
            var el = document.getElementById('ajaxSuccess');
            if (!el) {
                el = document.createElement('div');
                el.id = 'ajaxSuccess';
                el.className = 'mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative text-sm';
                var container = document.querySelector('.max-w-7xl');
                if (container) container.prepend(el);
            }
            if (el) { el.textContent = msg; el.classList.remove('hidden'); setTimeout(function() { el.classList.add('hidden'); }, 4000); }
        };

        function clearEditErrorSummary() {
            var summary = document.getElementById('editErrorSummary');
            if (!summary) return;
            summary.classList.add('hidden');
            summary.innerHTML = '';
        }

        function showEditErrorSummary(messages) {
            var summary = document.getElementById('editErrorSummary');
            if (!summary) return;
            var list = Array.isArray(messages) ? messages.filter(Boolean) : [];
            if (!list.length) return clearEditErrorSummary();
            var _errMsg = list.join('\n');
            setTimeout(function() {
                if (window.rentalAlert) window.rentalAlert(_errMsg, { icon: 'error', title: 'Validation error' });
            }, 50);
            summary.innerHTML = '<div class="font-semibold mb-1">Please fix these issues:</div><ul class="list-disc list-inside">' + list.map(function(msg) {
                return '<li>' + String(msg) + '</li>';
            }).join('') + '</ul>';
            summary.classList.remove('hidden');
        }

        document.getElementById('editForm').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!mayOperateRentTransactions || editModalViewOnly) {
                window.rentalAlert('View-only mode. Your account cannot change this rent.', { icon: 'warning', title: 'View only' });
                return;
            }
            clearEditErrorSummary();
            document.getElementById('editSd').removeAttribute('min');
            document.getElementById('editEd').removeAttribute('min');

            // Ensure checked outfit ids are submitted — build hidden inputs for checked items that are disabled or not attached to the form
            // Remove previously added hidden inputs
            document.querySelectorAll('#editForm input.edit-outfit-hidden').forEach(function(el) { el.remove(); });
            document.querySelectorAll('#editForm input.edit-item-disc-hidden').forEach(function(el) { el.remove(); });
            document.querySelectorAll('#editForm input.takeover-hidden').forEach(function(el) { el.remove(); });
            var checkedBoxes = document.querySelectorAll('#editOutfitCheckboxList input[type=checkbox]:checked');
            checkedBoxes.forEach(function(cb) {
                // If checkbox is disabled or for some reason not part of the form, add a hidden input to guarantee submission
                if (cb.disabled || !cb.form) {
                    var h = document.createElement('input');
                    h.type = 'hidden';
                    h.name = 'outfit_ids[]';
                    h.value = cb.value;
                    h.className = 'edit-outfit-hidden';
                    document.getElementById('editForm').appendChild(h);
                }
            });

            // Also ensure items in editCart are submitted
            if (typeof editCart !== 'undefined') {
                Object.keys(editCart).forEach(function(id) {
                    // avoid duplicating if hidden input already exists
                    if (!document.querySelector('#editForm input.edit-outfit-hidden[value="' + id + '"]')) {
                        var h2 = document.createElement('input');
                        h2.type = 'hidden';
                        h2.name = 'outfit_ids[]';
                        h2.value = id;
                        h2.className = 'edit-outfit-hidden';
                        document.getElementById('editForm').appendChild(h2);
                    }
                });
            }

            if (typeof takeoverFrom !== 'undefined') {
                Object.keys(takeoverFrom).forEach(function(outfitId) {
                    if (!editCart[outfitId]) return;
                    var h = document.createElement('input');
                    h.type = 'hidden';
                    h.name = 'takeover_from[' + outfitId + ']';
                    h.value = takeoverFrom[outfitId];
                    h.className = 'takeover-hidden';
                    document.getElementById('editForm').appendChild(h);
                });
            }
            if (_pricingEnabled && typeof editCart !== 'undefined') {
                Object.keys(editCart).forEach(function(id) {
                    var item = editCart[id];
                    var di = document.createElement('input');
                    di.type = 'hidden';
                    di.name = 'item_discounts[' + id + ']';
                    di.value = item.discount != null && item.discount !== '' ? item.discount : '';
                    di.className = 'edit-item-disc-hidden';
                    document.getElementById('editForm').appendChild(di);
                    var dt = document.createElement('input');
                    dt.type = 'hidden';
                    dt.name = 'item_discount_types[' + id + ']';
                    dt.value = item.discountType || 'fixed';
                    dt.className = 'edit-item-disc-hidden';
                    document.getElementById('editForm').appendChild(dt);
                    var dn = document.createElement('input');
                    dn.type = 'hidden';
                    dn.name = 'item_discount_notes[' + id + ']';
                    dn.value = item.discountNote || '';
                    dn.className = 'edit-item-disc-hidden';
                    document.getElementById('editForm').appendChild(dn);
                });
            }
            if (_pricingEnabled && !editModalPaymentFieldsLocked()) {
                var _ebi3 = editRentalBillingInfo();
                if (_ebi3.extraDays > 0) {
                    var _eExc = document.getElementById('editExtraCharge');
                    var _eExcVal = _eExc ? (rentalMoneyParse(_eExc.value) || 0) : 0;
                    if (!(_eExcVal > 0)) {
                        showEditErrorSummary(['Additional charge is required — the selected dates have ' + _ebi3.extraDays + ' extra ' + (_ebi3.extraDays === 1 ? 'day' : 'days') + ' beyond the 3-day blocks.']);
                        if (_eExc) { _eExc.focus(); _eExc.classList.add('ring-2', 'ring-red-500'); }
                        return;
                    }
                }
            }
            var formEl = this;
            window.rentalConfirm('Save changes to this rent?', {
                title: 'Confirm update',
                confirmButtonText: 'Save',
                cancelButtonText: 'Cancel',
                icon: 'question',
            }).then(function(confirmed) {
                if (!confirmed) {
                    return;
                }
                if (editCooldownPendingActiveForCurrentRental()) {
                    window.rentalAlert('This rent has a pending cool-down approval. Wait for management before saving again.', { icon: 'warning', title: 'Approval pending' });
                    return;
                }
                var fd = new FormData(formEl);
                patchFormDataForEditPaymentLock(fd);
                // With a balance left, "Amount to pay" is a read-only display of the
                // remaining balance (posted as payment_amount_add). Saving an edit must
                // never record it as paid — money is taken with the Pay button only.
                // (Adding an item then Save/Submit used to settle the rent.)
                if (fd.get('payment_entry') === 'increment') {
                    fd.set('payment_amount_add', '0');
                    var storedKind = (editPaymentLockSnapshot && editPaymentLockSnapshot.kind) || currentEditRentalStoredPaymentKind;
                    if (storedKind) fd.set('payment_kind', storedKind);
                }
                // Same as the Create Rent submit: this is AJAX (preventDefault'd), so the
                // app's own auto-showing PWA loading overlay skips itself — show it
                // manually so there's visible feedback and nothing to spam-click during
                // the request.
                if (typeof window.rentalShowPwaLoading === 'function') window.rentalShowPwaLoading();
                fetch(formEl.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                    .then(function(r) {
                        if (r.redirected) {
                            closeModal();
                            loadRentals();
                            ajaxSuccess('Rent updated.');
                            setTimeout(function() { window.rentalAlert('Rent updated.', { icon: 'success', title: 'Saved' }); }, 50);
                            return null;
                        }
                        return r.json().then(function(d) {
                            if (!r.ok) {
                                if (d.errors) {
                                    showEditErrorSummary(Object.values(d.errors).flat());
                                } else if (d.error) {
                                    showEditErrorSummary([d.error]);
                                } else if (d.message) {
                                    showEditErrorSummary([d.message]);
                                } else {
                                    showEditErrorSummary(['Update failed.']);
                                }
                                return;
                            }
                            if (d.ok && d.mode === 'edit_approval_request_sent') {
                                closeModal();
                                loadRentals();
                                var _pendingMsg = d.message || 'Changes submitted for approval. Nothing has been saved yet.';
                                ajaxSuccess(_pendingMsg);
                                setTimeout(function() { window.rentalAlert(_pendingMsg, { icon: 'info', title: 'Submitted for approval' }); }, 50);
                                return;
                            }
                            if (d.ok) {
                                var ch = d.payment_change != null ? Number(d.payment_change) : 0;
                                    closeModal();
                                loadRentals();
                                ajaxSuccess('Rent updated.');
                                // The edit left the customer overpaid — a refund entry was posted
                                // automatically, but it only RECORDS the money is owed back, it
                                // never moves real cash. Surface this as its own unmissable alert
                                // (warning, not plain success) so it is never quietly missed.
                                if (d.refund_message) {
                                    setTimeout(function() { window.rentalAlert(d.refund_message, { icon: 'warning', title: 'Refund due to customer' }); }, 50);
                                    return;
                                }
                                var _alertMsg = ch > 0.004
                                    ? 'Saved. Change (cash to return) Rp ' + ch.toLocaleString('id-ID') + ' — you can see it under Total due when you open this rent again.'
                                    : 'Rent updated.';
                                var _alertTitle = ch > 0.004 ? 'Rent updated' : 'Saved';
                                setTimeout(function() { window.rentalAlert(_alertMsg, { icon: 'success', title: _alertTitle }); }, 50);
                                return;
                            }
                            showEditErrorSummary(['Unexpected response from server.']);
                        });
                    })
                    .catch(function() { showEditErrorSummary(['Update failed. Please try again.']); })
                    .finally(function() {
                        if (typeof window.rentalHidePwaLoading === 'function') window.rentalHidePwaLoading();
                    });
            });
        });

        function applyRentFilter() {
            var sel = document.getElementById('rentStatusFilter');
            currentStatus = sel ? sel.value : '';
            try { window.currentStatus = currentStatus; } catch (e) {}
            currentPage = 1;
            if (typeof rentalsOfflineMode !== 'undefined' && rentalsOfflineMode) loadRentalsOffline(currentPage);
            else loadRentals(currentPage);
        }

        var rentalDateFrom = '';
        var rentalDateTo = '';
        var rentalCreatedOn = ''; // debug-only filter: matches created_at, unlike the rent-period Dates filter
        (function wireRentDateFilter() {
            var fromEl = document.getElementById('rentDateFrom');
            var toEl = document.getElementById('rentDateTo');
            var createdEl = document.getElementById('rentCreatedOn');
            var clearBtn = document.getElementById('rentDateClearBtn');
            if (!fromEl || !toEl) return;

            try {
                var usp = new URLSearchParams(window.location.search || '');
                var df = usp.get('date_from') || '';
                var dt = usp.get('date_to') || '';
                if (df) { fromEl.value = df; rentalDateFrom = df; }
                if (dt) { toEl.value = dt; rentalDateTo = dt; }
                var co = usp.get('created_on') || '';
                if (co && createdEl) { createdEl.value = co; rentalCreatedOn = co; }
            } catch (e) {}

            function sync() {
                rentalDateFrom = String(fromEl.value || '');
                rentalDateTo = String(toEl.value || '');
                if (createdEl) rentalCreatedOn = String(createdEl.value || '');
                loadRentals();
            }
            fromEl.addEventListener('change', sync);
            toEl.addEventListener('change', sync);
            if (createdEl) createdEl.addEventListener('change', sync);
            if (clearBtn) clearBtn.addEventListener('click', function() {
                fromEl.value = '';
                toEl.value = '';
                rentalDateFrom = '';
                rentalDateTo = '';
                if (createdEl) createdEl.value = '';
                rentalCreatedOn = '';
                loadRentals();
            });
        })();

        if (__rentalsDebugToolsEnabled) {
        // Debug shift lookup lives inside the shift-control fragment, which is
        // re-fetched and re-injected; delegate from document so the handler survives.
        (function wireShiftDebugLookup() {
            function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
            function run() {
                var dateEl = document.getElementById('shiftDebugDate');
                var out = document.getElementById('shiftDebugResults');
                if (!dateEl || !out) return;
                var date = String(dateEl.value || '');
                if (!date) { out.classList.remove('hidden'); out.innerHTML = '<p class="text-amber-800">Pick a date first.</p>'; return; }
                out.classList.remove('hidden');
                out.innerHTML = '<p class="text-gray-500">Loading…</p>';
                fetch('/shifts/by-date?date=' + encodeURIComponent(date), { cache: 'no-store', headers: { 'Accept': 'application/json' } })
                    .then(function(r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
                    .then(function(data) {
                        var rows = Array.isArray(data.shifts) ? data.shifts : [];
                        if (!rows.length) { out.innerHTML = '<p class="text-gray-600">No shifts opened on ' + esc(date) + '.</p>'; return; }
                        var html = '<ul class="space-y-1.5">';
                        rows.forEach(function(s) {
                            var status = s.open ? '<span class="text-emerald-700 font-semibold">open</span>' : 'closed ' + esc(s.closed_at || '—');
                            var totals = s.open
                                ? 'totals at close'
                                : esc(s.transactions == null ? '—' : s.transactions) + ' tx · Rp ' + esc(s.revenue == null ? '—' : Number(s.revenue).toLocaleString('id-ID'));
                            html += '<li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-amber-200 first:border-t-0 pt-1.5 first:pt-0">'
                                + '<span><span class="font-semibold">' + esc(s.admin) + '</span> · ' + esc(s.period) + ' · opened ' + esc(s.opened_at || '—') + ' · ' + status + '</span>'
                                + '<span class="shrink-0">' + totals + ' · <a class="text-indigo-700 underline" href="' + esc(s.report_url) + '">report</a></span>'
                                + '</li>';
                        });
                        html += '</ul>';
                        out.innerHTML = html;
                    })
                    .catch(function() { out.innerHTML = '<p class="text-red-700">Lookup failed.</p>'; });
            }
            document.addEventListener('click', function(e) {
                if (e.target && e.target.closest && e.target.closest('#shiftDebugCheckBtn')) run();
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && e.target && e.target.id === 'shiftDebugDate') { e.preventDefault(); run(); }
            });
        })();
        }

        function toggleDetail(id) {
            const el = document.getElementById('detail-' + id);
            const btn = document.getElementById('toggle-' + id);
            if (el) {
                el.classList.toggle('hidden');
                btn.textContent = el.classList.contains('hidden') ? '+' : '−';
            }
        }

        function getRentalsCsrfToken() {
            var m = document.querySelector('meta[name="csrf-token"]');
            return m ? m.getAttribute('content') : '';
        }

        function showRentalsListToast(message, type) {
            var el = document.getElementById('rentalListToast');
            if (!el) return;
            if (rentalListToastTimer) {
                clearTimeout(rentalListToastTimer);
                rentalListToastTimer = null;
            }
            el.textContent = message;
            var theme = type === 'error'
                ? 'bg-red-50 border-red-200 text-red-900'
                : (type === 'warning'
                    ? 'bg-amber-50 border-amber-300 text-amber-950'
                    : 'bg-emerald-50 border-emerald-200 text-emerald-900');
            el.className = 'mb-3 rounded-lg border px-4 py-3 text-sm shadow-sm ' + theme;
            el.classList.remove('hidden');
            // Only pull the page to the toast for errors the user must see.
            // Info/sync toasts used to scrollIntoView here, yanking the page
            // ~2500px down to the list on every background notice.
            if (type === 'error') {
                el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            rentalListToastTimer = setTimeout(function() {
                el.classList.add('hidden');
                rentalListToastTimer = null;
            }, 5200);
        }

        function syncRentalMutationsLockedUi() {
            var form = document.getElementById('rentalForm');
            var fieldset = form ? form.querySelector('fieldset') : null;
            // The periodic shift-state poll calls this and would otherwise silently
            // undo the tour's own force-enable of this fieldset (see
            // applyTutorialFormFlag in approval-tour.blade.php) the next time it runs
            // while the tour is active.
            var effectiveLocked = !!rentalMutationsLocked && !window.__rentalTutorialMode;
            if (fieldset) {
                fieldset.disabled = effectiveLocked;
                fieldset.classList.toggle('opacity-60', effectiveLocked);
                syncRentalFormLockedOverlay(form, effectiveLocked);
            }
            if (typeof syncCooldownApprovalOnlyUi === 'function') syncCooldownApprovalOnlyUi();
            if (typeof fetchOutfits === 'function') fetchOutfits();
        }

        // A disabled fieldset swallows clicks entirely, so the locked create form
        // gives no feedback. Overlay it and explain why on click/tap.
        function syncRentalFormLockedOverlay(form, locked) {
            var overlay = document.getElementById('rentalFormLockedOverlay');
            if (!locked) {
                if (overlay) overlay.remove();
                return;
            }
            if (overlay) return;
            if (getComputedStyle(form).position === 'static') {
                form.style.position = 'relative';
            }
            overlay = document.createElement('div');
            overlay.id = 'rentalFormLockedOverlay';
            overlay.className = 'absolute inset-0 z-10 cursor-not-allowed';
            overlay.setAttribute('aria-hidden', 'true');
            overlay.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (window.rentalAlert) {
                    window.rentalAlert('Open a shift first. Creating rents and requesting cool-down approvals are paused while no shift is open.', { icon: 'warning', title: 'Shift required' });
                }
            });
            form.appendChild(overlay);
        }

        function refreshShiftControl() {
            var host = document.getElementById('shiftControlHost');
            if (!host) return;
            fetch('/rentals/shift-control-fragment?' + (allStoresMode ? 'all_stores=1&' : '') + '_=' + Date.now(), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
                credentials: 'same-origin'
            }).then(function(r) {
                return r.json().then(function(d) { return { ok: r.ok, d: d }; }).catch(function() { return { ok: r.ok, d: {} }; });
            }).then(function(res) {
                if (!res.ok || !res.d || !res.d.ok) return;
                if (typeof res.d.html === 'string') {
                    // A guided tour (shift tour especially) can be spotlighting a real
                    // button inside this exact fragment right now, with a click
                    // listener attached to that specific DOM node — replacing the
                    // markup out from under it via innerHTML silently orphans that
                    // listener (the new nodes look identical but aren't the same
                    // elements), so the user's very next click on it does nothing.
                    // Skip this refresh while any tour overlay is open; it'll just
                    // run again next time the list reloads.
                    var tourOpen = document.querySelector('[id$="TourOverlay"]:not(.hidden)');
                    if (tourOpen) return;
                    host.innerHTML = res.d.html;
                }
                if (typeof res.d.rental_mutations_locked === 'boolean') {
                    rentalMutationsLocked = res.d.rental_mutations_locked;
                    syncRentalMutationsLockedUi();
                }
            }).catch(function() {});
        }

        function clearCreateCartAndRefreshOutfits() {
            cart = {};
            renderCart();
            if (typeof fetchOutfits === 'function') {
                fetchOutfits();
            }
        }

        function resetCreateRentalFormAfterSuccess() {
            var form = document.getElementById('rentalForm');
            if (!form) return;
            clearCreateErrorSummary();
            cart = {};
            renderCart();
            [['customer_name'], ['phone'], ['instagram'], ['notes']].forEach(function(pair) {
                var el = form.querySelector('[name="' + pair[0] + '"]');
                if (el) el.value = '';
            });
            var rsdEl = document.getElementById('rsd');
            var redEl = document.getElementById('red');
            if (rsdEl) rsdEl.value = '';
            if (redEl) {
                redEl.value = '';
                redEl.removeAttribute('min');
            }
            var discEl = document.getElementById('discount');
            if (discEl) discEl.value = '';
            var dtEl = document.getElementById('discountType');
            if (dtEl) dtEl.value = 'fixed';
            var discNoteEl = document.getElementById('discountNote');
            if (discNoteEl) discNoteEl.value = '';
            var pmSel = document.getElementById('createPaymentMethod');
            if (pmSel) pmSel.value = '';
            var apField = document.getElementById('amountPaid');
            if (apField) apField.value = '';
            var ckFull = document.getElementById('createPaymentKindFull');
            var ckDown = document.getElementById('createPaymentKindDown');
            if (ckFull) ckFull.checked = true;
            if (ckDown) ckDown.checked = false;
            syncCreatePaymentKindUi();
            var ci = document.getElementById('conflictInfo');
            if (ci) ci.classList.add('hidden');
            var searchEl = document.getElementById('outfitSearch');
            if (searchEl) searchEl.value = '';
            var qai = document.getElementById('quickAddCreateInput');
            var qan = document.getElementById('quickAddCreateNewCode');
            var qad = document.getElementById('quickAddCreateDescription');
            if (qai) qai.value = '';
            if (qan) qan.value = '';
            if (qad) qad.value = '';
            var qPanel = document.getElementById('quickAddCreatePanel');
            if (qPanel) qPanel.classList.add('hidden');
            syncOutfitSearchClearVisible();
            if (typeof fetchOutfits === 'function') fetchOutfits();
            syncCooldownApprovalOnlyUi();
        }

        function appendMyPendingApprovalRow(summary) {
            if (!summary) return;
            var section = document.getElementById('my-pending-approvals-section');
            var list = document.getElementById('my-pending-approvals-list');
            var countEl = document.getElementById('my-pending-approvals-count');
            if (!section || !list || !countEl) return;
            section.classList.remove('hidden');
            var li = document.createElement('li');
            li.className = 'border-t border-gray-100 pt-2';
            if (summary.approval_id != null) {
                li.setAttribute('data-my-pending-approval', String(summary.approval_id));
            }
            var sumKind = summary.kind || 'create';
            li.setAttribute('data-pending-kind', sumKind);
            if (sumKind === 'edit' && summary.rental_id != null) {
                li.setAttribute('data-pending-rental-id', String(summary.rental_id));
            }
            var allC = Array.isArray(summary.outfit_codes) ? summary.outfit_codes : [];
            var focusC = Array.isArray(summary.approval_focus_outfit_codes) && summary.approval_focus_outfit_codes.length
                ? summary.approval_focus_outfit_codes
                : allC;
            var others = [];
            if (Array.isArray(summary.approval_focus_outfit_codes) && summary.approval_focus_outfit_codes.length && allC.length > summary.approval_focus_outfit_codes.length) {
                var fs = {};
                summary.approval_focus_outfit_codes.forEach(function(x) { fs[x] = true; });
                allC.forEach(function(x) { if (!fs[x]) others.push(x); });
            }
            var codesHtml = focusC.length
                ? '<span class="block text-gray-800 mt-0.5 font-mono text-xs">' + focusC.map(escRentalHtml).join(', ') + '</span>'
                    + (others.length ? '<span class="block text-gray-500 mt-0.5 text-xs">Also includes: ' + others.map(escRentalHtml).join(', ') + '</span>' : '')
                : '';
            var isEdit = sumKind === 'edit' && summary.rental_id != null;
            var label = isEdit
                ? '<span class="font-medium text-amber-900">Quick edit pending</span>'
                    + '<span class="block text-xs text-gray-600">Rent #' + escRentalHtml(String(summary.rental_id)) + ' — updates apply after approval</span>'
                : (summary.items_only
                    ? '<span class="font-medium text-amber-900">Items &amp; dates only</span>'
                    : '<span class="font-medium">' + escRentalHtml(summary.customer_name || '—') + '</span>' +
                        (summary.phone ? '<span class="text-gray-600"> · ' + escRentalHtml(summary.phone) + '</span>' : ''));
            li.innerHTML = label + codesHtml +
                '<span class="block text-gray-600 mt-0.5">' +
                escRentalHtml(summary.start_date || '') + '–' + escRentalHtml(summary.end_date || '') + ' · just now</span>';
            list.appendChild(li);
            var n = parseInt(countEl.textContent, 10);
            countEl.textContent = String((Number.isNaN(n) ? 0 : n) + 1);
        }

        function submitCreateCooldownApprovalRequest() {
            if (rentalMutationsLocked || rentalCooldownApprovalBypass) return;
            clearCreateErrorSummary();
            if (!sd() || !ed()) {
                showCreateErrorSummary(['Set start and end dates first.']);
                return;
            }
            var ids = Object.keys(cart);
            if (!ids.length) {
                showCreateErrorSummary(['Add at least one cool-down item to the cart.']);
                return;
            }
            var btn = document.getElementById('btnCooldownApprovalOnly');
            var url = btn ? btn.getAttribute('data-action-url') : null;
            if (!url) {
                showCreateErrorSummary(['Approval is not available. Refresh the page and try again.']);
                return;
            }
            var fd = new FormData();
            fd.append('_token', getRentalsCsrfToken());
            ids.forEach(function(k) {
                var item = cart[k];
                fd.append('outfit_ids[]', item.id);
                if (_pricingEnabled) {
                    fd.append('item_discounts[' + item.id + ']', item.discount || '');
                    fd.append('item_discount_types[' + item.id + ']', item.discountType || 'fixed');
                    fd.append('item_discount_notes[' + item.id + ']', item.discountNote || '');
                }
            });
            fd.append('start_date', sd());
            fd.append('end_date', ed());
            // Carry whatever customer details staff already typed so the manager
            // sees them and approval doesn't create a placeholder unnecessarily.
            var createForm = document.getElementById('rentalForm');
            ['customer_name', 'phone', 'instagram', 'notes'].forEach(function(name) {
                var el = createForm ? createForm.querySelector('[name="' + name + '"]') : null;
                fd.append(name, el && el.value ? el.value : '');
            });
            // Carry Payment & Settlement too so the server validates it like a
            // normal create (full payment must cover the total due) and the
            // approved rent keeps the payment staff entered.
            if (_pricingEnabled && createForm) {
                ['payment_method', 'amount_paid', 'discount', 'discount_type', 'discount_note', 'extra_charge'].forEach(function(name) {
                    var el = createForm.querySelector('[name="' + name + '"]');
                    if (el && el.value) fd.append(name, el.value);
                });
                var pk = createForm.querySelector('[name="payment_kind"]:checked');
                if (pk && pk.value) fd.append('payment_kind', pk.value);
            }
            fetch(url, {
                method: 'POST',
                body: fd,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getRentalsCsrfToken()
                },
                credentials: 'same-origin'
            }).then(function(r) {
                return r.text().then(function(text) {
                    var data = {};
                    try {
                        data = text ? JSON.parse(text) : {};
                    } catch (e) {
                        data = { message: text ? String(text).slice(0, 240) : 'Invalid response' };
                    }
                    return { r: r, data: data };
                });
            }).then(function(res) {
                if (res.r.ok && res.data && res.data.ok) {
                    if (res.data.mode === 'approval_request_sent') {
                        showRentalsListToast('Request sent to manager. Complete customer details after approval (Edit).', 'warning');
                        resetCreateRentalFormAfterSuccess();
                        appendMyPendingApprovalRow(res.data.pending_summary);
                        if (typeof loadRentals === 'function') loadRentals(1);
                    } else if (res.data.mode === 'approval_duplicate') {
                        showRentalsListToast('You already have a pending approval for these dates and items.', 'warning');
                    }
                    return;
                }
                var msgs = [];
                if (res.data && res.data.errors) {
                    Object.keys(res.data.errors).forEach(function(k) {
                        var v = res.data.errors[k];
                        if (Array.isArray(v)) msgs = msgs.concat(v);
                        else msgs.push(String(v));
                    });
                }
                if (res.data && res.data.message) msgs.push(res.data.message);
                if (msgs.length) showCreateErrorSummary(msgs);
                else showCreateErrorSummary(['Could not send approval request.']);
            }).catch(function() {
                showCreateErrorSummary(['Network error. Try again.']);
            });
        }

        (function() {
            var btn = document.getElementById('btnCooldownApprovalOnly');
            if (!btn) return;
            btn.addEventListener('click', function() {
                if (rentalMutationsLocked || btn.disabled) return;
                submitCreateCooldownApprovalRequest();
            });
        })();

        function rentalActionErrorMessage(d) {
            if (!d) return 'Something went wrong.';
            if (d.message) return d.message;
            if (d.errors) {
                var msgs = [];
                Object.keys(d.errors).forEach(function(k) {
                    var v = d.errors[k];
                    if (Array.isArray(v)) msgs = msgs.concat(v);
                    else msgs.push(String(v));
                });
                if (msgs.length) return msgs.join(' ');
            }
            return 'Action failed.';
        }

        function quickDeleteRental(id) {
            if (!mayDeleteRentals) {
                showRentalsListToast('Your account cannot delete rents. Ask management.', 'error');
                return;
            }
            if (rentalMutationsLocked && mayOperateRentTransactions) {
                showRentalsListToast('Open a shift before deleting rents.', 'error');
                return;
            }
            window.rentalConfirm('Delete this rent permanently? This cannot be undone.', {
                title: 'Delete rent?',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel',
            }).then(function(ok) {
                if (!ok) {
                    return;
                }
                sendRentalDelete(id, null);
            });
        }

        var currentRentalPickedUp = false;

        // A pickup/return that happened but was never clicked: report when and who
        // handled it; a supervisor/management approves before anything changes.
        function requestLateDone(id) {
            var pad = function(n) { return (n < 10 ? '0' : '') + n; };
            var d = new Date();
            var nowLocal = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
            var staffOpts = (rentalStoreStaff || []).map(function(u) {
                return '<option value="' + u.id + '"' + (u.id === currentUserId ? ' selected' : '') + '>' + escRentalHtml(u.name) + '</option>';
            }).join('');
            var row = 'display:flex;flex-direction:column;gap:4px;text-align:left;font-size:13px;margin-top:10px';
            var inp = 'border:1px solid #d1d5db;border-radius:6px;padding:6px 8px;font-size:14px';
            var pickupHtml = currentRentalPickedUp
                ? '<p style="text-align:left;font-size:13px;color:#6b7280;margin-top:10px">Already marked as picked up.</p>'
                : '<label style="' + row + '">Picked up at<input type="datetime-local" id="ldPickedAt" max="' + nowLocal + '" style="' + inp + '"></label>'
                  + '<label style="' + row + '">Handed over by<select id="ldPickedBy" style="' + inp + '">' + staffOpts + '</select></label>';
            // In-app tour: the tour card sits under this dialog's backdrop, so carry its
            // guidance into the dialog, and on wide screens keep the card visible beside it.
            var inTour = !!window.__rentalTutorialMode;
            var tourCard = inTour ? document.querySelector('[id$="TourOverlay"]:not(.hidden)') : null;
            var restoreTourCard = function() {
                if (tourCard && tourCard.dataset.prevZ !== undefined) {
                    tourCard.style.zIndex = tourCard.dataset.prevZ;
                    delete tourCard.dataset.prevZ;
                }
            };
            restoreTourCard(); // a previous dialog closed without its close hook
            var wide = window.innerWidth >= 1100;
            var endEl = document.getElementById('editEd');
            var tourNote = inTour
                ? '<div style="text-align:left;font-size:13px;line-height:1.45;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;border-radius:8px;padding:8px 10px;margin-bottom:6px">'
                  + '<b>Practice:</b> choose <b>Picked up and returned</b>, fill in the pickup &amp; return times, and who handed over / received. '
                  + 'Enter the return date as <b>' + escRentalHtml(endEl && endEl.value ? new Date(endEl.value + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : 'the rental end date') + '</b> at the latest so the late fee is cleared.</div>'
                : '';
            Swal.fire({
                title: 'Report pickup / return',
                position: inTour && wide && tourCard ? 'center-start' : 'center',
                html: tourNote + '<p style="text-align:left;font-size:13px;color:#4b5563">For a pickup or return that really happened but was not clicked in time. Applied after a supervisor or management approves.</p>'
                    + '<label style="' + row + '">What happened<select id="ldType" style="' + inp + '">'
                    + (currentRentalPickedUp ? '' : '<option value="pickup">Picked up (not returned yet)</option>')
                    + '<option value="return" selected>' + (currentRentalPickedUp ? 'Returned' : 'Picked up and returned') + '</option></select></label>'
                    + pickupHtml
                    + '<div id="ldReturnBox"><label style="' + row + '">Returned at<input type="datetime-local" id="ldReturnedAt" max="' + nowLocal + '" style="' + inp + '"></label>'
                    + '<label style="' + row + '">Received back by<select id="ldReturnedBy" style="' + inp + '">' + staffOpts + '</select></label></div>'
                    + '<label style="' + row + '">Note (optional)<input type="text" id="ldNote" maxlength="300" style="' + inp + '" placeholder="e.g. forgot to click return"></label>',
                showCancelButton: true,
                confirmButtonText: 'Send for approval',
                cancelButtonText: 'Cancel',
                didOpen: function() {
                    if (tourCard && wide) {
                        tourCard.dataset.prevZ = tourCard.style.zIndex || '';
                        tourCard.style.zIndex = '1070';
                    }
                    var t = document.getElementById('ldType');
                    var sync = function() { document.getElementById('ldReturnBox').style.display = t.value === 'return' ? '' : 'none'; };
                    t.addEventListener('change', sync);
                    sync();
                },
                willClose: restoreTourCard,
                preConfirm: function() {
                    var v = function(x) { var el = document.getElementById(x); return el ? el.value : ''; };
                    var out = { type: v('ldType'), picked_up_at: v('ldPickedAt'), picked_up_by: v('ldPickedBy'), returned_at: v('ldReturnedAt'), returned_by: v('ldReturnedBy'), note: v('ldNote') };
                    if (!currentRentalPickedUp && !out.picked_up_at) { Swal.showValidationMessage('Fill in when it was picked up.'); return false; }
                    if (out.type === 'return' && !out.returned_at) { Swal.showValidationMessage('Fill in when it was returned.'); return false; }
                    if (out.type === 'return' && out.picked_up_at && out.returned_at < out.picked_up_at) { Swal.showValidationMessage('Return cannot be before pickup.'); return false; }
                    return out;
                },
            }).then(function(res) {
                restoreTourCard();
                if (!res.isConfirmed) return;
                var fd = new URLSearchParams();
                fd.append('_token', getRentalsCsrfToken());
                Object.keys(res.value).forEach(function(k) {
                    if (res.value[k] !== '' && !(k.indexOf('returned') === 0 && res.value.type !== 'return')) fd.append(k, res.value[k]);
                });
                var ldQs = [];
                if (allStoresMode) ldQs.push('all_stores=1');
                // Practice rent (in-app tour): its own id sequence, see the {rental} binding.
                if (window.__rentalTutorialMode) ldQs.push('tutorial=1');
                fetch('/rentals/' + id + '/late-done-request' + (ldQs.length ? '?' + ldQs.join('&') : ''), {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: fd.toString(),
                })
                    .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, d: d }; }); })
                    .then(function(out) {
                        if (out.ok && out.d && out.d.ok) {
                            showRentalsListToast(out.d.message || 'Report sent.', 'success');
                            document.dispatchEvent(new CustomEvent('rental:late-done-sent', { detail: { approvalId: out.d.approval_id } }));
                            var modal = document.getElementById('editModal');
                            if (modal && !modal.classList.contains('hidden') && typeof closeModal === 'function') closeModal();
                            loadRentals();
                        } else {
                            showRentalsListToast(rentalActionErrorMessage(out.d), 'error');
                        }
                    })
                    .catch(function() { showRentalsListToast('Request failed. Please try again.', 'error'); });
            });
        }

        // reason !== null re-sends as "wrong input" (paid rent typed in by mistake).
        function sendRentalDelete(id, reason) {
            var fd = new URLSearchParams();
            fd.append('_token', getRentalsCsrfToken());
            fd.append('_method', 'DELETE');
            if (reason !== null) {
                fd.append('wrong_input', '1');
                fd.append('reason', reason);
            }
            var url = '/rentals/' + id + (allStoresMode ? '?all_stores=1' : '');
            fetch(url, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: fd.toString(),
            })
                .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, d: d }; }); })
                .then(function(res) {
                    if (res.ok && res.d && res.d.ok) {
                        showRentalsListToast(res.d.message || 'Rent deleted.', 'success');
                        loadRentals();
                    } else if (reason === null && res.d && res.d.needs_wrong_input) {
                        askWrongInputDelete(id, res.d.paid);
                    } else {
                        showRentalsListToast(rentalActionErrorMessage(res.d), 'error');
                    }
                })
                .catch(function() { showRentalsListToast('Delete failed. Please try again.', 'error'); });
        }

        // Paid rent: only deletable as a mistake (the money never came in). A real
        // customer cancellation goes through Cancel (kept or refunded) instead.
        function askWrongInputDelete(id, paid) {
            Swal.fire({
                title: 'This rent is already paid',
                html: '<div style="text-align:left;font-size:14px">Rp ' + Number(paid || 0).toLocaleString('id-ID')
                    + ' is recorded as paid.<br><br><b>Entered by mistake?</b> (that money was never received) Delete it as wrong input: the payments are voided and removed from the shift and cash.<br><br>'
                    + 'If the customer really cancelled, use <b>Cancel</b> instead.</div>',
                input: 'textarea',
                inputLabel: 'Why was it a mistake? (required)',
                inputPlaceholder: 'e.g. duplicate of #102, wrong items',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Delete as wrong input',
                cancelButtonText: 'Back',
                inputValidator: function(v) {
                    if (!v || v.trim().length < 3) return 'Please write the reason.';
                },
            }).then(function(r) {
                if (r.isConfirmed) sendRentalDelete(id, r.value.trim());
            });
        }

        // Free rent = bill Rp 0. Only a request: a supervisor/management (not the
        // requester) approves it on the approval queue; the reason is logged.
        function requestFreeRental(id) {
            Swal.fire({
                title: 'Request free rent?',
                text: 'The rent becomes Rp 0 after a supervisor or management approves. Money already paid is refunded to the customer and marked as FREE.',
                input: 'textarea',
                inputLabel: 'Reason (required)',
                inputPlaceholder: 'e.g. endorsement, owner family, compensation…',
                showCancelButton: true,
                confirmButtonText: 'Send request',
                cancelButtonText: 'Cancel',
                inputValidator: function(v) {
                    if (!v || v.trim().length < 3) return 'Please write the reason.';
                },
            }).then(function(res) {
                if (!res.isConfirmed) return;
                var fd = new URLSearchParams();
                fd.append('_token', getRentalsCsrfToken());
                fd.append('reason', res.value.trim());
                fetch('/rentals/' + id + '/free-request' + (allStoresMode ? '?all_stores=1' + rentalTutorialQs('&') : rentalTutorialQs()), {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: fd.toString(),
                })
                    .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, d: d }; }); })
                    .then(function(out) {
                        if (out.ok && out.d && out.d.ok) {
                            showRentalsListToast(out.d.message || 'Free request sent.', 'success');
                            var modal = document.getElementById('editModal');
                            if (modal && !modal.classList.contains('hidden') && typeof closeModal === 'function') closeModal();
                            loadRentals();
                        } else {
                            showRentalsListToast(rentalActionErrorMessage(out.d), 'error');
                        }
                    })
                    .catch(function() { showRentalsListToast('Request failed. Please try again.', 'error'); });
            });
        }

        function rentalCardActionLinks(r) {
            return (r && r.actions_html) ? r.actions_html : '';
        }

        function renderPendingApprovalCard(r) {
            return (r && r.card_html) ? r.card_html : '';
        }

        function renderRentalCard(r) {
            return (r && r.card_html) ? r.card_html : '';
        }

        /** Deleted-transactions mode: wrap each card with a selection checkbox and deleted-at badge. */
        function renderDeletedRentalCard(r) {
            var inner = renderRentalCard(r);
            if (!inner) return '';
            var id = Number(r && r.id) || 0;
            var deletedAt = (r && r.deleted_at) ? String(r.deleted_at) : '';
            return '<div class="relative rounded-xl border border-red-200" data-deleted-rental-id="' + id + '">'
                + '<div class="flex items-center justify-between gap-2 rounded-t-xl border-b border-red-200 bg-red-50 px-3 py-2">'
                + '<label class="flex items-center gap-2 text-sm font-medium text-red-900 cursor-pointer">'
                + '<input type="checkbox" class="deleted-rental-checkbox rounded border-gray-300 text-red-600 focus:ring-red-500" value="' + id + '">'
                + 'Select'
                + '</label>'
                + (deletedAt ? '<span class="text-xs font-semibold text-red-700">Deleted at ' + deletedAt.replace(/</g, '&lt;') + '</span>' : '')
                + '</div>'
                + inner
                + '</div>';
        }

        function rentalsDeletedSelectedIds() {
            return Array.prototype.slice.call(document.querySelectorAll('.deleted-rental-checkbox:checked'))
                .map(function (el) { return Number(el.value) || 0; })
                .filter(function (id) { return id > 0; });
        }

        function rentalsDeletedSyncToolbar() {
            var ids = rentalsDeletedSelectedIds();
            var btn = document.getElementById('deletedBulkDeleteBtn');
            if (btn) btn.disabled = ids.length === 0;
            var count = document.getElementById('deletedSelectedCount');
            if (count) count.textContent = ids.length > 0 ? '(' + ids.length + ' selected)' : '';
            var all = document.getElementById('deletedSelectAll');
            if (all) {
                var boxes = document.querySelectorAll('.deleted-rental-checkbox');
                all.checked = boxes.length > 0 && ids.length === boxes.length;
            }
        }

        function rentalsDeletedBindSelection() {
            document.querySelectorAll('.deleted-rental-checkbox').forEach(function (el) {
                el.addEventListener('change', rentalsDeletedSyncToolbar);
            });
            rentalsDeletedSyncToolbar();
        }
        window.renderDeletedRentalCard = renderDeletedRentalCard;
        window.rentalsDeletedBindSelection = rentalsDeletedBindSelection;
        window.rentalsDeletedMode = rentalsDeletedMode;

        function rentalsDeletedPost(url, body, confirmMsg) {
            if (!window.confirm(confirmMsg)) return;
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(body || {}),
            })
                .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
                .then(function (res) {
                    if (!res.ok || !res.data || res.data.ok !== true) {
                        showRentalsListToast((res.data && res.data.message) || 'Could not delete transactions.', 'error');
                        return;
                    }
                    showRentalsListToast('Permanently deleted ' + res.data.deleted + ' transaction(s).', 'success');
                    loadRentals(1);
                })
                .catch(function () {
                    showRentalsListToast('Could not delete transactions.', 'error');
                });
        }

        (function () {
            if (!rentalsDeletedMode) return;
            var all = document.getElementById('deletedSelectAll');
            if (all) {
                all.addEventListener('change', function () {
                    document.querySelectorAll('.deleted-rental-checkbox').forEach(function (el) {
                        el.checked = all.checked;
                    });
                    rentalsDeletedSyncToolbar();
                });
            }
            var bulkBtn = document.getElementById('deletedBulkDeleteBtn');
            if (bulkBtn) {
                bulkBtn.addEventListener('click', function () {
                    var ids = rentalsDeletedSelectedIds();
                    if (ids.length === 0) return;
                    rentalsDeletedPost(
                        __rentalsRouteDeletedBulkDestroy,
                        { ids: ids },
                        'Permanently delete ' + ids.length + ' selected transaction(s)? This cannot be undone.'
                    );
                });
            }
            var clearBtn = document.getElementById('deletedClearAllBtn');
            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    rentalsDeletedPost(
                        __rentalsRouteDeletedClear,
                        {},
                        'Permanently delete ALL deleted transactions? This cannot be undone.'
                    );
                });
            }
        })();

        var rentalsOfflineMode = false;

        function rentalsOfflineOptions(pageOverride) {
            var searchEl = document.getElementById('searchInput');
            var perPageEl = document.getElementById('perPage');
            var search = searchEl ? searchEl.value : '';
            var perPage = perPageEl ? perPageEl.value : '10';
            var page = pageOverride !== undefined ? pageOverride : currentPage;
            var opts = { search: search, per_page: perPage, page: page };
            if (typeof rentalDateFrom === 'string' && rentalDateFrom) opts.date_from = rentalDateFrom;
            if (typeof rentalDateTo === 'string' && rentalDateTo) opts.date_to = rentalDateTo;
            if (currentStatus === 'overdue') opts.overdue = true;
            else if (currentStatus === 'pickup_waiting') opts.pickup_waiting = true;
            else if (currentStatus === 'pickup_done') opts.pickup_done = true;
            else if (currentStatus === 'payment_down_payment') opts.payment_kind = 'down_payment';
            else if (currentStatus === 'payment_paid_off') opts.payment_kind = 'full';
            else if (currentStatus) opts.status = currentStatus;
            return opts;
        }

        function rentalsOfflinePaginationHtml(meta) {
            var page = Number(meta && meta.current_page) || 1;
            var last = Number(meta && meta.last_page) || 1;
            if (last <= 1) return '';
            var start = Math.max(1, page - 2);
            var end = Math.min(last, page + 2);
            if (end - start < 4) {
                start = Math.max(1, Math.min(start, end - 4));
                end = Math.min(last, Math.max(end, start + 4));
            }
            var html = '<div class="flex flex-wrap gap-2 justify-end">';
            var prevDisabled = page <= 1;
            var nextDisabled = page >= last;
            html += '<button type="button" ' + (prevDisabled ? 'disabled' : '') + ' onclick="loadRentalsOffline(' + (page - 1) + ')" class="px-3 py-1.5 text-sm rounded border ' + (prevDisabled ? 'border-gray-200 text-gray-400 bg-gray-50 cursor-not-allowed' : 'border-gray-300 bg-white hover:bg-gray-50') + '">Prev</button>';
            for (var p = start; p <= end; p++) {
                var active = p === page;
                html += '<button type="button" onclick="loadRentalsOffline(' + p + ')" class="px-3 py-1.5 text-sm rounded border ' + (active ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white hover:bg-gray-50') + '">' + p + '</button>';
            }
            html += '<button type="button" ' + (nextDisabled ? 'disabled' : '') + ' onclick="loadRentalsOffline(' + (page + 1) + ')" class="px-3 py-1.5 text-sm rounded border ' + (nextDisabled ? 'border-gray-200 text-gray-400 bg-gray-50 cursor-not-allowed' : 'border-gray-300 bg-white hover:bg-gray-50') + '">Next</button>';
            html += '</div>';
            return html;
        }

        function loadRentalsOffline(page) {
            if (page !== undefined) currentPage = page;
            var api = window.rentalOfflineRentals;
            if (!api || typeof api.search !== 'function') {
                showRentalsListToast('Offline storage is not available in this browser.', 'error');
                return;
            }
            rentalsOfflineMode = true;
            var reqSeq = (window.__rentalRentalsListSeq = (window.__rentalRentalsListSeq || 0) + 1);
            var opts = rentalsOfflineOptions(currentPage);
            api.search(opts).then(function(data) {
                if (reqSeq !== window.__rentalRentalsListSeq) return;
                var cards = document.getElementById('rentalCards');
                if (!cards) return;
                cards.innerHTML = '';
                var rows = Array.isArray(data.data) ? data.data : [];
                if (rows.length === 0) {
                    cards.innerHTML = rentalsListEmptyHtml;
                } else {
                    rows.forEach(function(r) {
                        cards.innerHTML += renderRentalCard(r);
                    });
                }
                syncOverdueDurationLabels();
                var linkDiv = document.getElementById('paginationLinks');
                var infoDiv = document.getElementById('paginationInfo');
                var countSpan = document.getElementById('resultCount');
                if (linkDiv) linkDiv.innerHTML = rentalsOfflinePaginationHtml(data);
                var from = data.from || 0, to = data.to || 0, total = data.total || 0;
                if (infoDiv) infoDiv.textContent = total > 0 ? `Showing ${from}-${to} of ${total} (offline)` : 'Offline (no cached data yet)';
                if (countSpan) countSpan.textContent = total > 0 ? `(${total} results)` : '';
                if (api && typeof api.meta === 'function') {
                    api.meta('rentals_last_full_sync_at').then(function(ts) {
                        if (ts) showRentalsListToast('Offline mode. Last synced: ' + ts, 'warning');
                        else showRentalsListToast('Offline mode. Offline database not synced yet — reconnect and open Rentals to sync.', 'warning');
                    });
                } else {
                    showRentalsListToast('Offline mode.', 'warning');
                }
            }).catch(function() {
                showRentalsListToast('Offline search failed.', 'error');
            });
        }
        window.loadRentalsOffline = loadRentalsOffline;

        function rentalsFormatSyncAge(iso) {
            var ms = Date.parse(String(iso || ''));
            if (!Number.isFinite(ms)) return '';
            var diff = Math.max(0, Date.now() - ms);
            var mins = Math.floor(diff / 60000);
            if (mins < 1) return 'just now';
            if (mins < 60) return mins + 'm ago';
            var hrs = Math.floor(mins / 60);
            if (hrs < 24) return hrs + 'h ago';
            var days = Math.floor(hrs / 24);
            return days + 'd ago';
        }

        function rentalsSetOfflineSyncIndicator(text, theme) {
            var el = document.getElementById('offlineSyncIndicator');
            if (!el) return;
            el.textContent = text || '';
            el.classList.remove('hidden');
            el.className = 'inline-flex w-fit justify-self-start md:justify-self-end text-[11px] leading-4 px-2 py-1 rounded-full border whitespace-nowrap ' + theme;
        }

        function rentalsUpdateOfflineSyncIndicator() {
            var el = document.getElementById('offlineSyncIndicator');
            if (!el) return;
            var api = window.rentalOfflineRentals;
            if (!api || typeof api.meta !== 'function') {
                el.classList.add('hidden');
                return;
            }

            var online = (typeof navigator !== 'undefined' && navigator.onLine === false) ? false : true;
            var swCtrl = !!(navigator.serviceWorker && navigator.serviceWorker.controller);
            Promise.all([
                api.meta('rentals_sync_in_progress'),
                api.meta('rentals_last_full_sync_at'),
                api.meta('rentals_full_sync_next_page'),
                api.meta('rentals_full_sync_last_page'),
                api.meta('rentals_full_sync_total'),
            ]).then(function(values) {
                var syncing = values[0] === true || values[0] === 'true';
                var lastAt = values[1];
                var nextPage = parseInt(values[2], 10);
                var lastPage = parseInt(values[3], 10);
                var total = parseInt(values[4], 10);
                var parts = [];
                parts.push('Offline DB');
                if (syncing) {
                    var currentDone = Number.isFinite(nextPage) ? Math.max(0, nextPage - 1) : 0;
                    var maxPages = Number.isFinite(lastPage) ? lastPage : 0;
                    parts.push('syncing' + (maxPages > 0 ? (' ' + currentDone + '/' + maxPages) : ''));
                    if (Number.isFinite(total) && total > 0) parts.push(total + ' rows');
                    if (!online) parts.push('offline');
                    if (!swCtrl) parts.push('no-sw');
                    rentalsSetOfflineSyncIndicator(parts.join(' · '), 'border-amber-300 bg-amber-50 text-amber-950');
                    return;
                }
                if (lastAt) {
                    var age = rentalsFormatSyncAge(lastAt);
                    parts.push('ready');
                    if (age) parts.push(age);
                    if (!online) parts.push('offline');
                    if (!swCtrl) parts.push('no-sw');
                    rentalsSetOfflineSyncIndicator(parts.join(' · '), (online ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-indigo-200 bg-indigo-50 text-indigo-900'));
                    return;
                }
                parts.push('not-synced');
                if (!online) parts.push('offline');
                if (!swCtrl) parts.push('no-sw');
                rentalsSetOfflineSyncIndicator(parts.join(' · '), 'border-gray-300 bg-gray-50 text-gray-700');
            }).catch(function() {
                rentalsSetOfflineSyncIndicator('Offline DB · unknown', 'border-gray-300 bg-gray-50 text-gray-700');
            });
        }

        setInterval(rentalsUpdateOfflineSyncIndicator, 3000);
        window.addEventListener('online', rentalsUpdateOfflineSyncIndicator);
        window.addEventListener('offline', rentalsUpdateOfflineSyncIndicator);

        function loadRentals(page) {
            const search = document.getElementById('searchInput').value;
            const perPage = document.getElementById('perPage').value;
            if (page !== undefined) currentPage = page;
            // Every filter change fires a fetch; responses can land out of order,
            // letting a stale result overwrite the latest filter. Only the newest
            // request (shared counter across online/offline loaders) may render.
            var reqSeq = (window.__rentalRentalsListSeq = (window.__rentalRentalsListSeq || 0) + 1);

            let url = `/rentals/search?per_page=${perPage}&page=${currentPage}&_=${Date.now()}${rentalsDebugTodayQuery()}`;
            if (allStoresMode) url += '&all_stores=1';
            if (rentalsDeletedMode) url += '&deleted=1';
            if (search) url += `&search=${encodeURIComponent(search)}`;
            if (typeof rentalDateFrom === 'string' && rentalDateFrom) url += `&date_from=${encodeURIComponent(rentalDateFrom)}`;
            if (typeof rentalDateTo === 'string' && rentalDateTo) url += `&date_to=${encodeURIComponent(rentalDateTo)}`;
            if (typeof rentalCreatedOn === 'string' && rentalCreatedOn) url += `&created_on=${encodeURIComponent(rentalCreatedOn)}`;
            if (currentStatus === 'overdue') url += '&overdue=1';
            else if (currentStatus === 'pickup_waiting') url += '&pickup_waiting=1';
            else if (currentStatus === 'pickup_done') url += '&pickup_done=1';
            else if (currentStatus === 'payment_down_payment') url += '&payment_kind=down_payment';
            else if (currentStatus === 'payment_paid_off') url += '&payment_kind=full';
            else if (currentStatus) url += `&status=${encodeURIComponent(currentStatus)}`;
            if (window.__rentalTutorialMode) url += '&tutorial=1';

            fetch(url, {
                cache: 'no-store',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(r => {
                    if (!r.ok) throw new Error('rentals search ' + r.status);
                    var ct = r.headers.get('content-type') || '';
                    if (ct && !ct.includes('application/json')) {
                        throw new Error('rentals search non-json');
                    }
                    return r.json();
                })
                .then(data => {
                    if (reqSeq !== window.__rentalRentalsListSeq) return;
                    rentalsOfflineMode = false;
                    const cards = document.getElementById('rentalCards');
                    cards.innerHTML = '';

                    var pending = Array.isArray(data.pending_approval_rows) ? data.pending_approval_rows : [];
                    pending.forEach(function(p) {
                        cards.innerHTML += renderPendingApprovalCard(p);
                    });

                    if (data.data.length === 0 && pending.length === 0) {
                        cards.innerHTML = rentalsListEmptyHtml;
                    } else {
                        data.data.forEach(r => {
                            if (rentalsDeletedMode) {
                                cards.innerHTML += renderDeletedRentalCard(r);
                            } else {
                                cards.innerHTML += renderRentalCard(r);
                            }
                        });
                    }
                    if (rentalsDeletedMode) rentalsDeletedBindSelection();
                    syncOverdueDurationLabels();

                    const linkDiv = document.getElementById('paginationLinks');
                    const infoDiv = document.getElementById('paginationInfo');
                    const countSpan = document.getElementById('resultCount');

                    linkDiv.innerHTML = data.pagination_links_html || '';
                    const from = data.from || 0, to = data.to || 0, total = data.total || 0;
                    infoDiv.textContent = total > 0 ? `Showing ${from}-${to} of ${total}` : '';
                    countSpan.textContent = total > 0 ? `(${total} results)` : '';
                    if (typeof window.rentalTryStartPendingApprovalPoll === 'function') {
                        window.rentalTryStartPendingApprovalPoll();
                    }
                    if (!rentalsDeletedMode && window.rentalOfflineRentals && typeof window.rentalOfflineRentals.upsertMany === 'function' && Array.isArray(data.data) && data.data.length) {
                        window.rentalOfflineRentals.upsertMany(data.data);
                    }
                    rentalsUpdateOfflineSyncIndicator();
                    refreshShiftControl();
                })
                .catch(function (err) {
                    if (reqSeq !== window.__rentalRentalsListSeq) return;
                    var offline = (typeof navigator !== 'undefined' && navigator.onLine === false);
                    if (offline && !rentalsDeletedMode && window.rentalOfflineRentals) {
                        loadRentalsOffline(currentPage);
                        return;
                    }
                    var cards = document.getElementById('rentalCards');
                    if (cards) cards.innerHTML = rentalsListLoadErrorHtml;
                    showRentalsListToast('Could not refresh rents list. Please reload the page.', 'error');
                });
        }
        window.loadRentals = loadRentals;

        function goToPage(page) {
            currentPage = page;
            if (rentalsOfflineMode) loadRentalsOffline(page);
            else loadRentals();
        }

        function ensureSwal(cb) {
            if (window.Swal) return cb();
            var s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
            s.onload = cb;
            document.head.appendChild(s);
        }

        function postRentalStatus(id, status, extra) {
            var fd = new FormData();
            fd.append('_token', getRentalsCsrfToken());
            fd.append('status', status);
            if (extra && extra.refund) { fd.append('refund', '1'); }
            return fetch('/rentals/' + id + '/status' + rentalTutorialQs(), {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: fd,
            }).then(function(r) {
                return r.json().then(function(d) { return { ok: r.ok, d: d }; }).catch(function() { return { ok: r.ok, d: {} }; });
            });
        }

        function postRentalPickup(id, pickedUp) {
            var fd = new FormData();
            fd.append('_token', getRentalsCsrfToken());
            fd.append('picked_up', pickedUp ? '1' : '0');
            return fetch('/rentals/' + id + '/pickup' + rentalTutorialQs(), {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: fd,
            }).then(function(r) {
                return r.json().then(function(d) { return { ok: r.ok, d: d }; }).catch(function() { return { ok: r.ok, d: {} }; });
            });
        }

        function updateStatus(id, status) {
            if (rentalMutationsLocked) {
                showRentalsListToast('Open a shift before changing rent status.', 'error');
                return;
            }
            var statusExtra = {};
            function run() {
                postRentalStatus(id, status, statusExtra).then(function(res) {
                    if (res.ok && res.d && res.d.ok) {
                        showRentalsListToast(res.d.message, 'success');
                        loadRentals();
                        refreshRentalCacheById(id);
                        var modal = document.getElementById('editModal');
                        if (modal && !modal.classList.contains('hidden') && currentRentalId && Number(currentRentalId) === Number(id)) {
                            reloadEditModalFromServer(id, null);
                        }
                    } else {
                        showRentalsListToast(rentalActionErrorMessage(res.d), 'error');
                    }
                }).catch(function() { showRentalsListToast('Update failed. Please try again.', 'error'); });
            }
            if (status === 'returned') {
                ensureSwal(function() {
                    Swal.fire({
                        title: 'Confirm return',
                        text: 'Mark this rent as returned?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, return',
                        cancelButtonText: 'Cancel'
                    }).then(function(result) { if (result.isConfirmed) run(); });
                });
                return;
            }
            if (status === 'cancelled') {
                ensureSwal(function() {
                    // By default the customer's money is kept (deposit forfeited). Management can
                    // instead refund it — booked back out of the account the customer paid with.
                    var refundBox = (typeof rentalMayRefundCancel !== 'undefined' && rentalMayRefundCancel)
                        ? '<label style="display:flex;gap:8px;align-items:flex-start;text-align:left;font-size:14px;margin-top:10px;">'
                            + '<input type="checkbox" id="cancelRefundCheck" style="margin-top:3px;">'
                            + '<span>Refund the money to the customer. If left unchecked, the money already paid is forfeited.</span></label>'
                        : '<p style="font-size:13px;margin-top:8px;color:#6b7280;">Money the customer has already paid is not refunded (forfeited).</p>';
                    Swal.fire({
                        title: 'Cancel this rental?',
                        html: '<div>The rent will be marked cancelled.</div>' + refundBox,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, cancel',
                        cancelButtonText: 'Back',
                        preConfirm: function() {
                            var box = document.getElementById('cancelRefundCheck');
                            statusExtra.refund = !!(box && box.checked);
                        }
                    }).then(function(result) { if (result.isConfirmed) run(); });
                });
                return;
            }
            if (status === 'active') {
                ensureSwal(function() {
                    Swal.fire({
                        title: 'Re-activate rental?',
                        text: 'Only possible if items are not double-booked.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Re-activate',
                        cancelButtonText: 'Cancel'
                    }).then(function(result) { if (result.isConfirmed) run(); });
                });
                return;
            }
            run();
        }

        function updatePickup(id, pickedUp) {
            if (rentalMutationsLocked) {
                showRentalsListToast('Open a shift before changing pickup status.', 'error');
                return;
            }
            ensureSwal(function() {
                Swal.fire({
                    title: pickedUp ? 'Confirm pickup' : 'Undo pickup',
                    text: pickedUp ? 'Mark this rent as picked up?' : 'Clear pickup status?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: pickedUp ? 'Yes, pickup' : 'Yes, undo',
                    cancelButtonText: 'Cancel'
                }).then(function(result) {
                    if (!result.isConfirmed) return;
                    postRentalPickup(id, pickedUp).then(function(res) {
                        if (res.ok && res.d && res.d.ok) {
                            showRentalsListToast(res.d.message, 'success');
                            loadRentals();
                            refreshRentalCacheById(id);
                            var modal = document.getElementById('editModal');
                            if (modal && !modal.classList.contains('hidden') && currentRentalId && Number(currentRentalId) === Number(id)) {
                                reloadEditModalFromServer(id, null);
                            }
                        } else {
                            showRentalsListToast(rentalActionErrorMessage(res.d), 'error');
                        }
                    }).catch(function() { showRentalsListToast('Update failed. Please try again.', 'error'); });
                });
            });
        }

        function openRentalById(id, practice) {
            fetch(`/rentals/search?id=${encodeURIComponent(id)}&per_page=1&page=1&_=${Date.now()}${rentalsDebugTodayQuery()}${practice ? '&tutorial=1' : ''}`, { cache: 'no-store' })
                .then(r => r.json())
                .then(data => {
                    var rental = (data && data.data && data.data[0]) ? data.data[0] : null;
                    if (rental) openEditModal(rental);
                });
        }

        function rentalRestoreEditModalAfterReceipt() {
            var raw = null;
            try { raw = sessionStorage.getItem('__RENTAL_RECEIPT_RETURN'); } catch (e) {}
            if (!raw) return;
            try { sessionStorage.removeItem('__RENTAL_RECEIPT_RETURN'); } catch (e) {}
            var payload = null;
            try { payload = JSON.parse(raw); } catch (e) {}
            var rid = payload && payload.rental_id ? Number(payload.rental_id) : 0;
            var t = payload && payload.t ? Number(payload.t) : 0;
            var y = payload && typeof payload.scroll_y === 'number' ? payload.scroll_y : null;
            if (!rid || !Number.isFinite(rid)) return;
            if (t && Number.isFinite(t) && (Date.now() - t) > (10 * 60 * 1000)) return;
            if (y !== null && Number.isFinite(y)) {
                try { window.scrollTo(0, y); } catch (e) {}
            }
            setTimeout(function() { openRentalById(rid); }, 50);
        }

        // Select a rental and load it into the edit modal/cart (used by "Select rental" buttons)
        function selectRentalIntoCart(id) {
            fetch(`/rentals/search?id=${encodeURIComponent(id)}&per_page=1&page=1&_=${Date.now()}${rentalsDebugTodayQuery()}`, { cache: 'no-store' })
                .then(r => r.json())
                .then(data => {
                    var rental = (data && data.data && data.data[0]) ? data.data[0] : null;
                    if (!rental) {
                        window.rentalAlert('Rent not found', { icon: 'error', title: 'Not found' });
                        return;
                    }
                    openEditModal(rental);

                    // After outfits are loaded, ensure editCart contains the rental items
                    setTimeout(function() {
                        if (typeof editCart !== 'undefined' && editAllOutfits && Array.isArray(rental.outfits)) {
                            rental.outfits.forEach(function(s) {
                                var rsid = rentalNumericOutfitId(s.id);
                                if (rsid === null) return;
                                var found = editAllOutfits.find(function(x) { return outfitsSameId(x.id, rsid); });
                                if (found) editCart[rsid] = editCartAssignFromOutfit(found);
                            });
                            renderEditCart();
                        }
                    }, 350);
                })
                .catch(function() { window.rentalAlert('Failed to load rental', { icon: 'error', title: 'Load failed' }); });
        }

        function wireSearchInputs() {
            if (window.rentalBindSearchField) {
                window.rentalBindSearchField({
                    input: 'outfitSearch',
                    clearButton: 'outfitSearchClear',
                    onInput: function() {
                        var el = document.getElementById('outfitSearch');
                        filterOutfitList(el ? el.value : '');
                    },
                    onClear: function() {
                        filterOutfitList('');
                    },
                });
            } else {
                var outfitSearch = document.getElementById('outfitSearch');
                if (outfitSearch) {
                    outfitSearch.addEventListener('input', function() {
                        syncOutfitSearchClearVisible();
                        filterOutfitList(outfitSearch.value);
                    });
                    outfitSearch.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                        }
                        if (e.key === 'Escape') {
                            e.preventDefault();
                            clearOutfitSearchInput();
                        }
                    });
                }
                var outfitSearchClear = document.getElementById('outfitSearchClear');
                if (outfitSearchClear) {
                    outfitSearchClear.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        clearOutfitSearchInput();
                    });
                }
                syncOutfitSearchClearVisible();
            }

            if (window.rentalBindSearchField) {
                window.rentalBindSearchField({
                    input: 'editOutfitSearch',
                    clearButton: 'editOutfitSearchClear',
                    onInput: function() {
                        var inputEl = document.getElementById('editOutfitSearch');
                        if (!inputEl) return;
                        filterOutfitCheckboxes('editOutfitCheckboxList', inputEl.value);
                    },
                    onClear: function() {
                        filterOutfitCheckboxes('editOutfitCheckboxList', '');
                    },
                });
            } else {
                var editOutfitSearch = document.getElementById('editOutfitSearch');
                if (editOutfitSearch) {
                    editOutfitSearch.addEventListener('input', function() {
                        syncEditOutfitSearchClearVisible();
                        filterOutfitCheckboxes('editOutfitCheckboxList', editOutfitSearch.value || '');
                    });
                    editOutfitSearch.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                        }
                        if (e.key === 'Escape') {
                            e.preventDefault();
                            clearEditOutfitSearchInput();
                        }
                    });
                }
                var editOutfitSearchClear = document.getElementById('editOutfitSearchClear');
                if (editOutfitSearchClear) {
                    editOutfitSearchClear.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        clearEditOutfitSearchInput();
                    });
                }
                syncEditOutfitSearchClearVisible();
            }

            if (window.rentalBindSearchField) {
                window.rentalBindSearchField({
                    input: 'searchInput',
                    clearButton: 'searchInputClear',
                    preventEnter: false,
                    onInput: function() {
                        clearTimeout(searchTimer);
                        searchTimer = setTimeout(function() {
                            if (typeof rentalsOfflineMode !== 'undefined' && rentalsOfflineMode) loadRentalsOffline(currentPage);
                            else loadRentals();
                        }, 300);
                    },
                    onEnter: function() {
                        clearTimeout(searchTimer);
                        if (typeof rentalsOfflineMode !== 'undefined' && rentalsOfflineMode) loadRentalsOffline(currentPage);
                        else loadRentals();
                    },
                    onEscape: function() {
                        clearRentalsListSearchInput();
                    },
                    onClear: function() {
                        clearTimeout(searchTimer);
                        if (typeof rentalsOfflineMode !== 'undefined' && rentalsOfflineMode) loadRentalsOffline(currentPage);
                        else loadRentals();
                    },
                });
                return;
            }

            var searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimer);
                    searchTimer = setTimeout(function() {
                        if (typeof rentalsOfflineMode !== 'undefined' && rentalsOfflineMode) loadRentalsOffline(currentPage);
                        else loadRentals();
                    }, 300);
                    syncRentalsListSearchClearVisible();
                });
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        clearTimeout(searchTimer);
                        if (typeof rentalsOfflineMode !== 'undefined' && rentalsOfflineMode) loadRentalsOffline(currentPage);
                        else loadRentals();
                    }
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        clearRentalsListSearchInput();
                    }
                });
            }
            var clearBtn = document.getElementById('searchInputClear');
            if (clearBtn) {
                clearBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    clearRentalsListSearchInput();
                });
            }
            syncRentalsListSearchClearVisible();
        }

        wireSearchInputs();

        var perPageEl = document.getElementById('perPage');
        if (perPageEl) perPageEl.addEventListener('change', function() {
            if (typeof rentalsOfflineMode !== 'undefined' && rentalsOfflineMode) loadRentalsOffline(1);
            else loadRentals(1);
        });

        var urlPage = new URLSearchParams(window.location.search).get('page');
        if (urlPage) currentPage = parseInt(urlPage);
        loadRentals();
        rentalRestoreEditModalAfterReceipt();

        (function() {
            if (window.__RENTAL_RENTALS_OFFLINE_SYNC_STARTED) return;
            if (!window.rentalOfflineRentals || typeof window.rentalOfflineRentals.fullSync !== 'function') return;
            window.__RENTAL_RENTALS_OFFLINE_SYNC_STARTED = true;
            var metaFn = window.rentalOfflineRentals.meta;
            if (typeof metaFn !== 'function') return;
            metaFn('rentals_last_full_sync_at').then(function(lastAt) {
                var ms = Date.parse(String(lastAt || ''));
                var should = !Number.isFinite(ms) || (Date.now() - ms) > (10 * 60 * 1000);
                if (!should) return;
                var progress = { page: 0, last: 0, total: 0, upserted: 0 };
                var t = null;
                function tick() {
                    var p = Number(progress.page) || 0;
                    var l = Number(progress.last) || 0;
                    var label = (p > 0 && l > 0) ? ('Syncing offline rentals… ' + p + '/' + l) : 'Syncing offline rentals…';
                    showRentalsListToast(label, 'warning');
                }
                tick();
                t = setInterval(tick, 4000);
                window.rentalOfflineRentals.fullSync({
                    all_stores: allStoresMode,
                    debug_today_query: rentalsDebugTodayQuery(),
                    onProgress: function(p) {
                        if (!p) return;
                        progress = p;
                    }
                }).then(function(res) {
                    if (t) clearInterval(t);
                    if (res && res.ok) {
                        showRentalsListToast('Offline rentals updated. ' + (res.upserted || 0) + ' rows cached.', 'success');
                        if (rentalsOfflineMode) loadRentalsOffline(currentPage);
                    } else {
                        showRentalsListToast('Offline sync failed (server unreachable).', 'warning');
                    }
                }).catch(function() {
                    if (t) clearInterval(t);
                    showRentalsListToast('Offline sync failed (server unreachable).', 'warning');
                });
            });
        })();

        var editId = new URLSearchParams(window.location.search).get('edit');
        if (editId) {
            openRentalById(editId, new URLSearchParams(window.location.search).get('tutorial') === '1');
        }

        // Approval pending-snapshot: poll every 5s when this page should watch the queue.
        var RENTALS_APPROVAL_POLL_MS = 5000;
        var rentalsQueueFragmentUrl = __rentalsRouteQueueFragment;
        var pendingPollTimer = null;
        var pendingLast = { count: null, latest_id: null };

        function rentalsIsUserInteracting() {
            var editModal = document.getElementById('editModal');
            if (editModal && !editModal.classList.contains('hidden')) return true;
            var el = document.activeElement;
            if (!el) return false;
            var tag = (el.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
            if (el.isContentEditable) return true;
            return false;
        }

        function scheduleNextRentalsPendingPoll() {
            schedulePendingPoll(RENTALS_APPROVAL_POLL_MS);
        }

        function hasAnyPendingUi() {
            if (document.querySelector('[data-rentals-awaiting-approval-flash]')) return true;
            if (document.querySelector('[data-my-pending-approval]')) return true;
            if (document.querySelector('[data-pending-approval-list-row]')) return true;
            if (document.querySelector('[data-pending-approvals-badge]')) return true;
            if (document.getElementById('manager-pending-approvals-inline')) return true;
            if (document.getElementById('manager-pending-approvals-dock')) return true;
            if (document.querySelector('[data-approval-card]')) return true;
            return false;
        }

        /** Drop stale rows / hide section when manager approves on another device (uses my_pending_ids from poll). */
        function syncMyPendingApprovalsFromSnapshot(s) {
            var section = document.getElementById('my-pending-approvals-section');
            var list = document.getElementById('my-pending-approvals-list');
            var countEl = document.getElementById('my-pending-approvals-count');
            if (!section || !list || !countEl) return;
            if (!s || !Array.isArray(s.my_pending_ids)) return;
            var idSet = {};
            s.my_pending_ids.forEach(function(x) { idSet[Number(x)] = true; });
            list.querySelectorAll('[data-my-pending-approval]').forEach(function(li) {
                var aid = parseInt(li.getAttribute('data-my-pending-approval'), 10);
                if (!idSet[aid]) li.remove();
            });
            var left = list.querySelectorAll('li').length;
            countEl.textContent = String(left);
            if (left < 1) section.classList.add('hidden');
            else section.classList.remove('hidden');
            maybeHandleEditCooldownClearedFromPoll();
            if (typeof syncEditCooldownPendingUi === 'function') syncEditCooldownPendingUi();
            if (typeof editUpdateConflictInfo === 'function') editUpdateConflictInfo();
        }

        function rentalsShouldRunPendingSnapshotPoll() {
            return hasAnyPendingUi() || rentalsMgmtSnapshotWatcher || rentalsStaffApprovalWatcher;
        }

        function stopPendingApprovalPoll() {
            if (pendingPollTimer) {
                clearTimeout(pendingPollTimer);
                pendingPollTimer = null;
            }
            pendingLast = { count: null, latest_id: null };
        }

        function schedulePendingPoll(ms) {
            if (pendingPollTimer) {
                clearTimeout(pendingPollTimer);
                pendingPollTimer = null;
            }
            if (!rentalsShouldRunPendingSnapshotPoll()) {
                stopPendingApprovalPoll();
                return;
            }
            pendingPollTimer = setTimeout(runPendingPoll, Math.max(0, ms));
        }

        function runPendingPoll() {
            pendingPollTimer = null;
            if (!rentalsShouldRunPendingSnapshotPoll()) {
                pendingLast = { count: null, latest_id: null };
                return;
            }
            fetch(__rentalsRoutePendingSnapshot + '?_=' + Date.now(), {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(function(r) { return r.json(); })
                .then(function(s) {
                    syncMyPendingApprovalsFromSnapshot(s);
                    var count = s && typeof s.count === 'number' ? s.count : 0;
                    var latestId = s.latest_id != null ? Number(s.latest_id) : null;
                    var prevCount = pendingLast.count;
                    var prevLatestId = pendingLast.latest_id;
                    var changed = prevCount !== null
                        && (prevCount !== count || prevLatestId !== latestId);
                    if (changed && rentalsIsUserInteracting()) {
                        scheduleNextRentalsPendingPoll();
                        return;
                    }
                    pendingLast = { count: count, latest_id: latestId };

                    if (changed) {
                        if (rentalsMgmtSnapshotWatcher) {
                            fetch(rentalsQueueFragmentUrl + '?_=' + Date.now(), {
                                cache: 'no-store',
                                credentials: 'same-origin',
                                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            })
                                .then(function (r) {
                                    if (!r.ok) throw new Error('fragment');
                                    return r.json();
                                })
                                .then(function (d) {
                                    // Insert at the panel's natural top spot; a fresh panel used to land
                                    // at the bottom anchor, hidden below the list.
                                    var anchorEl = document.getElementById('manager-pending-approvals-top-anchor')
                                        || document.getElementById('rentals-approval-poll-anchor');
                                    var existing = document.getElementById('manager-pending-approvals-inline');
                                    var html = d && d.inline_html;
                                    if (!html) {
                                        if (existing) existing.remove();
                                    } else {
                                        var tpl = document.createElement('template');
                                        tpl.innerHTML = String(html).trim();
                                        var node = tpl.content.firstElementChild;
                                        if (node) {
                                            if (existing) existing.replaceWith(node);
                                            else if (anchorEl && anchorEl.parentNode) anchorEl.parentNode.insertBefore(node, anchorEl.nextSibling);
                                        }
                                    }
                                    if (typeof window.rentalUpdatePendingApprovalBadges === 'function') {
                                        window.rentalUpdatePendingApprovalBadges(d.queue_total);
                                    }
                                    if (typeof window.loadRentals === 'function') window.loadRentals();
                                })
                                .catch(function () {
                                    scheduleNextRentalsPendingPoll();
                                })
                                .finally(function () {
                                    if (!rentalsShouldRunPendingSnapshotPoll()) {
                                        stopPendingApprovalPoll();
                                        return;
                                    }
                                    scheduleNextRentalsPendingPoll();
                                });
                            return;
                        }
                        if (typeof window.loadRentals === 'function') {
                            window.loadRentals();
                        }
                    }

                    if (count <= 0) {
                        if (prevCount !== null && prevCount > 0 && typeof window.loadRentals === 'function') {
                            window.loadRentals();
                        }
                        if (!rentalsShouldRunPendingSnapshotPoll()) {
                            stopPendingApprovalPoll();
                        } else {
                            scheduleNextRentalsPendingPoll();
                        }
                        return;
                    }

                    scheduleNextRentalsPendingPoll();
                })
                .catch(function() {
                    schedulePendingPoll(RENTALS_APPROVAL_POLL_MS);
                });
        }

        function rentalTryStartPendingApprovalPoll() {
            if (!rentalsShouldRunPendingSnapshotPoll()) return;
            if (!pendingPollTimer) schedulePendingPoll(RENTALS_APPROVAL_POLL_MS);
        }
        window.rentalTryStartPendingApprovalPoll = rentalTryStartPendingApprovalPoll;

        rentalTryStartPendingApprovalPoll();
        document.addEventListener('DOMContentLoaded', rentalTryStartPendingApprovalPoll);
        document.addEventListener('visibilitychange', function() {
            if (rentalsShouldRunPendingSnapshotPoll() && !pendingPollTimer) {
                schedulePendingPoll(RENTALS_APPROVAL_POLL_MS);
            }
        });

        document.addEventListener('rental:approval-changed', function(e) {
            var d = e.detail || {};
            var seamlessEditHandled = false;
            if (d.approval_id != null) {
                if (editCooldownApprovalTracked && Number(editCooldownApprovalTracked.approvalId) === Number(d.approval_id)) {
                    editCooldownApprovalTracked = null;
                }
                document.querySelectorAll('[data-my-pending-approval="' + String(d.approval_id) + '"]').forEach(function(el) {
                    el.remove();
                });
                var countEl = document.getElementById('my-pending-approvals-count');
                var list = document.getElementById('my-pending-approvals-list');
                var section = document.getElementById('my-pending-approvals-section');
                if (countEl && list) {
                    var left = list.querySelectorAll('li').length;
                    countEl.textContent = String(left);
                    if (section && left < 1) {
                        section.classList.add('hidden');
                    }
                }
            }
            if (d.kind === 'edit' && d.rental_id && currentRentalId && Number(d.rental_id) === Number(currentRentalId)) {
                var editModalQuick = document.getElementById('editModal');
                if (editModalQuick && !editModalQuick.classList.contains('hidden')) {
                    window.__suppressEditPollReloadUntil = Date.now() + 1200;
                    reloadEditModalFromServer(Number(d.rental_id), d);
                    seamlessEditHandled = true;
                }
            }
            syncEditCooldownPendingUi();
            if (typeof editUpdateConflictInfo === 'function') editUpdateConflictInfo();
            // Rental list refresh: also triggered from resources/js/app.js via window.loadRentals after approve/reject.
            var editModal = document.getElementById('editModal');
            if (!seamlessEditHandled && editModal && !editModal.classList.contains('hidden') && currentRentalId) {
                var esd = document.getElementById('editSd');
                var eed = document.getElementById('editEd');
                if (esd && eed && esd.value && eed.value) {
                    var sel = [];
                    if (typeof editCart !== 'undefined') sel = Object.keys(editCart).map(function(i) { return parseInt(i, 10); });
                    fetchEditOutfits(esd.value, eed.value, currentRentalId, sel);
                }
            }
            var rsd = document.getElementById('rsd');
            var red = document.getElementById('red');
            // Skip /outfits/available when create cart is empty — saves a request; user gets fresh data when they add items or change dates.
            var cartHasItems = typeof cart !== 'undefined' && cart && Object.keys(cart).length > 0;
            if (typeof fetchOutfits === 'function' && cartHasItems && rsd && red && rsd.value && red.value) {
                fetchOutfits();
            }

            // Polling only matters for cross-device; keep it alive while pending exists.
            if (rentalsShouldRunPendingSnapshotPoll() && !pendingPollTimer) {
                schedulePendingPoll(RENTALS_APPROVAL_POLL_MS);
            }
        });

        // ── Item Edit Modal ──────────────────────────────────────────────
        var _itemEditRentalId = null;
        var _itemEditRentalData = null;
        var _itemEditSavedData = null; // data from last successful save, used once on next open

        function openItemEditModal(rental) {
            if (!rental || !rental.id) return;
            _itemEditRentalId = rental.id;
            _itemEditRentalData = null;

            var modal = document.getElementById('itemEditModal');
            var loading = document.getElementById('itemEditModalLoading');
            var content = document.getElementById('itemEditModalContent');
            var status = document.getElementById('itemEditModalStatus');

            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';

            // Use saved data immediately if it's for the same rental (avoids stale first-open after save)
            if (_itemEditSavedData && _itemEditSavedData.id == rental.id) {
                var saved = _itemEditSavedData;
                _itemEditSavedData = null;
                loading.classList.add('hidden');
                content.innerHTML = '';
                if (status) status.textContent = '';
                _itemEditRentalData = saved;
                renderItemEditModalContent(saved);
                return;
            }

            loading.classList.remove('hidden');
            content.innerHTML = '';
            if (status) status.textContent = '';

            fetch('/rentals/search?id=' + encodeURIComponent(rental.id) + '&per_page=1&page=1&_=' + Date.now(), {
                cache: 'no-store',
                credentials: 'same-origin',
            })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var fresh = (data && data.data && data.data[0]) ? data.data[0] : null;
                    loading.classList.add('hidden');
                    if (!fresh) { content.innerHTML = '<p class="text-sm text-red-500">Could not load rental.</p>'; return; }
                    _itemEditRentalData = fresh;
                    renderItemEditModalContent(fresh);
                })
                .catch(function() {
                    loading.classList.add('hidden');
                    content.innerHTML = '<p class="text-sm text-red-500">Failed to load.</p>';
                });
        }

        function renderItemEditModalContent(rental) {
            var content = document.getElementById('itemEditModalContent');
            var outfits = (rental.outfits && Array.isArray(rental.outfits)) ? rental.outfits : [];

            if (!outfits.length) {
                content.innerHTML = '<p class="text-sm text-gray-400">No items in this rental.</p>';
                return;
            }

            content.innerHTML = outfits.map(function(s) {
                var sid = s.id;
                var discVal = (s.pivot_discount != null && s.pivot_discount !== '' && Number(s.pivot_discount) > 0)
                    ? Number(s.pivot_discount) : '';
                var discType = s.pivot_discount_type || 'fixed';
                var discNote = s.pivot_discount_note || '';

                return '<div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 space-y-2" data-outfit-id="' + sid + '">'
                    + '<div><span class="font-medium text-sm text-gray-900">' + escRentalHtml(s.code || '') + '</span>'
                    + (s.description ? '<span class="text-xs text-gray-500 ml-2">' + escRentalHtml(s.description) + '</span>' : '')
                    + '</div>'
                    + '<div class="flex items-center gap-2">'
                    + '<input type="number" min="0" step="1" value="' + discVal + '" placeholder="0"'
                    + ' id="iem-disc-' + sid + '"'
                    + ' class="form-input w-28 text-sm" />'
                    + '<select id="iem-type-' + sid + '" class="form-input text-sm">'
                    + '<option value="fixed"' + (discType === 'fixed' ? ' selected' : '') + '>Rp</option>'
                    + '<option value="percent"' + (discType === 'percent' ? ' selected' : '') + '>%</option>'
                    + '</select>'
                    + '</div>'
                    + '<textarea id="iem-note-' + sid + '" rows="2" placeholder="Discount note"'
                    + ' class="form-input w-full text-sm resize-none">' + escRentalHtml(discNote) + '</textarea>'
                    + '</div>';
            }).join('');
        }

        function submitItemEditModal() {
            if (!_itemEditRentalId || !_itemEditRentalData) return;
            var rental = _itemEditRentalData;
            var outfits = (rental.outfits && Array.isArray(rental.outfits)) ? rental.outfits : [];

            var saveBtn = document.getElementById('itemEditModalSaveBtn');
            var status = document.getElementById('itemEditModalStatus');
            if (saveBtn) saveBtn.disabled = true;
            if (status) status.textContent = 'Saving…';

            // Snapshot what the user typed before building FormData
            var _submittedDiscounts = {};
            outfits.forEach(function(s) {
                var sid = s.id;
                var discEl = document.getElementById('iem-disc-' + sid);
                var typeEl = document.getElementById('iem-type-' + sid);
                var noteEl = document.getElementById('iem-note-' + sid);
                _submittedDiscounts[sid] = {
                    pivot_discount: discEl ? (discEl.value !== '' ? Number(discEl.value) : 0) : (s.pivot_discount || 0),
                    pivot_discount_type: typeEl ? typeEl.value : (s.pivot_discount_type || 'fixed'),
                    pivot_discount_note: noteEl ? noteEl.value : (s.pivot_discount_note || ''),
                };
            });

            var fd = new FormData();
            fd.append('_method', 'PUT');
            fd.append('_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

            // Required rental fields from fresh data
            fd.append('start_date', rental.start_date);
            fd.append('end_date', rental.end_date);
            fd.append('customer_name', rental.customer_name || '');
            fd.append('phone', rental.phone || '-');
            fd.append('instagram', rental.instagram || '');
            fd.append('notes', rental.notes || rental.address || '-');
            fd.append('payment_method', rental.payment_method || '');
            fd.append('payment_kind', rental.payment_kind || 'full');
            fd.append('amount_paid', rental.amount_paid != null ? rental.amount_paid : '');
            fd.append('discount', rental.discount != null ? rental.discount : '');
            fd.append('discount_type', rental.discount_type || 'fixed');
            fd.append('discount_note', rental.discount_note || '');
            fd.append('extra_charge', rental.extra_charge != null ? rental.extra_charge : '');

            // Outfit IDs + per-item discount from inputs
            outfits.forEach(function(s) {
                var sid = s.id;
                fd.append('outfit_ids[]', sid);
                var discEl = document.getElementById('iem-disc-' + sid);
                var typeEl = document.getElementById('iem-type-' + sid);
                var noteEl = document.getElementById('iem-note-' + sid);
                var discVal = discEl ? (discEl.value || '') : '';
                var typeVal = typeEl ? typeEl.value : 'fixed';
                var noteVal = noteEl ? noteEl.value : '';
                fd.append('item_discounts[' + sid + ']', discVal);
                fd.append('item_discount_types[' + sid + ']', typeVal);
                fd.append('item_discount_notes[' + sid + ']', noteVal);
            });

            fetch('/rentals/' + _itemEditRentalId + rentalTutorialQs(), {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
                .then(function(res) {
                    if (saveBtn) saveBtn.disabled = false;
                    if (res.ok && res.data.ok) {
                        // Update stored rental data directly from what was submitted — no re-fetch needed
                        if (_itemEditRentalData && _itemEditRentalData.outfits) {
                            _itemEditRentalData.outfits = _itemEditRentalData.outfits.map(function(s) {
                                var upd = _submittedDiscounts[s.id];
                                if (!upd) return s;
                                return Object.assign({}, s, {
                                    pivot_discount: upd.pivot_discount,
                                    pivot_discount_type: upd.pivot_discount_type,
                                    pivot_discount_note: upd.pivot_discount_note,
                                });
                            });
                            renderItemEditModalContent(_itemEditRentalData);
                            // Cache updated data so the next modal open shows it immediately
                            _itemEditSavedData = JSON.parse(JSON.stringify(_itemEditRentalData));
                        }
                        if (status) status.textContent = 'Saved!';
                        setTimeout(function() { if (status) status.textContent = ''; }, 2000);
                        if (typeof loadRentals === 'function') loadRentals();
                    } else {
                        var msg = (res.data && (res.data.message || Object.values(res.data.errors || {}).flat()[0])) || 'Save failed.';
                        if (status) status.textContent = msg;
                    }
                })
                .catch(function() {
                    if (saveBtn) saveBtn.disabled = false;
                    if (status) status.textContent = 'Request failed.';
                });
        }

        function closeItemEditModal() {
            document.getElementById('itemEditModal').classList.add('hidden');
            document.body.style.overflow = '';
            _itemEditRentalId = null;
            _itemEditRentalData = null;
        }
