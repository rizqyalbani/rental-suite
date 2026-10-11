<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Onboarding-tour practice rentals — a full table clone of `rentals`
     * (same column set, so the Rental model's business logic works
     * unchanged via the TutorialRental subclass) but with its own
     * auto-increment sequence, so repeated tutorial runs never advance real
     * rental ID numbers.
     */
    public function up(): void
    {
        Schema::create('tutorial_rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained('stores');
            $table->string('customer_name');
            $table->string('phone', 20);
            $table->string('instagram', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('payment_method', 100)->nullable();
            $table->decimal('amount_paid', 12, 2)->nullable();
            $table->decimal('down_payment_amount', 10, 2)->nullable();
            $table->decimal('change_amount', 12, 2)->nullable();
            $table->string('payment_kind', 32)->nullable();
            $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shift_session_id')->nullable()->constrained('shift_sessions')->nullOnDelete();
            $table->decimal('discount', 12, 2)->nullable();
            $table->string('discount_type', 10)->default('fixed');
            $table->string('discount_note', 500)->nullable();
            $table->decimal('extra_charge', 12, 2)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['active', 'returned', 'cancelled'])->default('active');
            $table->boolean('is_tutorial')->default(true);
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamps();
            $table->date('overdue_base_end_date')->nullable();
            $table->timestamp('overdue_merged_at')->nullable();
            $table->date('overdue_charge_through_date')->nullable();
            $table->decimal('overdue_fee_rate', 12, 2)->nullable();
            $table->unsignedInteger('overdue_fee_days')->nullable();
            $table->decimal('overdue_fee_amount', 12, 2)->nullable();
            $table->timestamp('overdue_fee_applied_at')->nullable();
            $table->decimal('overdue_amount_paid', 12, 2)->nullable();
            $table->string('overdue_payment_method', 100)->nullable();
            $table->string('overdue_payment_kind', 20)->nullable();
            $table->foreignId('overdue_payment_received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('overdue_payment_shift_session_id')->nullable()->constrained('shift_sessions')->nullOnDelete();
            $table->timestamp('overdue_paid_at')->nullable();
            $table->decimal('overdue_change_amount', 12, 2)->nullable();
            $table->softDeletes();

            $table->index('status');
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorial_rentals');
    }
};
