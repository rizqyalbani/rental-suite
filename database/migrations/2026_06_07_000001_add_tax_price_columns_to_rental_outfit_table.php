<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_outfit', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_outfit', 'price_incl_tax')) {
                $table->decimal('price_incl_tax', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('rental_outfit', 'price_excl_tax')) {
                $table->decimal('price_excl_tax', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('rental_outfit', 'tax_amount')) {
                $table->decimal('tax_amount', 12, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_outfit', function (Blueprint $table) {
            if (Schema::hasColumn('rental_outfit', 'tax_amount')) {
                $table->dropColumn('tax_amount');
            }
            if (Schema::hasColumn('rental_outfit', 'price_excl_tax')) {
                $table->dropColumn('price_excl_tax');
            }
            if (Schema::hasColumn('rental_outfit', 'price_incl_tax')) {
                $table->dropColumn('price_incl_tax');
            }
        });
    }
};
