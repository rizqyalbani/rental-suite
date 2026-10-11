<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('outfits', 'stock')) {
            Schema::table('outfits', function (Blueprint $table) {
                $table->integer('stock')->nullable()->default(0)->after('price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('outfits', 'stock')) {
            Schema::table('outfits', function (Blueprint $table) {
                $table->dropColumn('stock');
            });
        }
    }
};
