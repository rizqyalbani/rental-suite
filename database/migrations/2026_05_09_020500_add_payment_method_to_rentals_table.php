<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rentals', 'payment_method')) {
            Schema::table('rentals', function (Blueprint $table) {
                $table->string('payment_method', 100)->nullable()->after('address');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rentals', 'payment_method')) {
            Schema::table('rentals', function (Blueprint $table) {
                $table->dropColumn('payment_method');
            });
        }
    }
};
