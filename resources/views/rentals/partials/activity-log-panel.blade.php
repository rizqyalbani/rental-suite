@if(Auth::check() && Auth::user()->mayViewActivityLog())
    {{-- Plain inline CSS on purpose, not Tailwind utility classes: this project has two
         conflicting Tailwind versions installed (tailwindcss@3 + @tailwindcss/vite@4),
         which silently fails to generate some utilities (confirmed with translate-x-full
         computing to transform:none). Sizing/animation here must not depend on that. --}}
    <style>
        #activityLogEdgeToggle {
            position: fixed;
            right: 0;
            top: 50%;
            margin-top: -20px;
            z-index: 69;
        }
        #activityLogOverlay {
            position: fixed;
            inset: 0;
            background: rgba(107, 114, 128, 0.5);
            z-index: 70;
            opacity: 0;
            pointer-events: none;
            transition: opacity 350ms ease;
        }
        #activityLogOverlay.activity-log-overlay-open {
            opacity: 1;
            pointer-events: auto;
        }
        #activityLogPanel {
            position: fixed;
            top: 0;
            right: 0;
            height: 100%;
            width: 30%;
            min-width: 320px;
            max-width: 480px;
            background: #fff;
            box-shadow: -8px 0 24px rgba(0, 0, 0, 0.12);
            z-index: 71;
            display: flex;
            flex-direction: column;
            transform: translateX(100%);
            transition: transform 350ms ease;
        }
        #activityLogPanel.activity-log-panel-open {
            transform: translateX(0);
        }
        @media (max-width: 640px) {
            #activityLogPanel {
                width: 100%;
                min-width: 0;
                max-width: none;
            }
        }
    </style>

    <button type="button" id="activityLogEdgeToggle" onclick="openGlobalActivityLogPanel()"
        class="bg-white border border-gray-200 border-r-0 rounded-l-md shadow-sm px-1.5 py-3 text-gray-400 hover:text-indigo-600 hover:bg-gray-50 cursor-pointer"
        aria-label="Show latest activity">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
    </button>

    <div id="activityLogOverlay" onclick="closeActivityLogPanel()"></div>
    <aside id="activityLogPanel">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 shrink-0">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold text-gray-900">Activity log</h3>
                <p id="activityLogPanelSubtitle" class="text-xs text-gray-500 truncate"></p>
            </div>
            <button type="button" onclick="closeActivityLogPanel()" class="text-gray-400 hover:text-gray-600 cursor-pointer shrink-0" aria-label="Close">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div id="activityLogPanelBody" class="flex-1 overflow-y-auto px-4 py-3">
            <div class="text-center py-10 text-sm text-gray-400">Loading…</div>
        </div>

        <div class="px-4 py-3 border-t border-gray-200 shrink-0">
            <a id="activityLogPanelFullLink" href="#" class="text-xs font-medium text-indigo-600 hover:text-indigo-800">View full history and filters &rarr;</a>
        </div>
    </aside>

    <script>
        var _activityLogPanelCache = {};

        function openActivityLogPanel(rentalId, customerName) {
            _activityLogPanelOpen('rental:' + rentalId, '/activity-log-panel?rental_id=' + rentalId, 'Rental #' + rentalId + (customerName ? ' — ' + customerName : ''));
        }

        function openGlobalActivityLogPanel() {
            _activityLogPanelOpen('global', '/activity-log-panel', 'Latest activity — all rentals');
        }

        function _activityLogPanelOpen(cacheKey, url, subtitleText) {
            var overlay = document.getElementById('activityLogOverlay');
            var panel = document.getElementById('activityLogPanel');
            var body = document.getElementById('activityLogPanelBody');
            var subtitle = document.getElementById('activityLogPanelSubtitle');
            if (!overlay || !panel || !body) return;

            overlay.classList.add('activity-log-overlay-open');
            panel.classList.add('activity-log-panel-open');
            subtitle.textContent = subtitleText;

            var cached = _activityLogPanelCache[cacheKey];
            if (cached) {
                renderActivityLogPanel(cached);
            } else {
                body.innerHTML = '<div class="text-center py-10 text-sm text-gray-400">Loading…</div>';
            }

            fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data || !data.ok) {
                        body.innerHTML = '<div class="text-center py-10 text-sm text-red-500">' + ((data && data.message) || 'Could not load activity log.') + '</div>';
                        return;
                    }
                    _activityLogPanelCache[cacheKey] = data;
                    renderActivityLogPanel(data);
                })
                .catch(function() {
                    if (!cached) body.innerHTML = '<div class="text-center py-10 text-sm text-red-500">Could not load activity log.</div>';
                });
        }

        function activityLogActionBadgeClass(action) {
            switch (action) {
                case 'created': return 'bg-green-100 text-green-700';
                case 'deleted': return 'bg-red-100 text-red-700';
                case 'item_removal_requested': return 'bg-amber-100 text-amber-700';
                case 'item_removal_approved': return 'bg-indigo-100 text-indigo-700';
                case 'item_removal_rejected': return 'bg-gray-100 text-gray-600';
                case 'status_changed': return 'bg-blue-100 text-blue-700';
                case 'edit_requested': return 'bg-amber-100 text-amber-700';
                case 'edit_approved': return 'bg-indigo-100 text-indigo-700';
                case 'edit_rejected': return 'bg-gray-100 text-gray-600';
                case 'shift_opened': return 'bg-teal-100 text-teal-700';
                case 'shift_closed': return 'bg-slate-200 text-slate-700';
                default: return 'bg-slate-100 text-slate-700';
            }
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function renderActivityLogPanel(data) {
            var body = document.getElementById('activityLogPanelBody');
            var fullLink = document.getElementById('activityLogPanelFullLink');
            if (fullLink && data.full_history_url) fullLink.href = data.full_history_url;
            if (!body) return;

            var logs = data.logs || [];
            if (!logs.length) {
                body.innerHTML = '<div class="text-center py-10 text-sm text-gray-400">No activity recorded yet.</div>';
                return;
            }

            var html = '<ol class="space-y-3">';
            logs.forEach(function(log) {
                html += '<li class="border border-gray-100 rounded-lg p-3">';
                html += '<div class="flex items-center gap-2 flex-wrap">';
                html += '<span class="font-semibold rounded-full ' + activityLogActionBadgeClass(log.action) + '" style="padding:2px 8px;font-size:11px;line-height:14px;display:inline-block;">' + escapeHtml(log.action_label) + '</span>';
                if (log.rental_id) {
                    html += '<button type="button" onclick="openActivityLogPanel(' + log.rental_id + ', ' + escapeHtml(JSON.stringify(log.rental_customer_name || '')) + ')" class="text-xs font-medium text-indigo-600 hover:text-indigo-800 cursor-pointer">Rental #' + log.rental_id + (log.rental_customer_name ? ' — ' + escapeHtml(log.rental_customer_name) : '') + '</button>';
                }
                html += '</div>';
                html += '<div class="text-xs text-gray-500 mt-1">' + escapeHtml(log.user_name) + ' &bull; ' + escapeHtml(log.time_full) + ' (' + escapeHtml(log.time_human) + ')' + (log.note ? ' &bull; ' + escapeHtml(log.note) : '') + '</div>';
                if (log.changes && log.changes.length) {
                    html += '<div class="mt-2 bg-gray-50 border border-gray-100 rounded-md divide-y divide-gray-100">';
                    log.changes.forEach(function(c) {
                        html += '<div class="px-2.5 py-1.5 text-xs">';
                        html += '<div class="text-[10px] uppercase tracking-wide text-gray-400">' + escapeHtml(c.label) + '</div>';
                        html += '<div class="flex items-baseline gap-1.5 flex-wrap">';
                        html += '<span class="text-gray-500 line-through decoration-red-300">' + escapeHtml(c.from) + '</span>';
                        html += '<span class="text-gray-400">&rarr;</span>';
                        html += '<span class="text-gray-900 font-medium">' + escapeHtml(c.to) + '</span>';
                        html += '</div></div>';
                    });
                    html += '</div>';
                }
                html += '</li>';
            });
            html += '</ol>';
            body.innerHTML = html;
        }

        function closeActivityLogPanel() {
            var overlay = document.getElementById('activityLogOverlay');
            var panel = document.getElementById('activityLogPanel');
            if (panel) panel.classList.remove('activity-log-panel-open');
            if (overlay) overlay.classList.remove('activity-log-overlay-open');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeActivityLogPanel();
        });
    </script>
@endif
