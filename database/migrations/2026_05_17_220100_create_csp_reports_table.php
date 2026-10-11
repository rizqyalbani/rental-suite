<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csp_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip', 64)->nullable()->index();
            $table->string('user_agent', 512)->nullable();
            $table->string('document_uri', 2048)->nullable();
            $table->string('referrer', 2048)->nullable();
            $table->string('blocked_uri', 2048)->nullable();
            $table->string('violated_directive', 512)->nullable();
            $table->string('effective_directive', 256)->nullable();
            $table->string('original_policy', 4096)->nullable();
            $table->string('disposition', 32)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csp_reports');
    }
};

