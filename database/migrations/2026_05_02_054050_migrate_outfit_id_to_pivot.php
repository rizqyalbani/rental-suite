<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Copy existing outfit_id to pivot table
        DB::table('rentals')->orderBy('id')->each(function ($rental) {
            DB::table('rental_outfit')->insert([
                'rental_id' => $rental->id,
                'outfit_id' => $rental->outfit_id,
            ]);
        });

        // Drop foreign key and column
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropForeign(['outfit_id']);
            $table->dropColumn('outfit_id');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->foreignId('outfit_id')->nullable()->constrained()->cascadeOnDelete();
        });

        // Restore first outfit_id from pivot
        DB::table('rentals')->orderBy('id')->each(function ($rental) {
            $first = DB::table('rental_outfit')->where('rental_id', $rental->id)->first();
            if ($first) {
                DB::table('rentals')->where('id', $rental->id)->update(['outfit_id' => $first->outfit_id]);
            }
        });
    }
};
