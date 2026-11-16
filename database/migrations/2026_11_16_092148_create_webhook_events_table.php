<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Accepted payment webhooks. One row per (provider, event_id): the unique index is the
     * deduplication key (specs/002-payment-webhook-notifications/data-model.md §1.1).
     */
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->string('event_id', 255);
            $table->string('event_type', 100);
            $table->boolean('livemode');
            $table->timestampTz('occurred_at');
            $table->jsonb('payload');
            $table->char('payload_sha256', 64);
            $table->string('correlation_id', 128);
            $table->timestampTz('received_at');
            $table->timestampsTz();

            $table->unique(['provider', 'event_id']);
            $table->index('correlation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
