<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Stock Opname — {{ $session->started_at->format('d M Y') }}
        </h2>
    </x-slot>

    @php
        $okCount = $itemsByOutfitId->where('status', 'ok')->count();
        $missingCount = $itemsByOutfitId->where('status', 'missing')->count();
        $damagedCount = $itemsByOutfitId->where('status', 'damaged')->count();
        $exceptionCount = $missingCount + $damagedCount;
    @endphp

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                    {{ session('success') }}
                </div>
            @endif

            <a href="{{ route('stock-opname.index') }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">&larr; Back to History</a>

            @if($session->is_tutorial_practice)
                <div class="mt-4 bg-indigo-50 border border-indigo-200 text-indigo-800 text-sm px-4 py-3 rounded-lg">
                    <strong>Practice session.</strong> The items here are for practice only: they do not affect the store's real opname data and do not appear in the history.
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mt-4">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
                        <h3 class="text-lg font-medium text-gray-900">
                            Opname Details
                            @if($session->isCompleted())
                                <span class="ml-2 px-2 py-0.5 bg-green-100 text-green-800 text-xs font-semibold rounded-full align-middle">Completed</span>
                            @else
                                <span class="ml-2 px-2 py-0.5 bg-amber-100 text-amber-800 text-xs font-semibold rounded-full align-middle">In progress</span>
                            @endif
                        </h3>
                        <div class="flex items-center gap-3">
                            @if($session->isCompleted())
                                <a href="{{ route('stock-opname.download-pdf', $session) }}" id="stockOpnameDownloadPdfLink" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">Download PDF</a>
                            @endif
                            <span class="text-sm text-gray-500">{{ $outfits->count() }} items total</span>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mb-4">
                        Started by {{ $session->startedBy->name ?? 'Unknown' }} &bull; {{ $session->started_at->format('d M Y H:i') }}
                        @if($session->isCompleted())
                            &bull; Completed by {{ $session->completedBy->name ?? 'Unknown' }} &bull; {{ $session->completed_at->format('d M Y H:i') }}
                        @endif
                    </p>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                        <div class="border border-gray-200 rounded-lg p-4 text-center">
                            <p class="text-2xl font-semibold text-gray-900 tabular-nums">{{ $okCount }}</p>
                            <p class="text-xs text-gray-500 mt-1">Present</p>
                        </div>
                        <div class="border border-gray-200 rounded-lg p-4 text-center">
                            <p class="text-2xl font-semibold text-red-700 tabular-nums">{{ $missingCount }}</p>
                            <p class="text-xs text-gray-500 mt-1">Missing</p>
                        </div>
                        <div class="border border-gray-200 rounded-lg p-4 text-center">
                            <p class="text-2xl font-semibold text-amber-700 tabular-nums">{{ $damagedCount }}</p>
                            <p class="text-xs text-gray-500 mt-1">Damaged</p>
                        </div>
                        <div class="border border-gray-200 rounded-lg p-4 text-center">
                            <p class="text-2xl font-semibold text-gray-400 tabular-nums">{{ $uncheckedCount }}</p>
                            <p class="text-xs text-gray-500 mt-1">Not Checked</p>
                        </div>
                    </div>

                    @if($session->notes)
                        <div class="mb-4 text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-md p-3">
                            <span class="font-medium text-gray-700">Session notes:</span> {{ $session->notes }}
                        </div>
                    @endif

                    @if(! $session->isCompleted())
                        <div class="mb-4 flex gap-2">
                            <input type="text" id="opnameSearch" placeholder="Search code or description…"
                                class="form-input-search w-full h-10 px-3 border border-gray-300 rounded-md text-sm"
                                autocomplete="off">
                            <button type="button" id="stockOpnameScanBtn"
                                class="shrink-0 h-10 px-4 flex items-center gap-1.5 border border-indigo-300 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-sm font-medium rounded-md cursor-pointer">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM17 17h4v4h-4zM14 21h3M21 14h0"/></svg>
                                Scan QR
                            </button>
                        </div>

                        @if(Auth::user()->isSuperAdmin() && $uncheckedCount > 0)
                            <div class="mb-4 border border-amber-200 bg-amber-50 rounded-md p-3">
                                <p class="text-xs font-medium text-amber-800 mb-2">
                                    Super Admin: mark all items that are still "Not Checked" at once ({{ $uncheckedCount }} items).
                                    Items already marked by staff are not changed.
                                </p>
                                <div class="flex gap-2">
                                    @foreach(['ok' => 'Present', 'missing' => 'Missing'] as $bulkStatus => $bulkLabel)
                                        <form method="POST" action="{{ route('stock-opname.bulk-mark', $session) }}"
                                            onsubmit="return window.rentalConfirmSubmit(event, 'Mark all {{ $uncheckedCount }} items that are still Not Checked as {{ $bulkLabel }}? Items already marked by staff will not be touched.', { title: 'Mark all as {{ $bulkLabel }}?', confirmButtonText: 'Mark All', cancelButtonText: 'Cancel' })">
                                            @csrf
                                            <input type="hidden" name="status" value="{{ $bulkStatus }}">
                                            <button type="submit" class="px-3 py-1.5 text-xs font-medium rounded-md border border-amber-300 bg-white hover:bg-amber-100 text-amber-900 cursor-pointer">Mark All {{ $bulkLabel }}</button>
                                        </form>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif

                    <div class="overflow-x-auto border border-gray-200 rounded-lg">
                        <table class="min-w-full divide-y divide-gray-200" id="opnameTable">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Code</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Status</th>
                                    @if(! $session->isCompleted())
                                        <th class="px-4 py-3"></th>
                                    @else
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($outfits as $outfit)
                                    @php
                                        $item = $itemsByOutfitId->get($outfit->id);
                                        $status = $item->status ?? 'unchecked';
                                        $badgeClasses = [
                                            'ok' => 'bg-green-100 text-green-800',
                                            'missing' => 'bg-red-100 text-red-800',
                                            'damaged' => 'bg-amber-100 text-amber-800',
                                            'unchecked' => 'bg-gray-100 text-gray-500',
                                        ];
                                        $badgeLabels = ['ok' => 'Present', 'missing' => 'Missing', 'damaged' => 'Damaged', 'unchecked' => 'Not Checked'];
                                    @endphp
                                    @if($session->isCompleted() && $status === 'ok')
                                        @continue
                                    @endif
                                    <tr data-outfit-row data-code="{{ strtolower($outfit->code) }}" data-desc="{{ strtolower($outfit->description ?? '') }}">
                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {{ $outfit->code }}
                                            <a href="{{ route('stock-opname.item-history', $outfit) }}" class="ml-1 text-[10px] text-gray-400 hover:text-indigo-600 hover:underline align-middle" title="Opname history of this item">history</a>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-500">
                                            {{ $outfit->description ?? '—' }}
                                            @if($outfit->rentals->count() > 0)
                                                <button type="button" data-rental-badge data-outfit-id="{{ $outfit->id }}"
                                                    class="ml-1.5 inline-block px-1.5 py-0.5 text-[11px] font-medium rounded bg-blue-50 text-blue-700 align-middle hover:bg-blue-100 cursor-pointer"
                                                    title="This item is currently rented, which does not mean it is missing. Click to see the rental details">Currently Rented ({{ $outfit->rentals->count() }})</button>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span data-status-badge class="px-2 py-1 text-xs font-semibold rounded-full {{ $badgeClasses[$status] }}">
                                                {{ $badgeLabels[$status] }}
                                            </span>
                                            <span data-photo-link-wrap data-outfit-id="{{ $outfit->id }}" class="ml-1 align-middle">
                                                @if($item && $item->photo_path)
                                                    <a href="{{ route('stock-opname.item-photo', $item) }}" target="_blank" rel="noopener" data-photo-link class="text-[13px]" title="View photo">📷</a>
                                                @endif
                                            </span>
                                        </td>
                                        @if(! $session->isCompleted())
                                            <td class="px-4 py-3 whitespace-nowrap text-right">
                                                <div class="inline-flex gap-1" data-status-buttons data-outfit-id="{{ $outfit->id }}">
                                                    <button type="button" data-set-status="ok" class="px-2 py-1 text-xs rounded border border-gray-300 hover:bg-gray-50 cursor-pointer">Present</button>
                                                    <button type="button" data-set-status="missing" class="px-2 py-1 text-xs rounded border border-gray-300 hover:bg-red-50 cursor-pointer">Missing</button>
                                                    <button type="button" data-set-status="damaged" class="px-2 py-1 text-xs rounded border border-gray-300 hover:bg-amber-50 cursor-pointer">Damaged</button>
                                                </div>
                                            </td>
                                        @else
                                            <td class="px-4 py-3 text-sm text-gray-500">{{ $item->note ?? '—' }}</td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-400">No items.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($session->isCompleted() && $exceptionCount === 0)
                        <p class="text-sm text-gray-400 text-center mt-4">All items match, no differences.</p>
                    @endif

                    @if(! $session->isCompleted() && $mayManage)
                        <div class="mt-6 border-t border-gray-200 pt-6">
                            <form method="POST" action="{{ route('stock-opname.complete', $session) }}"
                                onsubmit="return window.rentalConfirmSubmit(event, '{{ $uncheckedCount > 0 ? "{$uncheckedCount} items have not been checked yet and will stay recorded as Not Checked if you continue. " : '' }}Finish this opname session? Once finished, items can no longer be re-marked.', { title: 'Finish the opname?', confirmButtonText: 'Finish', cancelButtonText: 'Cancel' })">
                                @csrf
                                <label class="block text-sm font-medium text-gray-700 mb-1">Session notes (optional)</label>
                                <textarea name="notes" rows="2" class="form-textarea w-full mb-3 border border-gray-300 rounded-md text-sm" placeholder="e.g. opname done during the monthly restock"></textarea>
                                <button type="submit" id="stockOpnameCompleteBtn" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-md cursor-pointer">
                                    Finish Opname
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Rental-detail modal for the clickable "Currently Rented" badge — its own
         tiny endpoint (StockOpnameController::rentalDetail), not the Items
         page's rental-detail-fragment, whose rendered partials include a
         "Select rent" button tied to a rental-creation cart that doesn't
         exist here. Available regardless of session status: a completed
         session's rows should still be able to show who was renting it. --}}
    <div id="stockOpnameRentalModal" class="hidden fixed inset-0 z-[210] bg-black/50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-5">
            <div class="flex items-center justify-between mb-3">
                <p id="stockOpnameRentalModalTitle" class="text-sm font-semibold text-gray-900">Currently Rented</p>
                <button type="button" id="stockOpnameRentalModalClose" class="text-gray-400 hover:text-gray-600 text-xl leading-none cursor-pointer">&times;</button>
            </div>
            <div id="stockOpnameRentalModalBody" class="space-y-3 text-sm text-gray-700"></div>
        </div>
    </div>

    <script>
    (function () {
        var modal = document.getElementById('stockOpnameRentalModal');
        var title = document.getElementById('stockOpnameRentalModalTitle');
        var body = document.getElementById('stockOpnameRentalModalBody');
        var closeBtn = document.getElementById('stockOpnameRentalModalClose');
        var rentalDetailBaseUrl = @json(route('stock-opname.rental-detail', ['outfit' => '__OUTFIT__']));

        function closeRentalModal() { modal.classList.add('hidden'); }
        closeBtn.addEventListener('click', closeRentalModal);
        modal.addEventListener('click', function (e) { if (e.target === modal) closeRentalModal(); });

        document.querySelectorAll('[data-rental-badge]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var outfitId = btn.getAttribute('data-outfit-id');
                body.innerHTML = '<p class="text-gray-400">Loading…</p>';
                modal.classList.remove('hidden');
                fetch(rentalDetailBaseUrl.replace('__OUTFIT__', outfitId))
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (!data.ok) {
                            body.innerHTML = '<p class="text-gray-400">Failed to load rental data.</p>';
                            return;
                        }
                        title.textContent = 'Currently Rented — ' + data.code;
                        if (!data.rentals.length) {
                            body.innerHTML = '<p class="text-gray-400">No active rental.</p>';
                            return;
                        }
                        body.innerHTML = data.rentals.map(function (r) {
                            return '<div class="border border-gray-200 rounded-lg p-3">'
                                + '<p class="font-medium">' + escapeHtml(r.customer_name) + '</p>'
                                + '<p class="text-xs text-gray-500 mt-1">' + escapeHtml(r.start_label) + ' – ' + escapeHtml(r.end_label) + '</p>'
                                + '<p class="text-xs text-gray-500 mt-1">' + escapeHtml(r.phone || '-') + '</p>'
                                + '</div>';
                        }).join('');
                    })
                    .catch(function () {
                        body.innerHTML = '<p class="text-gray-400">Failed to load rental data.</p>';
                    });
            });
        });

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
    })();
    </script>

    @if(! $session->isCompleted())
        <div id="stockOpnameScanModal" class="hidden fixed inset-0 z-[210] bg-black/70 flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                    <p class="text-sm font-semibold text-gray-900">Scan Item QR</p>
                    <button type="button" id="stockOpnameScanClose" class="text-gray-400 hover:text-gray-600 text-xl leading-none cursor-pointer">&times;</button>
                </div>
                <div class="relative bg-black aspect-square">
                    <video id="stockOpnameScanVideo" class="w-full h-full object-cover" muted playsinline></video>
                </div>
                <div id="stockOpnameScanStatus" class="px-4 py-3 text-sm text-gray-600 min-h-[3.5rem] flex items-center justify-center text-center leading-snug">
                    Point the camera at the QR code attached to the item.
                </div>
            </div>
        </div>

        <script>
        (function () {
            var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            var markUrl = @json(route('stock-opname.mark', $session));
            var scanUrl = @json(route('stock-opname.scan', $session));
            var photoUrlBase = @json(route('stock-opname.item-photo', ['stockOpnameItem' => '__ITEM__']));
            var badgeLabels = { ok: 'Present', missing: 'Missing', damaged: 'Damaged' };
            var badgeClasses = {
                ok: 'bg-green-100 text-green-800',
                missing: 'bg-red-100 text-red-800',
                damaged: 'bg-amber-100 text-amber-800',
            };

            // Shared by both the manual Present/Missing/Damaged buttons and a
            // successful QR scan — updates one row's badge in place and
            // fires the same event the interactive tutorial's auto-advance
            // step listens for, so both entry points look identical to it.
            function applyStatusToRow(outfitId, status, itemId, hasPhoto) {
                var group = document.querySelector('[data-status-buttons][data-outfit-id="' + outfitId + '"]');
                if (!group) return;
                var badge = group.closest('tr').querySelector('[data-status-badge]');
                badge.textContent = badgeLabels[status];
                badge.className = 'px-2 py-1 text-xs font-semibold rounded-full ' + badgeClasses[status];

                var photoWrap = group.closest('tr').querySelector('[data-photo-link-wrap]');
                if (photoWrap) {
                    photoWrap.innerHTML = hasPhoto
                        ? '<a href="' + photoUrlBase.replace('__ITEM__', itemId) + '" target="_blank" rel="noopener" class="text-[13px]" title="View photo">📷</a>'
                        : '';
                }

                document.dispatchEvent(new CustomEvent('rental:opname-marked', { detail: { outfitId: outfitId, status: status } }));
            }

            document.querySelectorAll('[data-status-buttons]').forEach(function (group) {
                var outfitId = group.getAttribute('data-outfit-id');

                // Disables all 3 buttons the instant one is pressed (mobile in
                // particular has no other affordance telling the tap actually
                // registered) and puts the pressed one into a "Saving…"
                // state — reset in both the success and error paths of send().
                function setGroupLoading(loading, activeBtn) {
                    group.querySelectorAll('[data-set-status]').forEach(function (b) {
                        b.disabled = loading;
                        b.classList.toggle('opacity-50', loading);
                        b.classList.toggle('cursor-not-allowed', loading);
                        if (b === activeBtn) {
                            if (loading) {
                                b.dataset.origText = b.dataset.origText || b.textContent;
                                b.textContent = 'Saving…';
                            } else if (b.dataset.origText) {
                                b.textContent = b.dataset.origText;
                            }
                        }
                    });
                }

                group.querySelectorAll('[data-set-status]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var status = btn.getAttribute('data-set-status');
                        // A photo already attached from an earlier mark (visible as
                        // the 📷 link next to the badge) means this is just an edit
                        // — re-uploading isn't required again, mark() already keeps
                        // the existing photo_path when no new file is sent.
                        var photoWrap = group.closest('tr').querySelector('[data-photo-link-wrap]');
                        var hasExistingPhoto = !!(photoWrap && photoWrap.querySelector('a'));

                        function send(note, photoFile) {
                            setGroupLoading(true, btn);
                            var request;
                            if (photoFile) {
                                var fd = new FormData();
                                fd.append('outfit_id', outfitId);
                                fd.append('status', status);
                                if (note) fd.append('note', note);
                                fd.append('photo', photoFile);
                                request = fetch(markUrl, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                                    body: fd,
                                });
                            } else {
                                request = fetch(markUrl, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': csrfToken,
                                        'Accept': 'application/json',
                                    },
                                    body: JSON.stringify({ outfit_id: outfitId, status: status, note: note }),
                                });
                            }

                            request.then(function (res) { return res.json(); }).then(function (data) {
                                if (!data.ok) {
                                    setGroupLoading(false, btn);
                                    var firstError = data.errors ? (Object.values(data.errors)[0] || [])[0] : null;
                                    window.Swal && window.Swal.fire({ icon: 'error', title: 'Failed to save', text: data.error || firstError || data.message || 'Try again.' });
                                    return;
                                }
                                applyStatusToRow(outfitId, status, data.item_id, data.has_photo);
                                setGroupLoading(false, btn);
                            }).catch(function () {
                                setGroupLoading(false, btn);
                                window.Swal && window.Swal.fire({ icon: 'error', title: 'Failed to save', text: 'Failed to send the data. If you were sending a photo, it is probably too large: retake it or reduce its size.' });
                            });
                        }

                        if (status === 'ok') {
                            send(null, null);
                            return;
                        }

                        // A photo is required for "Damaged" (evidence of the damage), but
                        // it makes no sense to require one for "Missing" (the item is not
                        // there to be photographed), so it stays optional there.
                        var photoRequired = status === 'damaged' && !hasExistingPhoto;

                        window.Swal.fire({
                            title: status === 'missing' ? 'Mark item as missing' : 'Mark item as damaged',
                            html:
                                '<textarea id="swalOpnameNote" class="swal2-textarea" placeholder="Notes (optional)…" style="margin-top:0"></textarea>'
                                + '<label style="display:block; text-align:left; font-size:12.5px; color:#6b7280; margin:6px 2px 2px;">'
                                + (photoRequired ? 'Photo evidence (required): the camera opens right away on a phone' : 'Photo evidence (optional)')
                                + '</label>'
                                + '<input type="file" id="swalOpnamePhoto" accept="image/*" capture="environment" class="swal2-file" style="margin-top:0;">',
                            focusConfirm: false,
                            showCancelButton: true,
                            confirmButtonText: 'Save',
                            cancelButtonText: 'Cancel',
                            preConfirm: function () {
                                var noteEl = document.getElementById('swalOpnameNote');
                                var fileEl = document.getElementById('swalOpnamePhoto');
                                var photo = (fileEl && fileEl.files && fileEl.files[0]) || null;
                                if (photoRequired && !photo) {
                                    window.Swal.showValidationMessage('A photo is required to mark an item as Damaged.');
                                    return false;
                                }
                                return {
                                    note: noteEl ? (noteEl.value || null) : null,
                                    photo: photo,
                                };
                            },
                        }).then(function (result) {
                            if (!result.isConfirmed) return;
                            send(result.value.note, result.value.photo);
                        });
                    });
                });
            });

            var searchInput = document.getElementById('opnameSearch');
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    var q = searchInput.value.trim().toLowerCase();
                    document.querySelectorAll('#opnameTable tr[data-outfit-row]').forEach(function (row) {
                        var match = !q || row.getAttribute('data-code').includes(q) || row.getAttribute('data-desc').includes(q);
                        row.classList.toggle('hidden', !match);
                    });
                });
            }

            // QR scanning — the printed QR on each item encodes an absolute
            // .../i/{token} URL (see OutfitController::scanByToken), so a
            // camera decode just needs that token pulled back out; nothing
            // here actually navigates to it.
            var scanBtn = document.getElementById('stockOpnameScanBtn');
            var scanModal = document.getElementById('stockOpnameScanModal');
            var scanVideo = document.getElementById('stockOpnameScanVideo');
            var scanStatus = document.getElementById('stockOpnameScanStatus');
            var scanCloseBtn = document.getElementById('stockOpnameScanClose');
            var qrScanner = null;
            var lastToken = null;
            var lastTokenAt = 0;
            var statusResetTimer = null;

            function setScanStatus(text, isError) {
                scanStatus.textContent = text;
                scanStatus.className = 'px-4 py-3 text-sm min-h-[3.5rem] flex items-center justify-center text-center leading-snug '
                    + (isError ? 'text-red-600' : 'text-green-700');
                clearTimeout(statusResetTimer);
                statusResetTimer = setTimeout(function () {
                    scanStatus.textContent = 'Point the camera at the QR code attached to the item.';
                    scanStatus.className = 'px-4 py-3 text-sm text-gray-600 min-h-[3.5rem] flex items-center justify-center text-center leading-snug';
                }, 2500);
            }

            function handleDecoded(text) {
                var match = /\/i\/([A-Za-z0-9]{32,64})(?:[/?#]|$)/.exec(text);
                if (!match) {
                    setScanStatus('QR code not recognised: this is not an item QR.', true);
                    return;
                }
                var token = match[1];
                var now = Date.now();
                // The camera keeps decoding the same tag every frame while it's
                // in view — without this, holding steady on one item would fire
                // dozens of identical requests instead of just the first one.
                if (token === lastToken && (now - lastTokenAt) < 2500) return;
                lastToken = token;
                lastTokenAt = now;

                fetch(scanUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ token: token }),
                }).then(function (res) { return res.json().then(function (data) { return { status: res.status, data: data }; }); })
                    .then(function (result) {
                        if (!result.data.ok) {
                            setScanStatus(result.data.error || 'Failed to mark the item.', true);
                            return;
                        }
                        applyStatusToRow(String(result.data.outfit_id), 'ok');
                        setScanStatus(result.data.code + ' — ' + (result.data.description || '') + ' marked Present.', false);
                    }).catch(function () {
                        setScanStatus('Could not reach the server, please try again.', true);
                    });
            }

            function closeScanModal() {
                if (qrScanner) qrScanner.stop();
                scanModal.classList.add('hidden');
            }

            if (scanBtn) {
                scanBtn.addEventListener('click', function () {
                    // Checked here, not at script-load time — app.js's own
                    // <script type="module"> is deferred by the browser, so it
                    // can genuinely still be loading when this plain inline
                    // script runs; window.QrScanner only needs to actually
                    // exist by the time someone clicks, not by page load.
                    if (!window.QrScanner) {
                        window.Swal && window.Swal.fire({ icon: 'error', title: 'Scanner not ready', text: 'Please try again in a moment.' });
                        return;
                    }
                    scanModal.classList.remove('hidden');
                    if (!qrScanner) {
                        qrScanner = new window.QrScanner(scanVideo, function (result) {
                            handleDecoded(result.data);
                        }, { highlightScanRegion: true, highlightCodeOutline: true });
                    }
                    qrScanner.start().catch(function () {
                        setScanStatus('Cannot access the camera: check the camera permission in your browser.', true);
                    });
                });
                scanCloseBtn.addEventListener('click', closeScanModal);
                scanModal.addEventListener('click', function (e) {
                    if (e.target === scanModal) closeScanModal();
                });
            }
        })();
        </script>
    @endif

    <button type="button" data-open-stock-opname-tour
        class="fixed right-4 bottom-24 z-[90] flex h-11 w-11 items-center justify-center rounded-full bg-indigo-600 text-white shadow-lg hover:bg-indigo-700 transition cursor-pointer"
        aria-label="Help for this page" title="Help for this page">
        <span class="text-lg font-bold leading-none">?</span>
    </button>
</x-app-layout>
