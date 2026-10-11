<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * is_tutorial (existing column) means "created while Trial Mode was on"
     * — it counts normally in reports while Trial Mode stays on, same as
     * real data, since that's meant for someone manually driving the real
     * screens to test them before going live.
     *
     * is_tutorial_practice (this one) means "created by a guided tutorial's
     * own practice step" (e.g. the Expense/Manual Journal Entry tour's
     * pre-filled example) — a distinct, narrower case that can happen at
     * ANY time, including long after real data already exists (re-onboarding
     * a new staff member). That must never count toward real numbers, on or
     * off — see ExcludesAccountingTrialData.
     */
    public function up(): void
    {
        // Added to all four tables ExcludesAccountingTrialData is applied to
        // (Account, Expense, CashMovement, JournalEntry) — the trait's global
        // scope queries this column on every one of them, even though only
        // Expense and JournalEntry actually populate it with true today (no
        // guided practice step creates a CashMovement or Account yet).
        foreach (['journal_entries', 'expenses', 'cash_movements', 'accounts'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('is_tutorial_practice')->default(false)->after('is_tutorial');
            });
        }
    }

    public function down(): void
    {
        foreach (['journal_entries', 'expenses', 'cash_movements', 'accounts'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('is_tutorial_practice');
            });
        }
    }
};
