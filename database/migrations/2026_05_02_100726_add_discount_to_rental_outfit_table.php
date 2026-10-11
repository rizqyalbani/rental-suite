<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_outfit', function (Blueprint $table) {
            $table->decimal('discount', 12, 2)->nullable()->after('outfit_id');
        });
    }

    public function down(): void
    {
        Schema::table('rental_outfit', function (Blueprint $table) {
            $table->dropColumn('discount');
        });
    }
};
