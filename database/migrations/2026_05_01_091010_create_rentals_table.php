<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outfit_id')->constrained()->cascadeOnDelete();
            $table->string('customer_name');
            $table->string('phone', 20);
            $table->text('address');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['active', 'returned', 'cancelled'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rentals');
    }
};
