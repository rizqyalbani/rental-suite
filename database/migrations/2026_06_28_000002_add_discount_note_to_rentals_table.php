<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rentals', 'discount_note')) {
            return;
        }
        Schema::table('rentals', function (Blueprint $table) {
            $table->string('discount_note', 500)->nullable()->after('extra_charge');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn('discount_note');
        });
    }
};
