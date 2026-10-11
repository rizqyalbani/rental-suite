<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->decimal('overdue_amount_paid', 12, 2)->nullable();
            $table->string('overdue_payment_method', 100)->nullable();
            $table->string('overdue_payment_kind', 20)->nullable();
            $table->foreignId('overdue_payment_received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('overdue_payment_shift_session_id')->nullable()->constrained('shift_sessions')->nullOnDelete();
            $table->timestamp('overdue_paid_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('overdue_payment_received_by_user_id');
            $table->dropConstrainedForeignId('overdue_payment_shift_session_id');
            $table->dropColumn([
                'overdue_amount_paid',
                'overdue_payment_method',
                'overdue_payment_kind',
                'overdue_paid_at',
            ]);
        });
    }
};
