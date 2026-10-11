<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_tutorial', 'stock_opname_pin'])]
#[Hidden(['password', 'remember_token', 'stock_opname_pin'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'stock_opname_pin' => 'hashed',
            'is_tutorial' => 'boolean',
        ];
    }

    /**
     * Normalized role for comparisons (trim, lowercase, common aliases).
     */
    public function normalizedRole(): string
    {
        $raw = strtolower(trim((string) ($this->role ?? '')));

        return match ($raw) {
            'manager' => 'management',
            default => $raw,
        };
    }

    public function isSuperAdmin(): bool
    {
        return $this->normalizedRole() === 'super_admin';
    }

    public function isAdmin(): bool
    {
        return $this->normalizedRole() === 'admin';
    }

    public function isManagement(): bool
    {
        return $this->normalizedRole() === 'management';
    }

    public function isSupervisor(): bool
    {
        return $this->normalizedRole() === 'supervisor';
    }

    public function isAccounting(): bool
    {
        return $this->normalizedRole() === 'accounting';
    }

    /**
     * Staff allowed to create/edit/delete rents, shift sessions, and invoice actions.
     * Management and supervisor have full transaction access (not oversight-only)
     * since both already review/approve other people's requests — gating them
     * too would be circular.
     */
    public function mayOperateRentTransactions(): bool
    {
        return $this->isSuperAdmin() || $this->isAdmin() || $this->isManagement() || $this->isSupervisor();
    }

    public function mayMutateItems(): bool
    {
        return $this->isSuperAdmin() || $this->isManagement();
    }

    /**
     * Supervisor and management may approve/reject item-removal requests
     * (removing a rented item from a rental that already has payment recorded).
     */
    public function mayApproveItemRemoval(): bool
    {
        return $this->isSuperAdmin() || $this->isSupervisor() || $this->isManagement();
    }

    /**
     * Supervisor, management, and super admin can review the rental activity
     * log (the fraud-accountability trail for full-access transaction edits).
     */
    /**
     * Every authenticated role can see the activity log for their own store —
     * it's a transparency/accountability tool, not an approval-only page.
     * Approving requests is a separate, narrower permission (mayApproveItemRemoval()).
     */
    public function mayViewActivityLog(): bool
    {
        return true;
    }

    /**
     * Receipt/invoice viewing is not a rent transaction mutation: management may
     * open/generate receipts for oversight even though they can't edit rents.
     */
    public function mayGenerateInvoices(): bool
    {
        return $this->isSuperAdmin() || $this->isAdmin() || $this->isManagement() || $this->isSupervisor();
    }

    /**
     * Stock Opname (physical count vs system stock) — Admin helps with the
     * actual counting, but starting/finalizing a session stays with
     * Supervisor and above (supervisor and above own the stock-opname session).
     */
    public function mayParticipateInStockOpname(): bool
    {
        return $this->isAdmin() || $this->mayManageStockOpname();
    }

    public function mayManageStockOpname(): bool
    {
        return $this->isSupervisor() || $this->isManagement() || $this->isSuperAdmin();
    }

    public function hasStockOpnamePin(): bool
    {
        return ! empty($this->stock_opname_pin);
    }

    /**
     * Chart of Accounts structure (accounts, hierarchy, normal balances) is
     * shared company-wide, not per-store — restricted to the same roles that
     * manage Stores/Settings, since a wrong account type/normal-balance edit
     * corrupts every store's books at once.
     */
    public function mayManageChartOfAccounts(): bool
    {
        return $this->isSuperAdmin() || $this->isManagement();
    }

    /**
     * Read-only Chart of Accounts access — broader than mayManageChartOfAccounts().
     * An accountant reviewing Trial Balance/Balance Sheet/P&L needs to see the
     * account structure those reports reference (what "4100" or "1200" actually
     * is), even though managing that structure stays Management/Super Admin only.
     */
    public function mayViewChartOfAccounts(): bool
    {
        return $this->mayManageChartOfAccounts() || $this->isAccounting();
    }

    /**
     * Day-to-day bookkeeping entry (expenses, cash in/out). Client's own
     * notes name Admin explicitly for this ("Admin — cash in/out, expenses,
     * recording"); Accounting's whole job is bookkeeping so it gets the same
     * access. Supervisor is deliberately excluded — not named for this by
     * the client, unlike its existing broad rent-transaction access.
     */
    public function mayRecordAccountingEntries(): bool
    {
        return $this->isSuperAdmin() || $this->isManagement() || $this->isAdmin() || $this->isAccounting();
    }

    /**
     * Correcting an Expense/Cash In-Out entry after the fact — narrower than
     * who can create one. Admin can record entries day-to-day but can't
     * correct them; a correction reverses the original ledger entry and
     * posts a new one (JournalPostingService::reverse()), so it's kept to
     * the roles that actually own the books, mirroring the same restriction
     * already on Chart of Accounts management (Super Admin/Management) plus
     * the Accounting role, since fixing entries is literally its job.
     */
    public function mayEditAccountingEntries(): bool
    {
        return $this->isSuperAdmin() || $this->isManagement() || $this->isAccounting();
    }

    /**
     * Read-only accounting dashboard + order visibility.
     */
    public function mayViewAccountingDashboard(): bool
    {
        return $this->isSuperAdmin() || $this->isManagement() || $this->isAccounting();
    }

    /**
     * Active store IDs this user may see data for (matches SetCurrentStore middleware).
     *
     * @return array<int, int>
     */
    public function accessibleActiveStoreIds(): array
    {
        if ($this->isManagement() || $this->isSuperAdmin()) {
            return Store::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        $ids = $this->stores()
            ->where('is_active', true)
            ->orderBy('stores.id')
            ->pluck('stores.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($ids !== []) {
            return $ids;
        }

        // Match SetCurrentStore: staff with no store_user rows still operate on the first active store.
        $fallback = Store::query()->where('is_active', true)->orderBy('id')->value('id');

        return $fallback !== null ? [(int) $fallback] : [];
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_user');
    }
}
