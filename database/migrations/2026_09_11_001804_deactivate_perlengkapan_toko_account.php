<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supplies (hangers, soap, etc.) are now registered through Fixed Assets and
 * depreciated (a product decision), not expensed immediately. Account
 * 6100 "Store Supplies" stays for historical journal lines (never
 * deleted, can't be — Account::mayDelete() blocks removing an account with
 * existing entries) but is deactivated so it drops out of the Expenses
 * category dropdown, which only lists is_active accounts — new supplies
 * purchases can't accidentally go through the old immediate-expense path
 * again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounts')->where('code', '6100')->update(['is_active' => false]);
    }

    public function down(): void
    {
        DB::table('accounts')->where('code', '6100')->update(['is_active' => true]);
    }
};
