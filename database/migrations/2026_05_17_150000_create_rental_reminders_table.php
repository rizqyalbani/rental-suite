<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rental_id')->index();
            $table->string('kind')->index();
            $table->string('target')->nullable()->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('rental_id')->references('id')->on('rentals')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_reminders');
    }
};

