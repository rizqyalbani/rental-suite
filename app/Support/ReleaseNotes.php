<?php

namespace App\Support;

/**
 * Plain changelog for the Help page — maintained by hand in code (append a new
 * entry here whenever real work ships), not through any admin UI. Ordered
 * newest-first; help/release-notes.blade.php shows the first entry as
 * "current" and the rest as archived history, so nothing needs deleting or a
 * separate archive flag — an entry becomes history simply by no longer being
 * first.
 */
class ReleaseNotes
{
    /**
     * @return array<int, array{date: string, title: string, highlights: array<int, string>}>
     */
    public static function all(): array
    {
        return [
            [
                'date' => '2026-09-10',
                'title' => 'Receivables, VAT, Fixed Assets, and Depreciated Supplies',
                'highlights' => [
                    'New Receivables report — lists rentals that have received a deposit (DP) but are not fully paid, so it is clear which customers still owe the store.',
                    'VAT report (preview) — estimated 10% VAT per date range, without posting anything to the books. The "VAT Questions for the Client" checklist also grew from 8 to 10 questions, to be asked to the accountant / tax consultant before VAT is really applied.',
                    'Fixed Assets: Equipment (racks, etc.) can now have its depreciation recorded straight from that page, with a history per item.',
                    'Supplies (hangers, soap, etc.) are now really depreciated gradually, as requested — not treated as used up the moment they are bought.',
                    'Removing an item from an already-paid rental now automatically records the refund in the books, instead of just a note with no money value.',
                    'Fixed: the Super Admin rentals page now only shows the store currently selected in the sidebar, not all stores at once.',
                    'Fixed: WhatsApp numbers (Fonnte) are now truly separate per store — sending an invoice from one store can no longer use another store\'s token.',
                    'New tutorials for all the features above, plus a help button right on the Removal Requests, Edit Requests, and My Requests pages.',
                    'This "Release Notes" page itself is new, so the notes for every update are collected in one place.',
                ],
            ],
            [
                'date' => '2026-08-25',
                'title' => 'Full Accounting module: financial reports and practice mode',
                'highlights' => [
                    '4 financial reports: Trial Balance, Balance Sheet, Profit & Loss, and General Ledger — all downloadable as PDF.',
                    'Rental payments are automatically recorded in the books as soon as they are received, so there is no need to enter them twice by hand.',
                    'Practice mode (Trial Mode) — try out entries in Accounting without affecting the real figures, to learn first before using it for real.',
                    'Entry corrections (reversing journal entries) when something was recorded wrongly, with the history still visible.',
                    'A "?" help button on every Accounting page, explaining that page directly.',
                ],
            ],
            [
                'date' => '2026-08-09',
                'title' => 'Accounting module Phase 1 launched',
                'highlights' => [
                    'Chart of Accounts and double-entry bookkeeping — the foundation for all financial reports going forward.',
                    'Accounting dashboard: summary of revenue, cash, and expenses per date range.',
                    'Expenses & Cash In/Out — record expenses and cash movements from separate pages.',
                    'Interactive tutorials (coach-marks) were added to the system for the first time, starting with the rental approval flow.',
                ],
            ],
            [
                'date' => '2026-07-31',
                'title' => 'Approval system for rental edits',
                'highlights' => [
                    'Changes to an existing rental now need approval from a superior first (except when a Supervisor/Management/Super Admin edits directly).',
                    'New pages: "My Requests" (follow the status of your own requests) and "Edit Requests" (for those who approve).',
                    'The request card shows exactly what changed, compared with the data currently saved.',
                    'A per-item activity log was added to track the history of rental changes.',
                ],
            ],
        ];
    }

    public static function current(): array
    {
        return self::all()[0];
    }

    /**
     * @return array<int, array{date: string, title: string, highlights: array<int, string>}>
     */
    public static function archived(): array
    {
        return array_slice(self::all(), 1);
    }
}
