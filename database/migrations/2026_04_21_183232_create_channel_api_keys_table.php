<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_api_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('channel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('prefix', 12)->unique();
            $table->string('key_hash', 64);
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['channel_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_api_keys');
    }
};
