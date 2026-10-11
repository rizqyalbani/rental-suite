<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasBase = Schema::hasColumn('rentals', 'overdue_base_end_date');
        $hasMergedAt = Schema::hasColumn('rentals', 'overdue_merged_at');

        Schema::table('rentals', function (Blueprint $table) use ($hasBase, $hasMergedAt) {
            if (! $hasBase) {
                $table->date('overdue_base_end_date')->nullable();
            }
            if (! $hasMergedAt) {
                $table->timestamp('overdue_merged_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn(['overdue_base_end_date', 'overdue_merged_at']);
        });
    }
};
