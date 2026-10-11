<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $has = Schema::hasColumn('rentals', 'overdue_change_amount');
        if ($has) {
            return;
        }

        Schema::table('rentals', function (Blueprint $table) {
            $table->decimal('overdue_change_amount', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('rentals', 'overdue_change_amount')) {
            return;
        }

        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn('overdue_change_amount');
        });
    }
};

