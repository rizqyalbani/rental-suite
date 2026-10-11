<?php

namespace App\Support;

use App\Models\User;

class RentDeleteRestriction
{
    public static function enabled(): bool
    {
        return (bool) StoreCache::get('restrict_admin_deletes', false)
            || (bool) StoreCache::get('restrict_admin_rent_transactions', false);
    }

    public static function userMayDeleteRentals(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isManagement()) {
            return (bool) StoreCache::get('allow_management_delete_rents', true);
        }

        // This setting's own label ("Prevent admin accounts from...") only
        // ever claims to restrict Admin — Supervisor is a reviewer/approver
        // role, not the one this toggle is meant to lock down. Without this,
        // Supervisor was silently blocked from cancelling rents by a setting
        // that never mentions Supervisor at all (and doesn't even affect
        // Admin here in practice — RentalController::destroy() already
        // routes Admin's cancel through delete-approval before this check
        // ever runs).
        if ($user->isSupervisor()) {
            return true;
        }

        if (! self::enabled()) {
            return true;
        }

        return false;
    }

    public static function denyMessage(): string
    {
        return 'Your account cannot delete rents. Ask management to change this in Settings or perform the deletion for you.';
    }
}
