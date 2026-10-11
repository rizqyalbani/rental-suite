<?php

namespace App\Http\Controllers;

use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RentalActivityLogController extends Controller
{
    /**
     * Standalone, filterable activity log across all rentals in the user's
     * accessible stores. Companion to the compact timeline embedded on each
     * rental's detail view.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user && $user->mayViewActivityLog(), 403);

        $storeIds = $user->accessibleActiveStoreIds();

        $query = RentalActivityLog::withoutGlobalScope('store_scope')
            ->whereIn('store_id', $storeIds)
            ->with(['user', 'rental']);

        $action = (string) $request->query('action', '');
        if ($action !== '') {
            $query->where('action', $action);
        }

        $userId = (int) $request->query('user_id', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        $rentalId = (int) $request->query('rental_id', 0);
        if ($rentalId > 0) {
            $query->where('rental_id', $rentalId);
        }

        $dateFrom = (string) $request->query('date_from', '');
        if ($dateFrom !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        $dateTo = (string) $request->query('date_to', '');
        if ($dateTo !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $logs = $query->latest()->paginate(30)->withQueryString();

        $actionOptions = [
            'created' => 'Rental created',
            'updated' => 'Rental updated',
            'status_changed' => 'Status changed',
            'deleted' => 'Rental deleted',
            'item_removal_requested' => 'Item removal requested',
            'item_removal_approved' => 'Item removal approved',
            'item_removal_rejected' => 'Item removal rejected',
            'edit_requested' => 'Edit submitted for approval',
            'edit_approved' => 'Edit approved',
            'edit_rejected' => 'Edit rejected',
            'edit_cancelled' => 'Edit request cancelled',
            'delete_requested' => 'Deletion submitted for approval',
            'delete_approved' => 'Deletion approved',
            'delete_rejected' => 'Deletion rejected',
            'delete_cancelled' => 'Deletion request cancelled',
            'free_requested' => 'Free rent requested',
            'free_approved' => 'Free rent approved',
            'free_rejected' => 'Free rent rejected',
            'free_cancelled' => 'Free rent request cancelled',
            'payment_assign_requested' => 'Payment sent to a shift for approval',
            'payment_assign_approved' => 'Payment added to shift',
            'payment_assign_rejected' => 'Payment shift request rejected',
            'payment_assign_cancelled' => 'Payment shift request cancelled',
            'late_done_requested' => 'Pickup/return reported for approval',
            'late_done_approved' => 'Pickup/return report approved',
            'late_done_rejected' => 'Pickup/return report rejected',
            'late_done_cancelled' => 'Pickup/return report cancelled',
            'item_removal_cancelled' => 'Item removal request cancelled',
            'pickup_changed' => 'Pickup status changed',
            'shift_opened' => 'Shift opened',
            'shift_closed' => 'Shift closed',
        ];

        $userOptions = User::withoutGlobalScope('store_scope')
            ->whereIn('id', RentalActivityLog::withoutGlobalScope('store_scope')->whereIn('store_id', $storeIds)->distinct()->pluck('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('rentals.activity-log.index', [
            'logs' => $logs,
            'actionOptions' => $actionOptions,
            'userOptions' => $userOptions,
            'filters' => [
                'action' => $action,
                'user_id' => $userId ?: null,
                'rental_id' => $rentalId ?: null,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    /**
     * Lightweight JSON feed for the off-canvas activity panel. With
     * ?rental_id=X: latest entries for that one rental. Without it: latest
     * entries across every rental in the user's accessible stores (the
     * "global" feed opened via the edge-of-screen toggle). Kept deliberately
     * small (no pagination, capped rows) since it's fetched on-demand each
     * time the panel opens, not preloaded with the page.
     */
    public function panel(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->mayViewActivityLog()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $storeIds = $user->accessibleActiveStoreIds();
        $rentalId = (int) $request->query('rental_id', 0);

        $rentalModel = null;
        if ($rentalId > 0) {
            $rentalModel = Rental::withoutGlobalScope('store_scope')
                ->whereIn('store_id', $storeIds)
                ->find($rentalId);

            if (! $rentalModel) {
                return response()->json(['message' => 'Rental not found.'], 404);
            }
        }

        $query = RentalActivityLog::withoutGlobalScope('store_scope')
            ->whereIn('store_id', $storeIds)
            ->with(['user:id,name', 'rental:id,customer_name']);

        if ($rentalModel) {
            $query->where('rental_id', $rentalModel->id);
        }

        $logs = $query->latest()->limit(20)->get();

        $rows = $logs->map(function (RentalActivityLog $log) use ($rentalModel) {
            $changes = [];
            foreach ($log->changes ?? [] as $field => $pair) {
                $changes[] = [
                    'label' => RentalActivityLog::fieldLabel($field),
                    'from' => RentalActivityLog::formatChangeValue($field, $pair[0] ?? null),
                    'to' => RentalActivityLog::formatChangeValue($field, $pair[1] ?? null),
                ];
            }

            return [
                'action' => $log->action,
                'action_label' => $log->actionLabel(),
                'user_name' => $log->user->name ?? 'System',
                'time_human' => $log->created_at?->diffForHumans(),
                'time_full' => $log->created_at?->format('d M Y H:i'),
                'note' => $log->note,
                'changes' => $changes,
                'rental_id' => $rentalModel ? null : $log->rental_id,
                'rental_customer_name' => $rentalModel ? null : ($log->rental->customer_name ?? null),
            ];
        });

        return response()->json([
            'ok' => true,
            'mode' => $rentalModel ? 'rental' : 'global',
            'rental_id' => $rentalModel?->id,
            'customer_name' => $rentalModel?->customer_name,
            'full_history_url' => route('rentals.activity-log.index', $rentalModel ? ['rental_id' => $rentalModel->id] : []),
            'logs' => $rows,
        ]);
    }
}
