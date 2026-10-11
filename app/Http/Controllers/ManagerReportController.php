<?php

namespace App\Http\Controllers;

use App\Models\Rental;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Support\RentalFinancialSummary;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ManagerReportController extends Controller
{
    public function index(Request $request): View
    {
        $data = $this->buildReportData($request);

        return view('reports.manager', $data);
    }

    public function downloadPdf(Request $request): Response
    {
        $data = $this->buildReportData($request);
        if (class_exists(Pdf::class)) {
            $pdf = Pdf::loadView('reports.manager-pdf', $data);

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="manager-transaction-report.pdf"',
            ]);
        }
        if (class_exists(Dompdf::class)) {
            $html = view('reports.manager-pdf', $data)->render();
            $dompdf = new Dompdf;
            $dompdf->loadHtml($html);
            $dompdf->render();

            return response($dompdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="manager-transaction-report.pdf"',
            ]);
        }

        abort(500, 'Dompdf is not installed.');
    }

    private function pricingTaxPercent(): float
    {
        return max(0, (float) StoreCache::get('pricing_tax_percent', 0));
    }

    /**
     * @return array{gross: float, net: float, discount: float}
     */
    private function rentalAmounts(Rental $rental): array
    {
        $fin = RentalFinancialSummary::summarize($rental, $this->pricingTaxPercent());
        $discountTotal = (float) $fin['item_discount_total'] + (float) $fin['order_discount_amount'];

        // Overdue fines are attributed to the day they were applied (see buildReportData),
        // not the rent's creation day — a fine that arises later must not retroactively
        // change an already-closed day's report. Booked figures here exclude them.
        $overdueFee = max(0, (float) ($fin['overdue_fee_amount'] ?? 0));

        return [
            'gross' => (float) $fin['gross'],
            'net' => (float) ($fin['net_excl_tax'] ?? $fin['pre_tax_net']) - $overdueFee,
            'discount' => max(0, $discountTotal),
            // Extra-day charges are part of the original bill and stay on creation day.
            'fees' => max(0, (float) ($fin['extra_charge'] ?? 0)),
            // Balance the customer still owes (incl. tax basis, matching what they are billed).
            'outstanding' => max(0.0, (float) ($fin['net_incl_tax'] ?? 0)
                - max(0.0, (float) ($rental->amount_paid ?? 0))
                - max(0.0, (float) ($rental->overdue_amount_paid ?? 0))),
        ];
    }

    /**
     * @return array{period:string,date_from:string,date_to:string,status:string|null,year:int,month:int,day:string,month_from:?string,month_to:?string}
     */
    private function resolveFilters(Request $request): array
    {
        $period = (string) $request->input('period', 'daily');
        if (! in_array($period, ['daily', 'monthly', 'yearly', 'custom'], true)) {
            $period = 'daily';
        }

        $status = $request->input('status');
        $status = in_array($status, ['active', 'returned', 'cancelled'], true) ? $status : null;

        $now = now();
        $year = (int) $request->input('year', (int) $now->year);
        $month = (int) $request->input('month', (int) $now->month);
        $monthYear = (string) $request->input('month_year', '');
        if (preg_match('/^\d{4}-\d{2}$/', $monthYear) === 1) {
            [$yearPart, $monthPart] = explode('-', $monthYear, 2);
            $year = (int) $yearPart;
            $month = (int) $monthPart;
        }
        $day = (string) $request->input('day', $now->toDateString());
        $monthFrom = trim((string) $request->input('month_from', ''));
        $monthTo = trim((string) $request->input('month_to', ''));
        if (! preg_match('/^\d{4}-\d{2}$/', $monthFrom)) {
            $monthFrom = '';
        }
        if (! preg_match('/^\d{4}-\d{2}$/', $monthTo)) {
            $monthTo = '';
        }

        if ($period === 'daily') {
            $date = Carbon::parse($day);
            $dateFrom = $date->toDateString();
            $dateTo = $date->toDateString();
        } elseif ($period === 'yearly') {
            $dateFrom = now()->setDate($year, 1, 1)->toDateString();
            $dateTo = now()->setDate($year, 12, 31)->toDateString();
        } elseif ($period === 'custom') {
            $dateFrom = (string) ($request->input('date_from') ?: $now->startOfMonth()->toDateString());
            $dateTo = (string) ($request->input('date_to') ?: $now->toDateString());
        } else {
            $month = min(12, max(1, $month));
            $dateFrom = now()->setDate($year, $month, 1)->startOfMonth()->toDateString();
            $dateTo = now()->setDate($year, $month, 1)->endOfMonth()->toDateString();
        }

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        if ($monthFrom !== '' || $monthTo !== '') {
            $mf = $monthFrom !== '' ? $monthFrom : $monthTo;
            $mt = $monthTo !== '' ? $monthTo : $monthFrom;
            if ($mf !== '' && $mt !== '') {
                $from = Carbon::createFromFormat('Y-m', $mf)->startOfMonth();
                $to = Carbon::createFromFormat('Y-m', $mt)->endOfMonth();
                if ($from->greaterThan($to)) {
                    [$from, $to] = [$to->copy()->startOfMonth(), $from->copy()->endOfMonth()];
                    [$mf, $mt] = [$mt, $mf];
                }
                $dateFrom = $from->toDateString();
                $dateTo = $to->toDateString();
                $monthFrom = $mf;
                $monthTo = $mt;
            }
        }

        return [
            'period' => $period,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'status' => $status,
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'month_from' => $monthFrom !== '' ? $monthFrom : null,
            'month_to' => $monthTo !== '' ? $monthTo : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildReportData(Request $request): array
    {
        $filters = $this->resolveFilters($request);
        $pricingEnabled = StoreCache::get('pricing_enabled', true);

        // Transactions are attributed to the day they were recorded (created_at),
        // matching the shift system — not the day the rental period starts.
        $query = Rental::with(['outfits', 'handledBy', 'shiftSession'])
            ->whereDate('created_at', '>=', $filters['date_from'])
            ->whereDate('created_at', '<=', $filters['date_to'])
            ->latest();

        if ($filters['status']) {
            $query->where('status', $filters['status']);
        }

        $rentals = $query->get();
        $grossRevenue = 0.0;
        $netRevenue = 0.0;
        $discountTotal = 0.0;
        $feesTotal = 0.0;
        $outstandingTotal = 0.0;
        $byAdmin = [];

        foreach ($rentals as $rental) {
            $amounts = $this->rentalAmounts($rental);
            $grossRevenue += $amounts['gross'];
            $netRevenue += $amounts['net'];
            $discountTotal += $amounts['discount'];
            $feesTotal += $amounts['fees'];
            if ($rental->status !== 'cancelled') {
                $outstandingTotal += $amounts['outstanding'];
            }

            $adminKey = (string) ($rental->handled_by_user_id ?? 0);
            $adminName = $rental->handledBy?->name ?? 'Unassigned';
            if (! isset($byAdmin[$adminKey])) {
                $byAdmin[$adminKey] = [
                    'name' => $adminName,
                    'transaction_count' => 0,
                    'gross_revenue' => 0.0,
                    'discount_total' => 0.0,
                    'net_revenue' => 0.0,
                    'collected' => 0.0,
                    'average_transaction' => 0.0,
                ];
            }
            $byAdmin[$adminKey]['transaction_count']++;
            $byAdmin[$adminKey]['gross_revenue'] += $amounts['gross'];
            $byAdmin[$adminKey]['discount_total'] += $amounts['discount'];
            $byAdmin[$adminKey]['net_revenue'] += $amounts['net'];
        }

        // Overdue fines count on the day they were applied, regardless of when the
        // fined rent was booked, so past days' reports stay stable once closed.
        $overdueFeeRentals = Rental::with('handledBy')
            ->whereNotNull('overdue_fee_applied_at')
            ->whereDate('overdue_fee_applied_at', '>=', $filters['date_from'])
            ->whereDate('overdue_fee_applied_at', '<=', $filters['date_to'])
            ->where('overdue_fee_amount', '>', 0)
            ->get();
        foreach ($overdueFeeRentals as $rental) {
            $fee = max(0.0, (float) $rental->overdue_fee_amount);
            $feesTotal += $fee;
            $netRevenue += $fee;

            $adminKey = (string) ($rental->handled_by_user_id ?? 0);
            if (! isset($byAdmin[$adminKey])) {
                $byAdmin[$adminKey] = [
                    'name' => $rental->handledBy?->name ?? 'Unassigned',
                    'transaction_count' => 0,
                    'gross_revenue' => 0.0,
                    'discount_total' => 0.0,
                    'net_revenue' => 0.0,
                    'collected' => 0.0,
                    'average_transaction' => 0.0,
                ];
            }
            $byAdmin[$adminKey]['net_revenue'] += $fee;
        }

        // Cash actually received in the period: every payment record (down payments,
        // settlements, overdue fines) counts on the day it was recorded, regardless of
        // when the rent itself was booked. whereHas('rental') applies the store scope.
        $paymentRecords = RentalPaymentRecord::query()
            ->with('receivedBy')
            ->whereHas('rental')
            ->whereDate('recorded_at', '>=', $filters['date_from'])
            ->whereDate('recorded_at', '<=', $filters['date_to'])
            ->get();
        $cashReceived = 0.0;
        foreach ($paymentRecords as $record) {
            $cashReceived += (float) $record->amount;
            $adminKey = (string) ($record->received_by_user_id ?? 0);
            if (! isset($byAdmin[$adminKey])) {
                $byAdmin[$adminKey] = [
                    'name' => $record->receivedBy?->name ?? 'Unassigned',
                    'transaction_count' => 0,
                    'gross_revenue' => 0.0,
                    'discount_total' => 0.0,
                    'net_revenue' => 0.0,
                    'collected' => 0.0,
                    'average_transaction' => 0.0,
                ];
            }
            $byAdmin[$adminKey]['collected'] += (float) $record->amount;
        }

        foreach ($byAdmin as $key => $row) {
            $byAdmin[$key]['average_transaction'] = $row['transaction_count'] > 0
                ? ($row['net_revenue'] / $row['transaction_count'])
                : 0.0;
        }

        $transactionCount = $rentals->count();
        $averageTransaction = $transactionCount > 0 ? ($netRevenue / $transactionCount) : 0.0;
        $storeId = StoreContext::storeId();
        $storeName = null;
        if ($storeId !== null) {
            $storeName = Store::query()->where('id', $storeId)->value('name');
        }
        $scopeLabel = StoreContext::allStores() ? 'All Stores' : ($storeName ?: 'Current Store');

        $discountRentals = $rentals->filter(function (Rental $r) {
            if ((float) ($r->discount ?? 0) > 0.0) {
                return true;
            }

            return $r->outfits->contains(fn ($s) => (float) ($s->pivot->discount ?? 0) > 0.0);
        })->values();

        $shifts = ShiftSession::query()
            ->with(['openedBy'])
            ->whereDate('opened_at', '>=', $filters['date_from'])
            ->whereDate('opened_at', '<=', $filters['date_to'])
            ->orderByDesc('opened_at')
            ->get();

        return [
            'rentals' => $rentals,
            'filters' => $filters,
            'pricingEnabled' => $pricingEnabled,
            'taxPercent' => $this->pricingTaxPercent(),
            'byAdmin' => collect($byAdmin)->sortByDesc('net_revenue')->values(),
            'storeScopeLabel' => $scopeLabel,
            'discountRentals' => $discountRentals,
            'shifts' => $shifts,
            'metrics' => [
                'transaction_count' => $transactionCount,
                'gross_revenue' => $grossRevenue,
                'discount_total' => $discountTotal,
                'fees_total' => $feesTotal,
                'net_revenue' => $netRevenue,
                'cash_received' => $cashReceived,
                'outstanding_total' => $outstandingTotal,
                'average_transaction' => $averageTransaction,
            ],
        ];
    }
}
