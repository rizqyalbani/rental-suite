<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rentals', 'extra_charge')) {
            return;
        }
        Schema::table('rentals', function (Blueprint $table): void {
            $table->decimal('extra_charge', 12, 2)->nullable()->after('discount_note');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table): void {
            $table->dropColumn('extra_charge');
        });
    }
};
