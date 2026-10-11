<?php

namespace App\Http\Controllers;

use App\Models\Rental;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Support\RentalFinancialSummary;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminTransactionReportController extends Controller
{
    public function index(Request $request): View
    {
        return view('reports.admin-transactions', $this->buildReportData($request));
    }

    public function showShift(Request $request, ShiftSession $shift): View
    {
        $ownedShift = ShiftSession::query()
            ->with('openedBy')
            ->where('id', $shift->id)
            ->where('opened_by_user_id', $request->user()->id)
            ->firstOrFail();

        $data = $this->buildReportData($request, $ownedShift->id);
        $shiftReport = collect($data['shiftReports'] ?? [])->first();

        return view('reports.admin-transaction-shift', [
            'filters' => $data['filters'],
            'pricingEnabled' => $data['pricingEnabled'],
            'taxPercent' => $data['taxPercent'],
            'storeScopeLabel' => $data['storeScopeLabel'],
            'metrics' => $data['metrics'],
            'shiftReport' => $shiftReport,
            'selectedShift' => $ownedShift,
        ]);
    }

    private function pricingTaxPercent(): float
    {
        return max(0, (float) StoreCache::get('pricing_tax_percent', 0));
    }

    /**
     * @return array{period:string,date_from:string,date_to:string,status:string|null,year:int,month:int,day:string,month_from:?string,month_to:?string}
     */
    private function resolveFilters(Request $request): array
    {
        $period = (string) $request->input('period', 'monthly');
        if (! in_array($period, ['daily', 'monthly', 'yearly', 'custom'], true)) {
            $period = 'monthly';
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
     * @return array<string, mixed>
     */
    private function buildReportData(Request $request, ?int $onlyShiftId = null): array
    {
        $filters = $this->resolveFilters($request);
        $taxPercent = $this->pricingTaxPercent();
        $pricingEnabled = StoreCache::get('pricing_enabled', true);
        $user = $request->user();
        $rangeStart = Carbon::parse($filters['date_from'])->startOfDay();
        $rangeEnd = Carbon::parse($filters['date_to'])->endOfDay();

        $shiftIds = ShiftSession::query()
            ->where('opened_by_user_id', $user->id)
            ->when($onlyShiftId !== null, fn ($query) => $query->where('id', $onlyShiftId))
            ->pluck('id');

        $createdRentals = Rental::query()
            ->with(['outfits', 'handledBy', 'shiftSession.openedBy'])
            ->whereIn('shift_session_id', $shiftIds)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->get();

        $paymentRecords = RentalPaymentRecord::query()
            ->with(['rental.outfits', 'rental.handledBy', 'rental.shiftSession.openedBy', 'shiftSession'])
            ->whereHas('shiftSession', fn ($query) => $query->where('opened_by_user_id', $user->id))
            ->where(function ($query) use ($rangeStart, $rangeEnd): void {
                $query->whereBetween('recorded_at', [$rangeStart, $rangeEnd])
                    ->orWhere(function ($fallback) use ($rangeStart, $rangeEnd): void {
                        $fallback->whereNull('recorded_at')
                            ->whereBetween('created_at', [$rangeStart, $rangeEnd]);
                    });
            })
            ->when($filters['status'], function ($query, $status): void {
                $query->whereHas('rental', fn ($rentalQuery) => $rentalQuery->where('status', $status));
            })
            ->get();

        $overduePayments = Rental::query()
            ->with(['outfits', 'handledBy', 'shiftSession.openedBy'])
            ->whereIn('overdue_payment_shift_session_id', $shiftIds)
            ->whereBetween('overdue_paid_at', [$rangeStart, $rangeEnd])
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->get();

        $activityRentals = collect();
        $activityKinds = [];
        $activityAt = [];
        $paidInRangeByRentalId = [];
        $coveredShiftIds = collect();
        $paidFromCurrentShiftRentals = 0.0;
        $paidFromPreviousShiftRentals = 0.0;
        $shiftActivity = [];

        $markActivity = function (int $rentalId, string $kind, ?Carbon $at) use (&$activityKinds, &$activityAt): void {
            if (! isset($activityKinds[$rentalId])) {
                $activityKinds[$rentalId] = [];
            }
            $activityKinds[$rentalId][$kind] = true;
            if ($at && (! isset($activityAt[$rentalId]) || $at->greaterThan($activityAt[$rentalId]))) {
                $activityAt[$rentalId] = $at->copy();
            }
        };
        $markShiftActivity = function (int $shiftId, Rental $rental, string $kind, ?Carbon $at, float $paidInShift = 0.0) use (&$shiftActivity): void {
            if ($shiftId <= 0) {
                return;
            }

            if (! isset($shiftActivity[$shiftId])) {
                $shiftActivity[$shiftId] = [
                    'rows' => [],
                ];
            }

            $rentalId = (int) $rental->id;
            if (! isset($shiftActivity[$shiftId]['rows'][$rentalId])) {
                $shiftActivity[$shiftId]['rows'][$rentalId] = [
                    'rental' => $rental,
                    'paid_in_shift' => 0.0,
                    'activity_at' => null,
                    'activity_kinds' => [],
                ];
            }

            $shiftActivity[$shiftId]['rows'][$rentalId]['rental'] = $rental;
            $shiftActivity[$shiftId]['rows'][$rentalId]['paid_in_shift'] = round(
                (float) $shiftActivity[$shiftId]['rows'][$rentalId]['paid_in_shift'] + $paidInShift,
                2
            );
            $shiftActivity[$shiftId]['rows'][$rentalId]['activity_kinds'][$kind] = true;

            $currentAt = $shiftActivity[$shiftId]['rows'][$rentalId]['activity_at'];
            if ($at && (! $currentAt || $at->greaterThan($currentAt))) {
                $shiftActivity[$shiftId]['rows'][$rentalId]['activity_at'] = $at->copy();
            }
        };

        foreach ($createdRentals as $rental) {
            $activityRentals->put((int) $rental->id, $rental);
            $coveredShiftIds->push((int) $rental->shift_session_id);
            $markActivity((int) $rental->id, 'created', $rental->created_at);
            $markShiftActivity((int) $rental->shift_session_id, $rental, 'created', $rental->created_at);
        }

        foreach ($paymentRecords as $payment) {
            $rental = $payment->rental;
            if (! $rental) {
                continue;
            }

            $rental->loadMissing(['outfits', 'handledBy', 'shiftSession.openedBy']);
            $activityRentals->put((int) $rental->id, $rental);
            $coveredShiftIds->push((int) $payment->shift_session_id);

            $rentalId = (int) $rental->id;
            $amount = round((float) $payment->amount, 2);
            $paidInRangeByRentalId[$rentalId] = round(($paidInRangeByRentalId[$rentalId] ?? 0) + $amount, 2);
            $markActivity($rentalId, 'payment', $payment->recorded_at ?? $payment->created_at);
            $markShiftActivity(
                (int) $payment->shift_session_id,
                $rental,
                'payment',
                $payment->recorded_at ?? $payment->created_at,
                $amount
            );

            if ((int) $payment->shift_session_id === (int) $rental->shift_session_id) {
                $paidFromCurrentShiftRentals += $amount;
            } else {
                $paidFromPreviousShiftRentals += $amount;
            }
        }

        foreach ($overduePayments as $rental) {
            $activityRentals->put((int) $rental->id, $rental);
            $coveredShiftIds->push((int) $rental->overdue_payment_shift_session_id);

            $rentalId = (int) $rental->id;
            $amount = round(max(0.0, (float) ($rental->overdue_amount_paid ?? 0)), 2);
            $paidInRangeByRentalId[$rentalId] = round(($paidInRangeByRentalId[$rentalId] ?? 0) + $amount, 2);
            $markActivity($rentalId, 'overdue', $rental->overdue_paid_at);
            $markShiftActivity(
                (int) $rental->overdue_payment_shift_session_id,
                $rental,
                'overdue',
                $rental->overdue_paid_at,
                $amount
            );

            if ((int) $rental->overdue_payment_shift_session_id === (int) $rental->shift_session_id) {
                $paidFromCurrentShiftRentals += $amount;
            } else {
                $paidFromPreviousShiftRentals += $amount;
            }
        }

        $totalOutstanding = 0.0;
        $totalDue = 0.0;
        $rentalReports = $activityRentals
            ->map(function (Rental $rental) use ($taxPercent, $paidInRangeByRentalId, $activityAt, $activityKinds, &$totalOutstanding, &$totalDue): array {
                $financial = RentalFinancialSummary::summarize($rental, $taxPercent);
                $lifetimePaid = max(0.0, (float) ($rental->amount_paid ?? 0)) + max(0.0, (float) ($rental->overdue_amount_paid ?? 0));
                $outstanding = max(0.0, (float) $financial['net_incl_tax'] - $lifetimePaid);
                $totalOutstanding += $outstanding;
                $totalDue += (float) $financial['net_incl_tax'];

                return [
                    'rental' => $rental,
                    'financial' => $financial,
                    'paid_in_range' => (float) ($paidInRangeByRentalId[(int) $rental->id] ?? 0),
                    'outstanding' => $outstanding,
                    'activity_at' => $activityAt[(int) $rental->id] ?? $rental->created_at,
                    'activity_label' => $this->activityLabel(array_keys($activityKinds[(int) $rental->id] ?? [])),
                ];
            })
            ->sortByDesc(fn (array $report) => optional($report['activity_at'])->getTimestamp() ?? 0)
            ->values();

        $shiftModels = ShiftSession::query()
            ->with('openedBy')
            ->whereIn('id', array_keys($shiftActivity))
            ->get()
            ->keyBy('id');

        $shiftReports = collect($shiftActivity)
            ->map(function (array $bucket, int|string $shiftId) use ($shiftModels, $taxPercent): ?array {
                $shift = $shiftModels->get((int) $shiftId);
                if (! $shift) {
                    return null;
                }

                $rows = collect($bucket['rows'] ?? [])
                    ->map(function (array $row) use ($taxPercent): array {
                        /** @var Rental $rental */
                        $rental = $row['rental'];
                        $financial = RentalFinancialSummary::summarize($rental, $taxPercent);
                        $lifetimePaid = max(0.0, (float) ($rental->amount_paid ?? 0)) + max(0.0, (float) ($rental->overdue_amount_paid ?? 0));
                        $outstanding = max(0.0, (float) $financial['net_incl_tax'] - $lifetimePaid);

                        return [
                            'rental' => $rental,
                            'financial' => $financial,
                            'paid_in_shift' => (float) ($row['paid_in_shift'] ?? 0),
                            'outstanding' => $outstanding,
                            'activity_at' => $row['activity_at'] ?? $rental->created_at,
                            'activity_label' => $this->activityLabel(array_keys($row['activity_kinds'] ?? [])),
                        ];
                    })
                    ->sortByDesc(fn (array $row) => optional($row['activity_at'])->getTimestamp() ?? 0)
                    ->values();

                return [
                    'shift' => $shift,
                    'transaction_count' => $rows->count(),
                    'total_paid' => round((float) $rows->sum('paid_in_shift'), 2),
                    'latest_activity_at' => $rows->first()['activity_at'] ?? $shift->opened_at,
                    'rows' => $rows,
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $report) => optional($report['latest_activity_at'])->getTimestamp() ?? 0)
            ->values();

        $storeId = StoreContext::storeId();
        $storeName = null;
        if ($storeId !== null) {
            $storeName = Store::query()->where('id', $storeId)->value('name');
        }

        return [
            'filters' => $filters,
            'pricingEnabled' => $pricingEnabled,
            'taxPercent' => $taxPercent,
            'storeScopeLabel' => StoreContext::allStores() ? 'All Stores' : ($storeName ?: 'Current Store'),
            'metrics' => [
                'shift_count' => $shiftReports->count(),
                'transaction_count' => $rentalReports->count(),
                'total_paid' => round($paidFromCurrentShiftRentals + $paidFromPreviousShiftRentals, 2),
                'total_due' => round($totalDue, 2),
                'total_outstanding' => round($totalOutstanding, 2),
                'paid_from_previous_shift_rentals' => round($paidFromPreviousShiftRentals, 2),
                'paid_from_current_shift_rentals' => round($paidFromCurrentShiftRentals, 2),
            ],
            'shiftReports' => $shiftReports,
            'rentalReports' => $rentalReports,
        ];
    }

    /**
     * @param  array<int, string>  $activityKinds
     */
    private function activityLabel(array $activityKinds): string
    {
        sort($activityKinds);

        return match ($activityKinds) {
            ['created'] => 'Created',
            ['overdue'] => 'Overdue payment',
            ['payment'] => 'Payment',
            ['created', 'payment'] => 'Created + payment',
            ['created', 'overdue'] => 'Created + overdue payment',
            ['overdue', 'payment'] => 'Payment + overdue payment',
            ['created', 'overdue', 'payment'] => 'Created + all payments',
            default => 'Activity',
        };
    }
}
