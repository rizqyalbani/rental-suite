<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('kind', 80)->index();
            $table->string('severity', 16)->default('low')->index();
            $table->string('ip', 64)->nullable()->index();
            $table->string('method', 12)->nullable();
            $table->string('path', 512)->nullable();
            $table->text('query')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};

