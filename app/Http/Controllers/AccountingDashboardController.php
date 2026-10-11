<?php

namespace App\Http\Controllers;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Rental;
use App\Models\RentalPaymentRecord;
use App\Support\RentalAnomalyDetector;
use App\Support\RentalCheckMarks;
use App\Support\RentalLedgerSync;
use App\Support\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountingDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $dateFrom = \App\Support\DateInput::date($request->input('date_from'), now()->startOfMonth()->toDateString());
        $dateTo = \App\Support\DateInput::date($request->input('date_to'), now()->toDateString());

        $cashIn = (float) CashMovement::query()
            ->whereDate('movement_date', '>=', $dateFrom)->whereDate('movement_date', '<=', $dateTo)
            ->where('direction', 'in')
            ->sum('amount');
        $cashOut = (float) CashMovement::query()
            ->whereDate('movement_date', '>=', $dateFrom)->whereDate('movement_date', '<=', $dateTo)
            ->where('direction', 'out')
            ->sum('amount');
        $totalExpenses = (float) Expense::query()
            ->whereDate('expense_date', '>=', $dateFrom)->whereDate('expense_date', '<=', $dateTo)
            ->sum('amount');
        // whereHas('rental') alone excludes cancelled (soft-deleted) rentals'
        // payment records AND respects the current store context — both are
        // global scopes already registered on Rental, so a fresh Rental
        // subquery built by whereHas picks them up for free.
        $rentalPayments = RentalPaymentRecord::query()
            ->whereHas('rental')
            ->whereBetween('recorded_at', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59']);
        $rentalRevenue = (float) (clone $rentalPayments)->sum('amount');
        // Same split the business's own cash-in list keeps: cash vs. everything
        // else (QRIS/transfer all land in Bank).
        $rentalCash = (float) (clone $rentalPayments)->where('payment_method', 'cash')->sum('amount');
        $rentalBank = $rentalRevenue - $rentalCash;
        // The business's own way of counting revenue: cash received this period,
        // minus what was collected for rentals booked BEFORE the period (their
        // older transactions being settled now), so nothing is counted twice.
        $pelunasanLamaQuery = RentalPaymentRecord::query()
            ->whereHas('rental', fn ($q) => $q->whereDate('created_at', '<', $dateFrom))
            ->whereBetween('recorded_at', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'])
            ->whereIn('payment_kind', ['final_payment', 'down_payment', 'full']);
        $pelunasanLama = (float) (clone $pelunasanLamaQuery)->sum('amount');
        $pelunasanLamaJumlah = (int) (clone $pelunasanLamaQuery)->distinct()->count('rental_id');
        $pendapatanDiakuiUpdate = $rentalRevenue - $pelunasanLama;
        $pelunasanLamaList = (clone $pelunasanLamaQuery)
            ->with('rental')
            ->orderBy('recorded_at')
            ->get()
            ->map(fn ($p) => [
                'nama' => $p->rental?->customer_name,
                'nota' => $p->rental_id,
                'dibooking' => $p->rental?->created_at,
                'paid_at' => $p->recorded_at,
                'metode' => $p->payment_method,
                'jumlah' => (float) $p->amount,
            ]);
        // The same receivable seen the way the business's own list counts it:
        // only rentals BOOKED inside the filtered range, each measured by what
        // it still owes today's bill minus payments up to $dateTo. The figure
        // above is the running balance of every rental (incl. older ones), so
        // the two answer different questions and can legitimately differ.
        $piutangPeriode = 0.0;
        $piutangKontrak = 0.0;
        $piutangDibayar = 0.0;
        $piutangJumlahRental = 0;
        // Every rent booked in the period (not only the unpaid ones), so the card
        // shows how its bill splits into money already in Cash Collected and receivables.
        $periodeRental = 0;
        $periodeTagihan = 0.0;
        $periodeDibayar = 0.0;
        $periodeLebihBayar = 0.0;
        Rental::query()
            ->where('is_tutorial', false)
            ->whereIn('status', ['active', 'returned'])
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->with('outfits')
            ->chunkById(100, function ($chunk) use (&$piutangPeriode, &$piutangKontrak, &$piutangDibayar, &$piutangJumlahRental, &$periodeRental, &$periodeTagihan, &$periodeDibayar, &$periodeLebihBayar, $dateTo) {
                foreach ($chunk as $rental) {
                    $b = RentalLedgerSync::balanceAsOf($rental, $dateTo);
                    if ($b['paid'] > 0.004) {
                        $periodeRental++;
                        $periodeTagihan += $b['due'];
                        $periodeDibayar += $b['paid'];
                        $periodeLebihBayar += max(0.0, $b['paid'] - $b['due']);
                    }
                    if ($b['remaining'] > 0.004) {
                        $piutangPeriode += $b['remaining'];
                        $piutangKontrak += $b['due'];
                        $piutangDibayar += $b['paid'];
                        $piutangJumlahRental++;
                    }
                }
            });

        // Orders booked in the period, summarised (the full list lives on Rents).
        $orderCounts = Rental::query()
            ->where('is_tutorial', false)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        // Rent money handed back because a rent was approved as FREE (tracked on
        // the approval itself, so it shows even while journals are in trial mode).
        $freeRefunds = \App\Models\RentalApprovalRequest::query()
            ->where('status', 'approved')
            ->where('payload->kind', \App\Support\RentalFreeRequest::KIND)
            ->whereBetween('decided_at', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'])
            ->get()
            ->filter(fn ($a) => (float) ($a->payload['refund_amount'] ?? 0) > 0.004);
        $freeRefundTotal = (float) $freeRefunds->sum(fn ($a) => (float) $a->payload['refund_amount']);

        // Rents likely to disagree with the store's manual sheet, for a quick check.
        [$perluDicek, $sudahDicek] = RentalCheckMarks::split(RentalAnomalyDetector::forPeriod($dateFrom, $dateTo));

        return view('accounting.dashboard', [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'cashIn' => $cashIn,
            'cashOut' => $cashOut,
            'rentalRevenue' => $rentalRevenue,
            'piutangPeriode' => $piutangPeriode,
            'piutangKontrak' => $piutangKontrak,
            'piutangDibayar' => $piutangDibayar,
            'piutangJumlahRental' => $piutangJumlahRental,
            'periodeRental' => $periodeRental,
            'periodeTagihan' => $periodeTagihan,
            'periodeDibayar' => $periodeDibayar,
            'periodeLebihBayar' => $periodeLebihBayar,
            'rentalCash' => $rentalCash,
            'rentalBank' => $rentalBank,
            'pelunasanLama' => $pelunasanLama,
            'pelunasanLamaJumlah' => $pelunasanLamaJumlah,
            'pendapatanDiakuiUpdate' => $pendapatanDiakuiUpdate,
            'pelunasanLamaList' => $pelunasanLamaList,
            'totalExpenses' => $totalExpenses,
            'orderCounts' => $orderCounts,
            'perluDicek' => $perluDicek,
            'sudahDicek' => $sudahDicek,
            'freeRefundTotal' => $freeRefundTotal,
            'freeRefundCount' => $freeRefunds->count(),
        ]);
    }

    /** Marks a rent's current problems (for the viewed period) as checked. */
    public function markChecked(Request $request, int $rentalId): RedirectResponse
    {
        $dateFrom = \App\Support\DateInput::date($request->input('date_from'), now()->startOfMonth()->toDateString());
        $dateTo = \App\Support\DateInput::date($request->input('date_to'), now()->toDateString());

        // Recomputed server-side: the mark covers what the list actually shows.
        $row = RentalAnomalyDetector::forPeriod($dateFrom, $dateTo)->first(fn ($r) => $r['rental']->id === $rentalId);
        abort_if($row === null, 404);
        RentalCheckMarks::mark($rentalId, $row['flags'], (string) $request->user()->name);

        return redirect()->route('accounting.dashboard', ['date_from' => $dateFrom, 'date_to' => $dateTo])
            ->withFragment('perluDicekList');
    }

    public function unmarkChecked(Request $request, int $rentalId): RedirectResponse
    {
        RentalCheckMarks::unmark($rentalId);

        return redirect()->route('accounting.dashboard', array_filter([
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ]))->withFragment('perluDicekList');
    }
}
