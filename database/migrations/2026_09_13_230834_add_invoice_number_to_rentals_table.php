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
        Schema::table('rentals', function (Blueprint $table) {
            // Nullable on purpose — assigned going forward only (new rentals),
            // not backfilled onto existing ones. Old rentals keep showing
            // "Rent #<id>" on their invoice, same as before this feature.
            $table->string('invoice_number')->nullable()->unique()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn('invoice_number');
        });
    }
};
