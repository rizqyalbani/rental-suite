<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_payment_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_id')->constrained('rentals')->cascadeOnDelete();
            $table->foreignId('shift_session_id')->nullable()->constrained('shift_sessions')->nullOnDelete();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_kind', 50)->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->index(['shift_session_id', 'recorded_at']);
            $table->index(['rental_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_payment_records');
    }
};
