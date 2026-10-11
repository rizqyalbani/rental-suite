<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Chart of Accounts — global/shared across every store, not store-scoped:
        // the account structure is company-wide, and a future consolidated
        // report across stores needs a plain FK join on account_id rather than
        // account-code matching. Per-store variation, if ever needed, is a
        // cheap additive migration later.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            // 'asset'|'liability'|'equity'|'revenue'|'expense' — app-validated,
            // not a DB enum (matches this codebase's existing convention of
            // plain strings + validation rules over DB-level enums).
            $table->string('type', 20);
            // 'debit'|'credit' — independent of type, so contra accounts
            // (Discount Given, Owner's Drawings, Accumulated Depreciation)
            // can hold the opposite normal balance of their type's default.
            $table->string('normal_balance', 10);
            // Flags Cash/Bank accounts for the Cash In/Out form's "which
            // drawer" dropdown.
            $table->boolean('is_cash_equivalent')->default(false);
            // Soft-disable; no destroy route — accounts with historical
            // journal lines can never be deleted (restrictOnDelete below).
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
