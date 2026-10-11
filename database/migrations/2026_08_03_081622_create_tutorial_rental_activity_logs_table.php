<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutorial_rental_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained('stores')->cascadeOnDelete();
            // cascadeOnDelete: deleting a TutorialRental (e.g. tutorialCleanup())
            // auto-cleans its log entries, same as the real table's FK — no
            // separate manual delete step needed for this table specifically.
            $table->foreignId('rental_id')->nullable()->constrained('tutorial_rentals')->cascadeOnDelete();
            $table->boolean('is_tutorial')->default(true);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->json('changes')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['rental_id', 'created_at']);
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorial_rental_activity_logs');
    }
};
