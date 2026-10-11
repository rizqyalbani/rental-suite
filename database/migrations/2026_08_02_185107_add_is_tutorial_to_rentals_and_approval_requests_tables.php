<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->boolean('is_tutorial')->default(false)->after('status');
        });

        Schema::table('rental_approval_requests', function (Blueprint $table) {
            $table->boolean('is_tutorial')->default(false)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn('is_tutorial');
        });

        Schema::table('rental_approval_requests', function (Blueprint $table) {
            $table->dropColumn('is_tutorial');
        });
    }
};
