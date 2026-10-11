<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->after('handled_by_user_id')->constrained('users')->nullOnDelete();
        });

        // handled_by_user_id is overwritten by every edit, but it is the best
        // available approximation of the creator for pre-existing rows.
        DB::table('rentals')->whereNull('created_by_user_id')->update([
            'created_by_user_id' => DB::raw('handled_by_user_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
        });
    }
};
