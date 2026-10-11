{{--
    $downloadPdfUrl (required)
    $fullReportUrl — optional link to printable full report (new tab)
    $sharePdfUrl, $shareTitle, $shareText — optional; signed PDF URL + Web Share / WhatsApp app
    $idSuffix — unique suffix for DOM ids when multiple instances could exist
--}}
@php
    $suffix = $idSuffix ?? 'share';

    $shareUrlForText = '';
    if (! empty($sharePdfUrl)) {
        $shareUrlForText = (string) $sharePdfUrl;
        if (! preg_match('/^https?:\/\//i', $shareUrlForText)) {
            $shareUrlForText = url($shareUrlForText);
        }
    }
    $shareTitleForText = (string) ($shareTitle ?? 'Demo Store One — shift closing report');
    $shareTextForText = (string) ($shareText ?? '');
    $waBodyForText = trim($shareTitleForText."\n\n".$shareTextForText."\n\nPDF: ".$shareUrlForText);
@endphp

<div class="p-4 bg-gray-50 border border-gray-200 rounded-lg">
    <h2 class="text-sm font-semibold text-gray-800 mb-2">Download & share</h2>
    <p class="text-xs text-gray-600 mb-3">Download the PDF, open <strong>WhatsApp</strong> with the report text and secure link, or use <strong>Share</strong> / copy link on supported devices.</p>
    <div class="flex flex-col gap-3">
        @if (!empty($sharePdfUrl))
            <form method="GET" action="{{ route('whatsapp.redirect') }}" target="_blank"
                class="grid grid-cols-[minmax(0,1fr)_auto] items-end gap-2 w-full">
                <div class="min-w-0">
                    <label for="shiftReportWhatsappPhone_{{ $suffix }}" class="block text-xs font-medium text-gray-700 mb-1">Optional: WhatsApp number</label>
                    <input type="text" id="shiftReportWhatsappPhone_{{ $suffix }}" name="phone" placeholder="Leave empty to choose a chat, or e.g. 08123456789" autocomplete="tel"
                        class="form-input w-full" value="">
                    <input type="hidden" name="text" value="{{ $waBodyForText }}">
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <a href="{{ $downloadPdfUrl }}" download class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-md text-sm font-medium hover:bg-gray-900 cursor-pointer">
                        Download PDF
                    </a>
                    <button type="submit"
                        class="inline-flex items-center justify-center px-4 py-2 bg-emerald-600 text-white rounded-md text-sm font-medium hover:bg-emerald-700 cursor-pointer shrink-0 whitespace-nowrap">
                        WhatsApp
                    </button>
                    <button type="button" id="shiftReportShareBtn_{{ $suffix }}"
                        class="hidden inline-flex items-center px-4 py-2 bg-sky-600 text-white rounded-md text-sm font-medium hover:bg-sky-700 cursor-pointer">
                        Share…
                    </button>
                    @if (!empty($fullReportUrl))
                        <a href="{{ $fullReportUrl }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer">
                            Open full report &amp; print
                        </a>
                    @endif
                </div>
                <p id="shiftReportShareStatus_{{ $suffix }}" class="col-span-2 text-xs text-gray-600 min-h-[1.25rem]" role="status"></p>
            </form>
        @else
            <div class="flex flex-col gap-2">
                <p id="shiftReportShareStatus_{{ $suffix }}" class="text-xs text-gray-600 min-h-[1.25rem]" role="status"></p>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ $downloadPdfUrl }}" download class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-md text-sm font-medium hover:bg-gray-900 cursor-pointer">
                        Download PDF
                    </a>
                    @if (!empty($fullReportUrl))
                        <a href="{{ $fullReportUrl }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer">
                            Open full report &amp; print
                        </a>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
<script>
    (function() {
        var suffix = @json($suffix);
        var statusEl = document.getElementById('shiftReportShareStatus_' + suffix);
        var shareBtn = document.getElementById('shiftReportShareBtn_' + suffix);
        var shareUrlRaw = @json($sharePdfUrl ?? '');
        /** Relative signed URLs work on the current origin (localhost / ngrok); WhatsApp text needs absolute. */
        function shiftReportResolveShareUrl(raw) {
            var s = String(raw || '').trim();
            if (!s) return '';
            if (/^https?:\/\//i.test(s)) return s;
            var origin = window.location.origin || '';
            if (!origin) return s;
            return origin + (s.startsWith('/') ? s : '/' + s);
        }
        var shareUrl = shiftReportResolveShareUrl(shareUrlRaw);
        var shareTitle = @json($shareTitle ?? 'Demo Store One — shift closing report');
        var shareText = @json($shareText ?? '');

        if (shareBtn && shareUrl && statusEl) {
            var payload = {
                title: shareTitle,
                text: shareText,
                url: shareUrl
            };
            var canNative = typeof navigator.share === 'function' && (!navigator.canShare || navigator.canShare(payload));
            if (canNative) {
                shareBtn.classList.remove('hidden');
                shareBtn.addEventListener('click', async function() {
                    statusEl.classList.remove('text-red-600', 'text-green-700');
                    statusEl.textContent = '';
                    try {
                        await navigator.share(payload);
                    } catch (e) {
                        if (e.name !== 'AbortError') {
                            statusEl.textContent = 'Share failed. Try Download PDF or Copy report link.';
                            statusEl.classList.add('text-red-600');
                        }
                    }
                });
            } else if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                shareBtn.textContent = 'Copy report link';
                shareBtn.classList.remove('hidden');
                shareBtn.addEventListener('click', async function() {
                    statusEl.classList.remove('text-red-600');
                    try {
                        await navigator.clipboard.writeText(shareUrl);
                        statusEl.textContent = 'Secure PDF link copied. Paste into WhatsApp or any app.';
                        statusEl.classList.add('text-green-700');
                    } catch (e) {
                        statusEl.textContent = 'Could not copy. Use Download PDF instead.';
                        statusEl.classList.add('text-red-600');
                    }
                });
            }
        }
    })();
</script>
