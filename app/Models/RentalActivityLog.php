<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Concerns\ExcludesTutorialData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalActivityLog extends Model
{
    use BelongsToStore;
    use ExcludesTutorialData;

    protected $fillable = [
        'store_id',
        'rental_id',
        'user_id',
        'action',
        'changes',
        'note',
        'is_tutorial',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'is_tutorial' => 'boolean',
        ];
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    /**
     * Record one activity-log entry for a rental. Silently skipped when
     * $changes and $note are both empty so no-op saves don't spam the log.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  field => [old, new]
     */
    public static function record(Rental $rental, string $action, array $changes = [], ?string $note = null, ?int $userId = null): ?self
    {
        if ($action !== 'created' && $action !== 'deleted' && $changes === [] && ($note === null || $note === '')) {
            return null;
        }

        return static::create([
            'store_id' => $rental->store_id,
            'rental_id' => $rental->id,
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'changes' => $changes !== [] ? $changes : null,
            'note' => $note,
            'is_tutorial' => $rental->is_tutorial,
        ]);
    }

    /**
     * Record a shift open/close event. Not tied to any rental (rental_id is
     * nullable) — shown in the same global activity feed for a full picture
     * of who was operating and when.
     */
    public static function recordShiftEvent(ShiftSession $shift, string $action, ?int $userId = null): self
    {
        return static::create([
            'store_id' => $shift->store_id,
            'rental_id' => null,
            'user_id' => $userId,
            'action' => $action,
            'changes' => null,
            'note' => $shift->period ? ucfirst($shift->period).' shift' : null,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Human-readable label for the action type, used across the activity log views.
     */
    public function actionLabel(): string
    {
        return match ($this->action) {
            'created' => 'Rental created',
            'updated' => 'Rental updated',
            'items_changed' => 'Items changed',
            'status_changed' => 'Status changed',
            'deleted' => 'Rental deleted',
            'item_removal_requested' => 'Item removal requested',
            'item_removal_approved' => 'Item removal approved',
            'item_removal_rejected' => 'Item removal rejected',
            'edit_requested' => 'Edit submitted for approval',
            'edit_approved' => 'Edit approved',
            'edit_rejected' => 'Edit rejected',
            'shift_opened' => 'Shift opened',
            'shift_closed' => 'Shift closed',
            'pickup_changed' => 'Pickup status changed',
            'delete_requested' => 'Deletion submitted for approval',
            'delete_approved' => 'Deletion approved',
            'delete_rejected' => 'Deletion rejected',
            'item_removal_cancelled' => 'Item removal request cancelled',
            'edit_cancelled' => 'Edit request cancelled',
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
            'payment_recorded' => 'Payment recorded',
            default => ucfirst(str_replace('_', ' ', $this->action)),
        };
    }

    private const MONEY_FIELDS = ['payment_amount', 'amount_paid', 'discount', 'extra_charge', 'overdue_amount_paid', 'overdue_fee_amount', 'item_removal_change_amount', 'item_removal_refund_amount', 'rent_total', 'free_refund_amount', 'money_reversed'];
    private const DATE_FIELDS = ['start_date', 'end_date'];

    public static function fieldLabel(string $field): string
    {
        return match ($field) {
            'customer_name' => 'Customer name',
            'phone' => 'Phone',
            'instagram' => 'Instagram',
            'notes' => 'Notes',
            'payment_method' => 'Payment method',
            'payment_kind' => 'Payment kind',
            'amount_paid' => 'Amount paid',
            'payment_amount' => 'Payment received',
            'discount' => 'Discount',
            'discount_type' => 'Discount type',
            'discount_note' => 'Discount note',
            'extra_charge' => 'Additional charge',
            'start_date' => 'Start date',
            'end_date' => 'End date',
            'status' => 'Status',
            'overdue_amount_paid' => 'Overdue paid',
            'overdue_payment_method' => 'Overdue payment method',
            'overdue_fee_amount' => 'Overdue charge',
            'outfit_ids' => 'Items',
            'removed_outfit_ids' => 'Items',
            'item_removal_change_amount' => 'Change (from payment)',
            'item_removal_refund_amount' => 'Refund due (item removed)',
            'item_discounts' => 'Item discounts',
            'picked_up' => 'Picked up',
            'rent_total' => 'Rent total',
            'free_refund_amount' => 'Refunded (free)',
            'free_refund_accounts' => 'Refunded from',
            'free_reason' => 'Reason (free)',
            'requested_by' => 'Requested by',
            'approved_by' => 'Approved by',
            'rejected_by' => 'Rejected by',
            'shift' => 'Shift',
            'delete_reason' => 'Delete reason',
            'picked_up_at' => 'Picked up at',
            'picked_up_by' => 'Handed over by',
            'returned_at' => 'Returned at',
            'returned_by' => 'Received back by',
            'money_reversed' => 'Payments voided',
            'received_by' => 'Received by',
            default => ucfirst(str_replace('_', ' ', $field)),
        };
    }

    /**
     * Render one changed value for display — currency for money fields, a
     * readable date for date fields, "—" for null/empty, raw scalar otherwise.
     */
    public static function formatChangeValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (in_array($field, self::MONEY_FIELDS, true) && is_numeric($value)) {
            return 'Rp '.number_format((float) $value, 0, ',', '.');
        }

        if (in_array($field, self::DATE_FIELDS, true)) {
            try {
                return \Carbon\Carbon::parse((string) $value)->format('d M Y');
            } catch (\Throwable) {
                return (string) $value;
            }
        }

        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        return (string) $value;
    }
}
