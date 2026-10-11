<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per physical item (a broom, a hanger batch, a wardrobe
     * display) registered under a fixed-asset account (1600 Equipment /
     * 1650 Supplies) — the account-level "Fixed Assets" report only ever
     * showed a combined total per account, not which items make it up.
     * Registering an item here also posts its purchase as a real journal
     * entry (mirrors expenses' own account_id/payment_account_id/
     * journal_entry_id shape), same as every other accounting-recording
     * table in this app.
     */
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained('stores')->restrictOnDelete();
            $table->string('name', 255);
            // Which fixed-asset account this item belongs to (1600/1650).
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('purchase_date');
            $table->decimal('purchase_price', 14, 2);
            // Cash/Bank account credited (is_cash_equivalent=true).
            $table->foreignId('payment_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Back-reference to the journal entry posted atomically for the purchase.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->boolean('is_tutorial')->default(false);
            $table->boolean('is_tutorial_practice')->default(false);
            $table->timestamps();

            $table->index(['store_id', 'account_id']);
        });

        // Lets a Manual Journal Entry (the depreciation posting) point back
        // at the specific item it depreciates, so "Total Depreciated" can be
        // computed per item instead of only per account. Nullable — every
        // OTHER kind of manual entry (that isn't about a registered asset)
        // leaves this null, same as reference_type/reference_id already do.
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('fixed_asset_id')->nullable()->after('reference_id')
                ->constrained('fixed_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fixed_asset_id');
        });
        Schema::dropIfExists('fixed_assets');
    }
};
