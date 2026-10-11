<?php

namespace App\Http\Controllers;

use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Outfit;
use App\Models\TutorialRental;
use App\Models\TutorialRentalActivityLog;
use App\Models\TutorialShiftSession;
use App\Models\User;
use App\Support\RentalFinancialSummary;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ShiftController extends Controller
{
    public function open(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        if (! $user->mayOperateRentTransactions()) {
            return redirect()->route('rentals.index')->with('error', 'Your account cannot open shifts.');
        }

        $tutorialPractice = $request->boolean('tutorial_practice');
        $shiftClass = $tutorialPractice ? TutorialShiftSession::class : ShiftSession::class;

        $existing = $shiftClass::where('opened_by_user_id', '=', $user->id, 'and')
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();

        if ($existing) {
            return redirect()->route('rentals.index')->with('error', 'You already have an open shift.');
        }

        $validated = $request->validate([
            'period' => ['required', 'in:morning,afternoon'],
            'opening_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $shift = $shiftClass::create([
            'opened_by_user_id' => $user->id,
            'opened_at' => now(),
            'period' => $validated['period'],
            'opening_note' => $validated['opening_note'] ?? null,
        ]);

        if ($tutorialPractice) {
            // Practice shifts start with example transactions already in them —
            // otherwise the running totals and report the tour walks through
            // would just show zeroes, which teaches nothing.
            $this->seedTutorialShiftRentals($shift, $user);
            TutorialRentalActivityLog::recordShiftEvent($shift, 'shift_opened', $user->id);
        } else {
            RentalActivityLog::recordShiftEvent($shift, 'shift_opened', $user->id);
        }

        return redirect()->route('rentals.index')->with('success', 'Shift opened successfully.');
    }

    public function close(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        if (! $user->mayOperateRentTransactions()) {
            return redirect()->route('rentals.index')->with('error', 'Your account cannot close shifts.');
        }

        $tutorialPractice = $request->boolean('tutorial_practice');
        $shiftClass = $tutorialPractice ? TutorialShiftSession::class : ShiftSession::class;

        $shift = $shiftClass::where('opened_by_user_id', '=', $user->id, 'and')
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();

        if (! $shift) {
            return redirect()->route('rentals.index')->with('error', 'No open shift found.');
        }

        $totals = \App\Support\ShiftClosedTotals::calculate($shift);

        $shift->update([
            'closed_by_user_id' => $user->id,
            'closed_at' => now(),
            'closing_note' => $request->input('closing_note'),
            'total_transactions' => $totals['transactions'],
            'total_revenue' => $totals['received'],
        ]);

        if ($tutorialPractice) {
            TutorialRentalActivityLog::recordShiftEvent($shift, 'shift_closed', $user->id);
        } else {
            RentalActivityLog::recordShiftEvent($shift, 'shift_closed', $user->id);
        }

        return redirect()->route('rentals.index')
            ->with('success', 'Shift closed successfully.');
    }

    /**
     * Begins a practice shift SESSION (session-scoped, no data changes here) —
     * the Real/Practice tab switch on Shift Control only exists at all while a
     * session is ongoing (see buildShiftControlContext()'s $tutorialShiftActive);
     * outside of one, the card is real-only, matching production exactly. Only
     * the shift tutorial itself starts a session (fired automatically the
     * moment its "Start" is clicked on the Help page, before the tour even
     * navigates anywhere) — there's no standalone entry point for this
     * elsewhere. Reused for the "Practice" tab click within an already-ongoing
     * session too (harmless to re-affirm active=true there).
     */
    public function tutorialStart(Request $request): RedirectResponse
    {
        $request->session()->put('shift_tutorial_active', true);
        $request->session()->put('shift_tutorial_view', 'latihan');

        return redirect()->route('rentals.index');
    }

    /**
     * Switches the Shift Control card's slot to the real shift WITHOUT ending
     * the practice session (the tab switch itself stays visible) — a view
     * preference only, does not touch any in-progress practice shift. Only
     * "Exit practice" (tutorialExit) actually ends the session.
     */
    public function tutorialViewReal(Request $request): RedirectResponse
    {
        $request->session()->put('shift_tutorial_view', 'real');

        return redirect()->route('rentals.index');
    }

    /**
     * Ends the practice session — the Real/Practice tab switch disappears
     * entirely afterward, leaving Shift Control real-only again — and wipes
     * this user's practice shifts (and whatever example rentals were seeded
     * into them) so repeated runs of the tutorial don't pile up
     * harmless-but-pointless rows.
     */
    public function tutorialExit(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->session()->forget('shift_tutorial_active');
        $request->session()->forget('shift_tutorial_view');

        if ($user) {
            $shiftIds = TutorialShiftSession::where('opened_by_user_id', $user->id)->pluck('id');
            TutorialRental::where('created_by_user_id', $user->id)
                ->whereIn('shift_session_id', $shiftIds)
                ->get()
                ->each->forceDelete();
            TutorialShiftSession::where('opened_by_user_id', $user->id)->delete();
        }

        return redirect()->route('rentals.index');
    }

    /**
     * Two example transactions dropped straight into a freshly-opened
     * practice shift, using the same shared tutorial items (Outfit rows tagged
     * is_tutorial) the rest of the onboarding tour already uses — so the
     * "Running summary" and report steps have real, non-zero numbers to
     * point at instead of an empty shift.
     */
    private function seedTutorialShiftRentals(TutorialShiftSession $shift, User $user): void
    {
        $outfits = Outfit::withoutGlobalScope('tutorial_scope')->where('is_tutorial', true)->orderBy('code')->limit(2)->get();
        if ($outfits->isEmpty()) {
            return;
        }

        $start = now()->toDateString();
        $end = now()->addDays(3)->toDateString();

        foreach ($outfits as $outfit) {
            $price = (float) $outfit->price;
            $rental = TutorialRental::create([
                'customer_name' => 'Sample Customer',
                'phone' => '000000000',
                'start_date' => $start,
                'end_date' => $end,
                'status' => 'active',
                'handled_by_user_id' => $user->id,
                'created_by_user_id' => $user->id,
                'shift_session_id' => $shift->id,
                'payment_method' => 'cash',
                'payment_kind' => 'full',
                'amount_paid' => $price,
            ]);
            $rental->outfits()->attach([
                $outfit->id => ['price_incl_tax' => $price, 'price_excl_tax' => $price, 'tax_amount' => 0],
            ]);
            TutorialRentalActivityLog::record($rental, 'created');
        }
    }

    /**
     * Debug-only: reopen a closed shift so its numbers can be poked at and
     * re-closed while cross-checking reports. Gated by the debug tools toggle.
     */
    public function reopen(ShiftSession $shift, Request $request): RedirectResponse
    {
        abort_unless(\App\Support\DebugTools::enabled(), 404);
        $user = $request->user();
        if (! $user) {
            abort(403);
        }
        // Deliberately narrower than authorizeShiftReport() — Accounting's
        // access to shift reports is read-only, and reopening mutates a
        // closed shift's totals, so it stays limited to the same roles as
        // before Accounting was added to report viewing.
        $canReopen = $user->id === $shift->opened_by_user_id || $user->isSuperAdmin() || $user->isManagement();
        abort_unless($canReopen, 403);

        if ($shift->closed_at === null) {
            return redirect()->route('shifts.report', $shift)->with('error', 'Shift is already open.');
        }

        $stillOpen = ShiftSession::where('opened_by_user_id', $shift->opened_by_user_id)
            ->whereNull('closed_at')
            ->exists();
        if ($stillOpen) {
            return redirect()->route('shifts.report', $shift)
                ->with('error', 'This admin already has another open shift. Close it first.');
        }

        $shift->update([
            'closed_by_user_id' => null,
            'closed_at' => null,
            'closing_note' => null,
            'total_transactions' => 0,
            'total_revenue' => 0,
        ]);

        return redirect()->route('shifts.report', $shift)->with('success', 'Shift reopened (debug).');
    }

    public function report(ShiftSession $shift, Request $request): View
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $this->authorizeShiftReport($user, $shift);
        $data = $this->prepareShiftReportData($shift, $request);

        $opened = $shift->opened_at?->format('d M Y H:i') ?? '-';
        $closed = $shift->closed_at?->format('d M Y H:i') ?? '-';

        return view('shifts.report', $data + [
            'downloadPdfUrl' => route('shifts.report.download-pdf', array_merge(['shift' => $shift->id], $request->query())),
            'sharePdfUrl' => $shift->closed_at ? $shift->signedPublicPdfUrl() : null,
            'shareTitle' => ($shift->store?->name ?? 'Demo Store One').' — '.$shift->periodLabel().' shift — closing report #'.$shift->id,
            'shareText' => "Opened: {$opened}\n"
                ."Closed: {$closed}\n"
                .'Transactions: '.(int) ($shift->total_transactions ?? 0)
                .' | Received: Rp '.number_format((float) ($shift->total_revenue ?? 0), 0, ',', '.'),
        ]);
    }

    /**
     * Same report view as report(), for a practice shift — kept as its own
     * route/method (rather than reusing {shift}) because practice shift ids
     * collide with real shift ids (see the {tutorialShift} binding comment
     * in AppServiceProvider), so it needs its own unambiguous binding.
     * Ownership is already enforced by that binding, so no authorizeShiftReport()
     * call here. PDF download/reopen-debug are just hidden in the view for a
     * practice shift — no PDF pipeline exists for practice data.
     */
    public function tutorialReport(TutorialShiftSession $tutorialShift, Request $request): View
    {
        if (! $request->user()) {
            abort(403);
        }

        $data = $this->prepareShiftReportData($tutorialShift, $request);

        return view('shifts.report', $data + [
            'downloadPdfUrl' => null,
            'sharePdfUrl' => null,
            'shareTitle' => null,
            'shareText' => null,
        ]);
    }

    public function downloadPdf(ShiftSession $shift, Request $request): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $this->authorizeShiftReport($user, $shift);
        $binary = $this->buildShiftReportPdfBinary($shift, $request);
        $name = $this->shiftReportFilename($shift, $user);

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    /**
     * Signed URL target for external fetchers (e.g. shared PDF links in WhatsApp).
     */
    public function publicPdf(ShiftSession $shift, Request $request): Response
    {
        if (! $request->hasValidSignature(false)) {
            abort(403);
        }

        if ($shift->closed_at === null) {
            abort(404);
        }

        $binary = $this->buildShiftReportPdfBinary($shift, $request);
        $name = $this->shiftReportFilename($shift, null);

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
        ]);
    }

    public function pdfDebug(ShiftSession $shift, Request $request): View
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $this->authorizeShiftReport($user, $shift);
        $data = $this->prepareShiftReportData($shift, $request);

        return view('shifts.report-pdf', $data + ['debugHtml' => true]);
    }

    /**
     * Debug-only: list shifts opened on a given date so staff can cross-check
     * the daily manager report against individual shift reports.
     */
    public function byDate(Request $request): JsonResponse
    {
        abort_unless(\App\Support\DebugTools::enabled(), 404);
        $user = $request->user();
        abort_unless((bool) $user, 403);

        $date = (string) $request->query('date', '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['error' => 'Invalid date.'], 422);
        }

        $shifts = ShiftSession::with('openedBy')
            ->whereDate('opened_at', $date)
            ->when(
                ! $user->isSuperAdmin() && ! $user->isManagement(),
                fn ($q) => $q->where('opened_by_user_id', $user->id)
            )
            ->orderBy('opened_at')
            ->get();

        return response()->json([
            'date' => $date,
            'shifts' => $shifts->map(fn (ShiftSession $s) => [
                'id' => $s->id,
                'admin' => $s->openedBy?->name ?? '—',
                'period' => $s->periodLabel(),
                'opened_at' => $s->opened_at?->format('d M Y H:i'),
                'closed_at' => $s->closed_at?->format('d M Y H:i'),
                'open' => $s->closed_at === null,
                'transactions' => $s->total_transactions !== null ? (int) $s->total_transactions : null,
                'revenue' => $s->total_revenue !== null ? (float) $s->total_revenue : null,
                'report_url' => route('shifts.report', $s),
            ])->values(),
        ]);
    }

    /**
     * Same shape as byDate(), but a first-class feature for the Accounting
     * Dashboard rather than a debug tool — gated by mayViewAccountingDashboard()
     * (independent of the Debug Tools toggle) and always shows every shift for
     * the date, not just the caller's own, since only privileged
     * dashboard-viewing roles can reach this at all.
     */
    public function accountingShiftsByDate(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->mayViewAccountingDashboard(), 403);

        $date = (string) $request->query('date', '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['error' => 'Invalid date.'], 422);
        }

        $shifts = ShiftSession::with('openedBy')
            ->whereDate('opened_at', $date)
            ->orderBy('opened_at')
            ->get();

        return response()->json([
            'date' => $date,
            'shifts' => $shifts->map(fn (ShiftSession $s) => [
                'id' => $s->id,
                'admin' => $s->openedBy?->name ?? '—',
                'period' => $s->periodLabel(),
                'opened_at' => $s->opened_at?->format('d M Y H:i'),
                'closed_at' => $s->closed_at?->format('d M Y H:i'),
                'open' => $s->closed_at === null,
                'transactions' => $s->total_transactions !== null ? (int) $s->total_transactions : null,
                'revenue' => $s->total_revenue !== null ? (float) $s->total_revenue : null,
                'report_url' => route('shifts.report', $s),
            ])->values(),
        ]);
    }

    private function authorizeShiftReport(User $user, ShiftSession $shift): void
    {
        $canView = $user->id === $shift->opened_by_user_id
            || $user->isSuperAdmin()
            || $user->isManagement()
            || $user->isAccounting();
        if (! $canView) {
            abort(403);
        }
    }

    private function shiftReportFilename(ShiftSession $shift, ?User $requestUser): string
    {
        $shift->loadMissing(['openedBy', 'closedBy']);

        $adminName = $requestUser?->name
            ?? $shift->closedBy?->name
            ?? $shift->openedBy?->name
            ?? 'admin';

        $shiftName = $shift->periodLabel() ?: 'shift';
        $dt = $shift->closed_at ?? $shift->opened_at ?? now();
        $datePart = $dt->format('d-m-Y');
        $hourPart = $dt->format('H').'h';

        $adminSlug = Str::slug($adminName) ?: 'admin';
        $shiftSlug = Str::slug($shiftName) ?: 'shift';
        $base = "shift-report-{$adminSlug}-{$shiftSlug}-{$datePart}-{$hourPart}";

        $base = substr($base, 0, 180);

        return $base.'.pdf';
    }

    /**
     * @return array{
     *     shift: ShiftSession,
     *     rentals: Collection,
     *     rentalReports: Collection<int, array{rental: Rental, financial: array<string, mixed>}>,
     *     financialTotals: array<string, float>,
     *     totalRevenue: float,
     *     totalTransactions: int,
     *     filters: array<string, mixed>,
     *     byAdmin: Collection<int, array<string, mixed>>,
     *     taxPercent: float,
     *     storeScopeLabel: string
     * }
     */
    private function prepareShiftReportData(ShiftSession $shift, ?Request $request = null): array
    {
        // Build under the shift's own store: management may open a report of the other store.
        return StoreContext::runAs((int) $shift->store_id, fn () => $this->buildShiftReportData($shift, $request));
    }

    private function buildShiftReportData(ShiftSession $shift, ?Request $request = null): array
    {
        $shift->load(['store', 'openedBy', 'closedBy', 'rentals.outfits', 'rentals.handledBy']);
        $filters = $this->resolveFilters($request, $shift);
        $taxPercent = $this->pricingTaxPercent();
        $rentals = $shift->rentals
            ->filter(function ($rental) use ($filters) {
                $transactionDate = $rental->created_at?->toDateString();
                if (! $transactionDate) {
                    return false;
                }
                if ($transactionDate < $filters['date_from'] || $transactionDate > $filters['date_to']) {
                    return false;
                }
                if ($filters['status'] !== null && $rental->status !== $filters['status']) {
                    return false;
                }

                return true;
            });

        $rentals = $rentals->sortByDesc('id')->values();

        $byAdmin = [];
        $totalRevenue = 0.0;
        $totalReceived = 0.0;
        $totalOutstanding = 0.0;
        $financialTotals = [
            'gross' => 0.0,
            'item_discounts' => 0.0,
            'order_discounts' => 0.0,
            'pre_tax' => 0.0,
            'tax' => 0.0,
            'net_incl_tax' => 0.0,
        ];

        // A practice shift has no payment records of its own; rental_payment_records points at REAL
        // shifts and rents that can share the same number, so it is not read for a practice shift.
        $isPractice = $shift instanceof TutorialShiftSession;
        $shiftPaymentRecords = $isPractice
            ? new \Illuminate\Database\Eloquent\Collection
            : RentalPaymentRecord::query()
                ->where('shift_session_id', $shift->id)
                ->get();
        $shiftRecordsByRental = $shiftPaymentRecords->groupBy(fn ($rec) => (int) $rec->rental_id);
        $rentalIdsWithAnyRecord = $isPractice
            ? collect()
            : RentalPaymentRecord::query()
                ->whereIn('rental_id', $rentals->pluck('id'))
                ->distinct()
                ->pluck('rental_id')
                ->map(fn ($v) => (int) $v)
                ->flip();

        $rentalReports = $rentals->map(function ($rental) use ($taxPercent, &$byAdmin, &$totalRevenue, &$totalReceived, &$totalOutstanding, &$financialTotals, $shiftRecordsByRental, $rentalIdsWithAnyRecord) {
            $financial = RentalFinancialSummary::summarize($rental, $taxPercent);
            $net = $financial['net_incl_tax'];
            $totalRevenue += $net;
            $paidOverall = max(0.0, (float) ($rental->amount_paid ?? 0)) + max(0.0, (float) ($rental->overdue_amount_paid ?? 0));
            if (isset($rentalIdsWithAnyRecord[(int) $rental->id])) {
                // Payment records exist: count only money received during this shift.
                $paid = (float) ($shiftRecordsByRental->get((int) $rental->id)?->sum('amount') ?? 0);
                $received = $paid;
            } else {
                // Legacy rentals without payment records: attribute all payments to the creation shift.
                $paid = $paidOverall;
                $received = $net > 0 ? min($paid, $net) : $paid;
            }
            $outstanding = max(0.0, $net - $paidOverall);
            $totalReceived += $received;
            $totalOutstanding += $outstanding;
            $financialTotals['gross'] += $financial['gross'];
            $financialTotals['item_discounts'] += $financial['item_discount_total'];
            $financialTotals['order_discounts'] += $financial['order_discount_amount'];
            $financialTotals['pre_tax'] += $financial['pre_tax_net'];
            $financialTotals['tax'] += $financial['tax_amount'];
            $financialTotals['net_incl_tax'] += $financial['net_incl_tax'];

            $key = (string) ($rental->handled_by_user_id ?? 0);
            $name = $rental->handledBy?->name ?? 'Unassigned';
            if (! isset($byAdmin[$key])) {
                $byAdmin[$key] = [
                    'name' => $name,
                    'transaction_count' => 0,
                    'received' => 0.0,
                    'average_transaction' => 0.0,
                ];
            }
            $byAdmin[$key]['transaction_count']++;
            $byAdmin[$key]['received'] += $received;

            return ['rental' => $rental, 'financial' => $financial, 'paid' => $paid, 'received' => $received, 'outstanding' => $outstanding];
        });

        // Payments taken this shift for rentals created in other shifts (e.g. final payments).
        $externalRentalIds = $shiftPaymentRecords->pluck('rental_id')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->diff($rentals->pluck('id')->map(fn ($v) => (int) $v));
        if ($externalRentalIds->isNotEmpty()) {
            $externalRentals = Rental::query()
                ->with(['outfits', 'handledBy'])
                ->whereIn('id', $externalRentalIds)
                ->orderByDesc('id')
                ->get();
            foreach ($externalRentals as $rental) {
                $financial = RentalFinancialSummary::summarize($rental, $taxPercent);
                $net = (float) ($financial['net_incl_tax'] ?? 0);
                $paidOverall = max(0.0, (float) ($rental->amount_paid ?? 0)) + max(0.0, (float) ($rental->overdue_amount_paid ?? 0));
                $paid = (float) ($shiftRecordsByRental->get((int) $rental->id)?->sum('amount') ?? 0);
                if (abs($paid) <= 0.004) {
                    continue;
                }
                $outstanding = max(0.0, $net - $paidOverall);
                $totalReceived += $paid;
                $totalOutstanding += $outstanding;

                $key = (string) ($rental->handled_by_user_id ?? 0);
                $name = $rental->handledBy?->name ?? 'Unassigned';
                if (! isset($byAdmin[$key])) {
                    $byAdmin[$key] = [
                        'name' => $name,
                        'transaction_count' => 0,
                        'received' => 0.0,
                        'average_transaction' => 0.0,
                    ];
                }
                $byAdmin[$key]['transaction_count']++;
                $byAdmin[$key]['received'] += $paid;

                $rentalReports->push([
                    'rental' => $rental,
                    'financial' => $financial,
                    'paid' => $paid,
                    'received' => $paid,
                    'outstanding' => $outstanding,
                    'from_other_shift' => true,
                ]);
            }
        }

        foreach ($byAdmin as $key => $row) {
            $byAdmin[$key]['average_transaction'] = $row['transaction_count'] > 0
                ? ($row['received'] / $row['transaction_count'])
                : 0.0;
        }

        return [
            'shift' => $shift,
            'rentals' => $rentals,
            'rentalReports' => $rentalReports,
            'financialTotals' => $financialTotals,
            'totalRevenue' => $totalRevenue,
            'totalReceived' => $totalReceived,
            'totalOutstanding' => $totalOutstanding,
            'totalTransactions' => $rentals->count(),
            'filters' => $filters,
            'byAdmin' => collect($byAdmin)->sortByDesc('received')->values(),
            'taxPercent' => $taxPercent,
            'storeScopeLabel' => $shift->store?->name ?? 'Current Store',
            'reportScopedToYourHandledRentals' => false,
            'showAdminSummary' => (bool) $request?->user()?->isManagement(),
        ];
    }

    private function buildShiftReportPdfBinary(ShiftSession $shift, ?Request $request = null): string
    {
        $data = $this->prepareShiftReportData($shift, $request);

        if (class_exists(Pdf::class)) {
            $pdf = Pdf::loadView('shifts.report-pdf', $data);

            return $pdf->output();
        }

        if (class_exists(Dompdf::class)) {
            $html = view('shifts.report-pdf', $data)->render();
            $dompdf = new Dompdf;
            $dompdf->loadHtml($html);
            $dompdf->render();

            return $dompdf->output();
        }

        abort(500, 'Dompdf is not installed.');
    }

    private function calculateRentalNet($rental): float
    {
        return RentalFinancialSummary::netInclTax($rental, $this->pricingTaxPercent());
    }

    private function calculateRentalReceived($rental): float
    {
        $net = RentalFinancialSummary::netInclTax($rental, $this->pricingTaxPercent());
        $paid = max(0.0, (float) ($rental->amount_paid ?? 0)) + max(0.0, (float) ($rental->overdue_amount_paid ?? 0));

        return $net > 0 ? min($paid, $net) : $paid;
    }

    private function pricingTaxPercent(): float
    {
        return max(0, (float) StoreCache::get('pricing_tax_percent', 0));
    }

    /**
     * @return array{period:string,date_from:string,date_to:string,status:string|null,year:int,month:int,day:string}
     */
    private function resolveFilters(?Request $request, ?ShiftSession $shift = null): array
    {
        $now = $shift?->opened_at?->copy() ?? now();
        $period = (string) ($request?->input('period', 'custom') ?? 'custom');
        if (! in_array($period, ['daily', 'monthly', 'yearly', 'custom'], true)) {
            $period = 'custom';
        }

        $status = $request?->input('status');
        $status = in_array($status, ['active', 'returned', 'cancelled'], true) ? $status : null;
        $year = (int) ($request?->input('year', (int) $now->year) ?? $now->year);
        $month = (int) ($request?->input('month', (int) $now->month) ?? $now->month);
        $monthYear = (string) ($request?->input('month_year', '') ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $monthYear) === 1) {
            [$yearPart, $monthPart] = explode('-', $monthYear, 2);
            $year = (int) $yearPart;
            $month = (int) $monthPart;
        }
        $day = (string) ($request?->input('day', $now->toDateString()) ?? $now->toDateString());

        if ($period === 'daily') {
            $date = Carbon::parse($day);
            $dateFrom = $date->toDateString();
            $dateTo = $date->toDateString();
        } elseif ($period === 'yearly') {
            $dateFrom = now()->setDate($year, 1, 1)->toDateString();
            $dateTo = now()->setDate($year, 12, 31)->toDateString();
        } elseif ($period === 'monthly') {
            $month = min(12, max(1, $month));
            $dateFrom = now()->setDate($year, $month, 1)->startOfMonth()->toDateString();
            $dateTo = now()->setDate($year, $month, 1)->endOfMonth()->toDateString();
        } else {
            $shiftOpened = $shift?->opened_at?->toDateString();
            $shiftClosed = $shift?->closed_at?->toDateString();
            $fallbackFrom = $shiftOpened ?? $now->startOfMonth()->toDateString();
            $fallbackTo = $shiftClosed ?? now()->toDateString();
            $dateFrom = (string) ($request?->input('date_from', $fallbackFrom) ?? $fallbackFrom);
            $dateTo = (string) ($request?->input('date_to', $fallbackTo) ?? $fallbackTo);
        }
        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [
            'period' => $period,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'status' => $status,
            'year' => $year,
            'month' => $month,
            'day' => $day,
        ];
    }
}
