<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasRate = Schema::hasColumn('rentals', 'overdue_fee_rate');
        $hasDays = Schema::hasColumn('rentals', 'overdue_fee_days');
        $hasAmount = Schema::hasColumn('rentals', 'overdue_fee_amount');
        $hasAppliedAt = Schema::hasColumn('rentals', 'overdue_fee_applied_at');

        Schema::table('rentals', function (Blueprint $table) use ($hasRate, $hasDays, $hasAmount, $hasAppliedAt) {
            if (! $hasRate) {
                $table->decimal('overdue_fee_rate', 12, 2)->nullable();
            }
            if (! $hasDays) {
                $table->unsignedInteger('overdue_fee_days')->nullable();
            }
            if (! $hasAmount) {
                $table->decimal('overdue_fee_amount', 12, 2)->nullable();
            }
            if (! $hasAppliedAt) {
                $table->timestamp('overdue_fee_applied_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn(['overdue_fee_rate', 'overdue_fee_days', 'overdue_fee_amount', 'overdue_fee_applied_at']);
        });
    }
};
