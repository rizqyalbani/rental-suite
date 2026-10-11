<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outfit_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['ok', 'missing', 'damaged']);
            $table->text('note')->nullable();
            $table->foreignId('marked_by_user_id')->constrained('users');
            $table->timestamps();

            // One row per outfit per session, written the moment it's actually
            // checked — "ok" included, not just problems, so an outfit with no
            // row here means genuinely unchecked ("Not Checked"), never
            // silently assumed present. Re-marking the same outfit updates
            // this row instead of piling up duplicates.
            $table->unique(['stock_opname_id', 'outfit_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
    }
};
