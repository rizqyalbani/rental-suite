{{-- Popup announcing the CURRENT release note (App\Support\ReleaseNotes) to
     Management/Super Admin, unlike whats-new-accounting.blade.php's
     one-time-ever SweetAlert2: this one fires EVERY time either of those
     roles visits the Help page — no localStorage "seen" flag at all — since
     Help is where someone deliberately goes to check what's new, not a page
     they land on incidentally. Full history is always at Help → Release Notes
     for anyone who wants more than the current headline. Also replayable
     manually via rentalOpenWhatsNewRelease(). --}}
@if(Auth::check() && (Auth::user()->isManagement() || Auth::user()->isSuperAdmin()) && request()->routeIs('help.index'))
@php
    $whatsNewRelease = \App\Support\ReleaseNotes::current();
@endphp
<script>
(function () {
    var release = @json($whatsNewRelease);

    function formatReleaseDate(iso) {
        var parts = iso.split('-');
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return parseInt(parts[2], 10) + ' ' + months[parseInt(parts[1], 10) - 1] + ' ' + parts[0];
    }

    function openWhatsNewRelease() {
        var items = release.highlights.map(function (point) {
            return '<li style="margin-bottom:.7rem;">' + point + '</li>';
        }).join('');

        window.Swal.fire({
            icon: 'info',
            title: 'Latest release: ' + release.title,
            html: `
                <div style="text-align:left; font-size:0.9rem; line-height:1.75; color:#374151;">
                    <p style="margin:0 0 .75rem 0; font-size:0.78rem; color:#9ca3af; text-transform:uppercase; letter-spacing:.03em;">${formatReleaseDate(release.date)}</p>
                    <ul style="margin:0 0 1rem 0; padding-left:1.3rem; list-style:disc;">${items}</ul>
                    <p style="margin:0; color:#6b7280;">Earlier releases can be viewed anytime on the <b>Help &rarr; Release Notes</b> page.</p>
                </div>
            `,
            confirmButtonText: 'Got it',
            width: 620,
        });
    }

    window.rentalOpenWhatsNewRelease = openWhatsNewRelease;

    function whenDomReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    whenDomReady(function () { setTimeout(openWhatsNewRelease, 400); });
})();
</script>
@endif
