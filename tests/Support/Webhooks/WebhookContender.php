<?php

namespace Tests\Support\Webhooks;

use App\Events\PaymentEventReceived;
use App\Services\Webhooks\Providers\GenericGatewayProvider;
use App\Services\Webhooks\ReceivePaymentWebhook;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Builds closures that receive the same webhook in a child process against PostgreSQL.
 * Child processes inherit the sqlite test environment, so the closure switches to pgsql itself.
 */
final class WebhookContender
{
    /**
     * @param  array<string, mixed>  $payload
     * @return Closure(): string
     */
    public static function receive(array $payload, string $rawBody, string $database): Closure
    {
        return static function () use ($payload, $rawBody, $database): string {
            config([
                'database.default' => 'pgsql',
                'database.connections.pgsql.database' => $database,
            ]);
            DB::purge('pgsql');
            Event::fake([PaymentEventReceived::class]);

            $event = app(GenericGatewayProvider::class, ['options' => []])->toPaymentEvent('generic', $payload);

            return app(ReceivePaymentWebhook::class)->handle($event, $payload, $rawBody)->value;
        };
    }
}
