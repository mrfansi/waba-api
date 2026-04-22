<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('channel_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['outbound', 'inbound']);
            $table->string('to_number', 32);
            $table->string('from_number', 32);
            $table->enum('type', ['text', 'media', 'template', 'interactive', 'location', 'contact']);
            $table->json('payload');
            $table->enum('status', ['pending', 'sending', 'sent', 'delivered', 'read', 'failed'])->default('pending');
            $table->string('provider_message_id', 128)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignUlid('api_key_id')->nullable()->constrained('channel_api_keys')->nullOnDelete();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['channel_id', 'status', 'created_at']);
            $table->index(['channel_id', 'provider_message_id']);
            $table->unique(['api_key_id', 'idempotency_key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
