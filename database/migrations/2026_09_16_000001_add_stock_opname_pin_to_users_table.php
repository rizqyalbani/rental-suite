<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A short numeric PIN, separate from the account password, so a staff
     * member can unlock the quick Present/Missing/Damaged buttons on an item's
     * public QR page (see OutfitController::renderItemStatus) without typing
     * their real password on a phone that may not be their own. Hashed the
     * same way as password — never stored or shown back in plain text.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stock_opname_pin')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('stock_opname_pin');
        });
    }
};
