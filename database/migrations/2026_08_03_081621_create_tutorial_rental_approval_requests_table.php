<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutorial_rental_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable();
            $table->foreign('store_id', 'trar_store_fk')->references('id')->on('stores');
            $table->foreignId('requested_by_user_id');
            $table->foreign('requested_by_user_id', 'trar_requested_by_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreignId('decided_by_user_id')->nullable();
            $table->foreign('decided_by_user_id', 'trar_decided_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreignId('source_shift_session_id')->nullable();
            $table->foreign('source_shift_session_id', 'trar_source_shift_fk')->references('id')->on('shift_sessions')->nullOnDelete();
            $table->longText('payload');
            $table->string('status', 20)->default('pending');
            $table->boolean('is_tutorial')->default(true);
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorial_rental_approval_requests');
    }
};
