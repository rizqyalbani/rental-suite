<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $has = Schema::hasColumn('rentals', 'overdue_charge_through_date');

        Schema::table('rentals', function (Blueprint $table) use ($has) {
            if (! $has) {
                $table->date('overdue_charge_through_date')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn('overdue_charge_through_date');
        });
    }
};
