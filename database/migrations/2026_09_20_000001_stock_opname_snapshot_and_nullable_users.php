<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two fixes from the independent review of Stock Opname:
     *
     *  1. A finished session used to recompute its totals from the CURRENT outfit
     *     list, so adding or deleting outfits later rewrote an old report. It now
     *     keeps a snapshot taken at completion.
     *  2. started_by / completed_by / marked_by pointed at users with no delete
     *     action, so deleting any staff account that had ever touched an opname
     *     failed with a database error. They now become NULL instead (the views
     *     already show "Unknown" for a missing user).
     */
    public function up(): void
    {
        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->dropForeign(['started_by_user_id']);
            $table->dropForeign(['completed_by_user_id']);
        });
        Schema::table('stock_opname_items', function (Blueprint $table) {
            $table->dropForeign(['marked_by_user_id']);
        });

        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->unsignedBigInteger('started_by_user_id')->nullable()->change();
            $table->foreign('started_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('completed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->json('snapshot')->nullable();
        });
        Schema::table('stock_opname_items', function (Blueprint $table) {
            $table->unsignedBigInteger('marked_by_user_id')->nullable()->change();
            $table->foreign('marked_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->dropColumn('snapshot');
            $table->dropForeign(['started_by_user_id']);
            $table->dropForeign(['completed_by_user_id']);
        });
        Schema::table('stock_opname_items', function (Blueprint $table) {
            $table->dropForeign(['marked_by_user_id']);
        });

        // Rows whose user was already deleted have NULL here now; they cannot go back
        // to NOT NULL, so the columns stay nullable — only the delete rule is reverted.
        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->foreign('started_by_user_id')->references('id')->on('users');
            $table->foreign('completed_by_user_id')->references('id')->on('users');
        });
        Schema::table('stock_opname_items', function (Blueprint $table) {
            $table->foreign('marked_by_user_id')->references('id')->on('users');
        });
    }
};
