<?php

namespace App\Support;

use App\Models\User;

class AdminDeleteRestriction
{
    public static function enabled(): bool
    {
        return (bool) StoreCache::get('restrict_admin_deletes', false);
    }

    /**
     * When restriction is on, only management and super_admin may delete outfits/rentals.
     */
    public static function userMayDeleteRecords(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (! self::enabled()) {
            return true;
        }

        return $user->isSuperAdmin() || $user->isManagement();
    }

    public static function denyMessage(): string
    {
        return 'Your account cannot delete items or rentals. Ask management to change this in Settings or perform the deletion for you.';
    }
}
