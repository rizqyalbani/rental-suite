<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks a session created by the interactive Stock Opname tutorial —
     * same pattern as fixed_assets.is_tutorial_practice: a real row, on the
     * real table, so the tutorial can drive the real page/buttons, but
     * unconditionally excluded from the real history list and the
     * one-active-session-per-store check.
     */
    public function up(): void
    {
        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->boolean('is_tutorial_practice')->default(false)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->dropColumn('is_tutorial_practice');
        });
    }
};
