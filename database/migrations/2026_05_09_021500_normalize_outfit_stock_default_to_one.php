<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('outfits', 'stock')) {
            return;
        }

        DB::table('outfits')
            ->whereNull('stock')
            ->orWhere('stock', '<=', 0)
            ->update(['stock' => 1]);

        Schema::table('outfits', function (Blueprint $table) {
            $table->integer('stock')->default(1)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('outfits', 'stock')) {
            return;
        }

        Schema::table('outfits', function (Blueprint $table) {
            $table->integer('stock')->default(0)->nullable()->change();
        });
    }
};
