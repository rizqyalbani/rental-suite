<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_outfit', function (Blueprint $table) {
            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outfit_id')->constrained()->cascadeOnDelete();
            $table->primary(['rental_id', 'outfit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_outfit');
    }
};
