<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_outfit', function (Blueprint $table) {
            $table->string('discount_note', 500)->nullable()->after('discount_type');
        });
    }

    public function down(): void
    {
        Schema::table('rental_outfit', function (Blueprint $table) {
            $table->dropColumn('discount_note');
        });
    }
};
