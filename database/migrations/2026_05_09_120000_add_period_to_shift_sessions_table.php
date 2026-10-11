<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shift_sessions') && ! Schema::hasColumn('shift_sessions', 'period')) {
            Schema::table('shift_sessions', function (Blueprint $table) {
                $table->string('period', 20)->default('morning')->after('opened_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shift_sessions', 'period')) {
            Schema::table('shift_sessions', function (Blueprint $table) {
                $table->dropColumn('period');
            });
        }
    }
};
