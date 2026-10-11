<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rentals', 'handled_by_user_id')) {
            Schema::table('rentals', function (Blueprint $table) {
                $table->foreignId('handled_by_user_id')->nullable()->after('payment_method')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rentals', 'handled_by_user_id')) {
            Schema::table('rentals', function (Blueprint $table) {
                $table->dropConstrainedForeignId('handled_by_user_id');
            });
        }
    }
};
