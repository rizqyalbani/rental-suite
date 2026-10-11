<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * rental_id becomes nullable so this table can also hold shift open/close
     * events, which aren't tied to any rental.
     */
    public function up(): void
    {
        Schema::table('rental_activity_logs', function (Blueprint $table) {
            $table->dropForeign(['rental_id']);
        });

        Schema::table('rental_activity_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('rental_id')->nullable()->change();
        });

        Schema::table('rental_activity_logs', function (Blueprint $table) {
            $table->foreign('rental_id')->references('id')->on('rentals')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_activity_logs', function (Blueprint $table) {
            $table->dropForeign(['rental_id']);
        });

        Schema::table('rental_activity_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('rental_id')->nullable(false)->change();
        });

        Schema::table('rental_activity_logs', function (Blueprint $table) {
            $table->foreign('rental_id')->references('id')->on('rentals')->cascadeOnDelete();
        });
    }
};
