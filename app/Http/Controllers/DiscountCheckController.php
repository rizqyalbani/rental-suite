<?php

namespace App\Http\Controllers;

use App\Models\DiscountCheck;
use App\Models\Rental;
use App\Support\RentalFinancialSummary;
use App\Support\StoreCache;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DiscountCheckController extends Controller
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    private function taxPercent(): float
    {
        return max(0, (float) StoreCache::get('pricing_tax_percent', 0));
    }

    private function hasDiscount(Rental $r): bool
    {
        if ((float) ($r->discount ?? 0) > 0.0) {
            return true;
        }

        return $r->outfits->contains(fn ($s) => (float) ($s->pivot->discount ?? 0) > 0.0);
    }

    private function discountAmount(Rental $r): float
    {
        $fin = RentalFinancialSummary::summarize($r, $this->taxPercent());

        return max(0, (float) $fin['item_discount_total'] + (float) $fin['order_discount_amount']);
    }

    private function resolveDay(Request $request): string
    {
        $day = (string) $request->input('day', now()->toDateString());
        try {
            return Carbon::parse($day)->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
    }

    // ── Management: list & check ──────────────────────────────────────────────

    public function index(Request $request): View
    {
        $day = $this->resolveDay($request);

        $rentals = Rental::with(['outfits', 'handledBy', 'shiftSession', 'discountCheck.checkedBy'])
            ->whereDate('created_at', $day)
            ->latest()
            ->get()
            ->filter(fn ($r) => $this->hasDiscount($r))
            ->values();

        return view('discounts.management', [
            'day' => $day,
            'rentals' => $rentals,
            'taxPercent' => $this->taxPercent(),
        ]);
    }

    public function toggle(Request $request, Rental $rental): RedirectResponse
    {
        $existing = $rental->discountCheck;

        if ($existing) {
            $existing->delete();
        } else {
            $note = $request->input('note');
            DiscountCheck::create([
                'rental_id' => $rental->id,
                'checked_by_user_id' => $request->user()->id,
                'checked_at' => now(),
                'note' => $note ? substr($note, 0, 500) : null,
            ]);
        }

        return back()->with('success', $existing ? 'Check removed.' : 'Discount marked as checked.');
    }

    // ── Admin: my discount status ─────────────────────────────────────────────

    public function myStatus(Request $request): View
    {
        $day = $this->resolveDay($request);

        $rentals = Rental::with(['outfits', 'handledBy', 'shiftSession', 'discountCheck.checkedBy'])
            ->where('handled_by_user_id', $request->user()->id)
            ->whereDate('created_at', $day)
            ->latest()
            ->get()
            ->filter(fn ($r) => $this->hasDiscount($r))
            ->values();

        return view('discounts.admin-status', [
            'day' => $day,
            'rentals' => $rentals,
            'taxPercent' => $this->taxPercent(),
        ]);
    }
}
