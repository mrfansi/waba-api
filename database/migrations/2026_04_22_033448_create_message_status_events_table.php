<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_status_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('message_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32);
            $table->timestamp('occurred_at');
            $table->json('raw_payload');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['message_id', 'occurred_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_status_events');
    }
};
