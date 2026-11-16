<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * One row per (provider, event_id, channel): the idempotency key of channel deliveries.
     * Deliberately without a foreign key to webhook_events, because a channel job may run before
     * the transaction storing the event commits (specs/002-payment-webhook-notifications/research.md R7).
     */
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->string('event_id', 255);
            $table->string('channel', 50);
            $table->string('status', 20)->default('pending');
            $table->smallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('correlation_id', 128);
            $table->timestampsTz();

            $table->unique(['provider', 'event_id', 'channel']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
