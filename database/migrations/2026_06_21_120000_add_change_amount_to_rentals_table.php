<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rentals', 'change_amount')) {
            return;
        }

        Schema::table('rentals', function (Blueprint $table) {
            $table->decimal('change_amount', 12, 2)->nullable()->after('amount_paid');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('rentals', 'change_amount')) {
            return;
        }

        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn('change_amount');
        });
    }
};
