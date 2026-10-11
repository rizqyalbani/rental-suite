<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outfits', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->change();
            $table->string('new_code', 50)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('outfits', function (Blueprint $table) {
            $table->string('code', 50)->nullable(false)->change();
            $table->dropColumn('new_code');
        });
    }
};
