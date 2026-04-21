<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 64)->unique();
            $table->string('display_name', 128);
            $table->string('driver', 32);
            $table->string('phone_number', 32);
            $table->string('phone_number_id', 64)->nullable();
            $table->text('credentials');
            $table->string('webhook_secret', 64);
            $table->json('settings')->nullable();
            $table->enum('status', ['active', 'disabled', 'pending'])->default('pending');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['driver', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
