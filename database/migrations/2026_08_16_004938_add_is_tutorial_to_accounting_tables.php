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
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('is_tutorial')->default(false)->after('is_active');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->boolean('is_tutorial')->default(false)->after('created_by_user_id');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('is_tutorial')->default(false)->after('recorded_by_user_id');
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->boolean('is_tutorial')->default(false)->after('recorded_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('is_tutorial');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn('is_tutorial');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('is_tutorial');
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropColumn('is_tutorial');
        });
    }
};
