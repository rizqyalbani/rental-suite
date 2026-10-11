<?php

namespace App\Http\Controllers;

use App\Models\RentalApprovalRequest;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Support\PaymentShiftAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Management asks a shift owner to take a shiftless payment; the owner accepts or rejects. */
class PaymentShiftAssignmentController extends Controller
{
    public function request(Request $request, int $paymentId): RedirectResponse
    {
        abort_unless(PaymentShiftAssignment::mayRequest($request->user()), 403);
        $data = $request->validate([
            'shift_session_id' => 'required|integer',
            'note' => 'nullable|string|max:300',
        ]);
        $payment = RentalPaymentRecord::query()->findOrFail($paymentId);
        $shift = ShiftSession::withoutGlobalScopes()->findOrFail((int) $data['shift_session_id']);

        if ($msg = PaymentShiftAssignment::blockMessage($payment, $shift, $request->user())) {
            return back()->withErrors(['payment_assign' => $msg]);
        }
        PaymentShiftAssignment::request($payment, $shift, $request->user(), trim((string) ($data['note'] ?? '')));

        return back()->with('success', 'Request sent to '.($shift->openedBy?->name ?? 'the shift owner').'. The payment joins their shift once they accept.');
    }

    public function accept(Request $request, RentalApprovalRequest $approval): RedirectResponse
    {
        if ($msg = PaymentShiftAssignment::accept($approval, $request->user())) {
            return back()->withErrors(['payment_assign' => $msg]);
        }

        return back()->with('success', 'Payment added to your shift.');
    }

    public function reject(Request $request, RentalApprovalRequest $approval): RedirectResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:300']);
        $note = trim((string) ($data['note'] ?? ''));
        if ($msg = PaymentShiftAssignment::reject($approval, $request->user(), $note)) {
            return back()->withErrors(['payment_assign' => $msg]);
        }

        return back()->with('success', 'Payment request rejected. Management will choose another shift.');
    }
}
