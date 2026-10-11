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
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete, not cascade: financial history must never be
            // silently destroyed by a store deletion.
            $table->foreignId('store_id')->nullable()->constrained('stores')->restrictOnDelete();
            $table->date('entry_date');
            $table->string('memo', 255)->nullable();
            // What created this entry: 'expense' | 'cash_movement' | 'manual'.
            // Plain int reference_id, not a polymorphic relation — this
            // codebase has no morphTo precedent anywhere, stay consistent.
            $table->string('reference_type', 30)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_reversal')->default(false);
            $table->foreignId('reverses_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'entry_date']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
