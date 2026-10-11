<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;

/**
 * A lightweight, opname-only identity for the public per-item QR page
 * (OutfitController::renderItemStatus / outfits/status.blade.php) — lets a staff
 * member unlock the Present/Missing/Damaged buttons there with a short PIN instead
 * of their real account password, since that page can be opened on a phone
 * that isn't necessarily theirs. Deliberately NOT a real Auth::login(): a
 * verified PIN only ever grants access to marking items on that one public
 * page, never the rest of the app — so nothing here should be checked by
 * any auth-protected route.
 */
class StockOpnamePin
{
    private const SESSION_KEY = 'stock_opname_pin_user_id';

    private const SESSION_VERIFIED_AT_KEY = 'stock_opname_pin_verified_at';

    private const SESSION_STORE_KEY = 'stock_opname_pin_store_id';

    // Long enough to walk the store scanning several items without
    // re-entering the PIN every time, short enough that a PIN unlocked on a
    // shared/borrowed phone doesn't stay open all day.
    private const TTL_SECONDS = 4 * 60 * 60;

    /**
     * Checks the given PIN against every user who could plausibly have one
     * (small staff lists per store, so a linear scan is fine — Hash::check
     * can't be done as a SQL WHERE anyway, since each row has its own salt).
     */
    public static function attempt(string $pin): ?User
    {
        return User::whereNotNull('stock_opname_pin')->get()
            ->first(function (User $user) use ($pin) {
                return $user->mayParticipateInStockOpname() && Hash::check($pin, $user->stock_opname_pin);
            });
    }

    /**
     * Whether some OTHER user already uses this PIN. attempt() identifies the
     * user by the PIN alone, so two people sharing one would both be recorded
     * as whichever comes first — wrong audit trail. Compared by hash check
     * (each row has its own salt), so this cannot be a unique DB index.
     */
    public static function isTakenByAnother(string $pin, ?int $exceptUserId = null): bool
    {
        return User::whereNotNull('stock_opname_pin')
            ->when($exceptUserId, fn ($q) => $q->where('id', '!=', $exceptUserId))
            ->get()
            ->contains(fn (User $user) => Hash::check($pin, $user->stock_opname_pin));
    }

    /**
     * @param int|null $storeId the store of the item page the PIN was entered
     *                          on (and its geofence checked against) — the
     *                          verified identity only works for that store.
     */
    public static function verify(User $user, ?int $storeId = null): void
    {
        Session::put(self::SESSION_KEY, $user->id);
        Session::put(self::SESSION_VERIFIED_AT_KEY, now()->timestamp);
        Session::put(self::SESSION_STORE_KEY, $storeId);
    }

    /** The store this PIN session was unlocked for (null = unknown/none). */
    public static function verifiedStoreId(): ?int
    {
        $id = Session::get(self::SESSION_STORE_KEY);

        return $id === null ? null : (int) $id;
    }

    public static function forget(): void
    {
        Session::forget([self::SESSION_KEY, self::SESSION_VERIFIED_AT_KEY, self::SESSION_STORE_KEY]);
    }

    /**
     * The currently PIN-verified user for this browser session, or null if
     * none was ever verified or the TTL has lapsed. Re-checks
     * mayParticipateInStockOpname() live — a role change or account removal
     * revokes access immediately, not just at the next PIN entry.
     */
    public static function verifiedUser(): ?User
    {
        $userId = Session::get(self::SESSION_KEY);
        $verifiedAt = Session::get(self::SESSION_VERIFIED_AT_KEY);
        if (! $userId || ! $verifiedAt) {
            return null;
        }
        if (now()->timestamp - (int) $verifiedAt > self::TTL_SECONDS) {
            self::forget();

            return null;
        }

        $user = User::find($userId);
        if (! $user || ! $user->mayParticipateInStockOpname()) {
            self::forget();

            return null;
        }

        return $user;
    }
}
