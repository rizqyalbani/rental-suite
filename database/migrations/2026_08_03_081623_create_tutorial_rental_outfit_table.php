<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutorial_rental_outfit', function (Blueprint $table) {
            $table->foreignId('rental_id')->constrained('tutorial_rentals')->cascadeOnDelete();
            $table->foreignId('outfit_id')->constrained('outfits')->cascadeOnDelete();
            $table->decimal('discount', 12, 2)->nullable();
            $table->string('discount_type', 10)->default('fixed');
            $table->string('discount_note', 500)->nullable();
            $table->decimal('price_incl_tax', 12, 2)->nullable();
            $table->decimal('price_excl_tax', 12, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();

            $table->primary(['rental_id', 'outfit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorial_rental_outfit');
    }
};
