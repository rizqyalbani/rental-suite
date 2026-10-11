<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per fixed question key from the PPN client-briefing checklist
 * (Help → "VAT Questions for the Client") — deliberately NOT store-scoped,
 * since several of the questions (e.g. "one or two tax IDs (NPWP) for 2 stores")
 * are about the business as a whole, not any one store. A handful of known
 * keys, so a simple key/value shape (matching store_settings' own pattern)
 * beats a dedicated column per question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppn_briefing_answers', function (Blueprint $table) {
            $table->id();
            $table->string('question_key', 60)->unique();
            $table->text('answer')->nullable();
            $table->foreignId('answered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppn_briefing_answers');
    }
};
