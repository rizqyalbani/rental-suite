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
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained('stores')->restrictOnDelete();
            $table->date('movement_date');
            // 'in' | 'out' — app-validated.
            $table->string('direction', 10);
            // The till/bank moving (is_cash_equivalent=true).
            $table->foreignId('cash_account_id')->constrained('accounts')->restrictOnDelete();
            // The other leg — any account (Equity for an owner deposit, Bank
            // for a till-to-bank deposit i.e. "monthly deposit", Revenue for
            // misc income, etc.).
            $table->foreignId('counter_account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('description', 500)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'movement_date']);
            $table->index('cash_account_id');
            $table->index('counter_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
