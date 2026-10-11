{{-- One-time "what's new" announcement for Management/Super Admin, using SweetAlert2
     (already the app's alert library — see resources/js/app.js's rentalAlert/rentalConfirm
     wrappers) rather than the tour-engine: this is a single summary popup, not a
     step-by-step walkthrough of specific UI elements, so the heavier spotlight engine
     doesn't fit. Auto-shows once (localStorage-tracked, same pattern as
     accounting-help-fab.blade.php's first-timer hint), and can be replayed anytime from
     the Help page via the same rentalOpenWhatsNewAccounting() function. --}}
@if(Auth::check() && (Auth::user()->isManagement() || Auth::user()->isSuperAdmin()))
<script>
(function () {
    var STORAGE_KEY = 'rental_whats_new_accounting_v1_seen_{{ Auth::id() }}';

    function openWhatsNewAccounting() {
        window.Swal.fire({
            icon: 'info',
            title: 'New feature: Accounting Module',
            html: `
                <div style="text-align:left; font-size:0.9rem; line-height:1.5; color:#374151;">
                    <p style="margin:0 0 .75rem 0;">The system now has a full accounting module, separate from Rents:</p>
                    <ul style="margin:0 0 .75rem 0; padding-left:1.1rem;">
                        <li><b>Accounting Dashboard</b> &mdash; summary of revenue, cash, and expenses by date range.</li>
                        <li><b>Chart of Accounts</b> &mdash; the standard account structure (Cash, Bank, Expenses, etc.).</li>
                        <li><b>Expenses &amp; Cash In/Out</b> &mdash; record expenses and cash movements, with corrections (reversing journal) if something was recorded wrongly.</li>
                        <li><b>4 financial reports</b> &mdash; Trial Balance, Balance Sheet, Profit &amp; Loss, General Ledger, downloadable as PDF.</li>
                        <li>Rental payments are <b>recorded automatically</b> in the general ledger as soon as they are received.</li>
                    </ul>
                    <p style="margin:0; color:#6b7280;">The <b>?</b> button on each Accounting page explains that page directly. A full guide is also on the Help page.</p>
                </div>
            `,
            confirmButtonText: 'Got it',
            width: 480,
        });
        try { localStorage.setItem(STORAGE_KEY, '1'); } catch (e) {}
    }

    window.rentalOpenWhatsNewAccounting = openWhatsNewAccounting;

    function whenDomReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    try {
        if (!localStorage.getItem(STORAGE_KEY)) {
            whenDomReady(function () { setTimeout(openWhatsNewAccounting, 400); });
        }
    } catch (e) {}
})();
</script>
@endif
