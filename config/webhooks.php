<?php

use App\Services\Webhooks\Providers\GenericGatewayProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Providers
    |--------------------------------------------------------------------------
    |
    | Providers accepted on POST /api/v1/webhooks/payment. The array key is the
    | provider identifier sent by the provider in the "X-Payment-Provider"
    | header and MUST match ^[a-z0-9-]{1,50}$. Adding a provider means adding a
    | class implementing App\Services\Webhooks\Contracts\PaymentProvider and an
    | entry below (see specs/002-payment-webhook-notifications/contracts).
    |
    | "secret" is required; "previous_secret" is optional and accepted only
    | during secret rotation. "tolerance" is the accepted clock difference in
    | seconds between the signature timestamp and the server.
    |
    */

    'providers' => [
        'generic' => [
            'driver' => GenericGatewayProvider::class,
            'secret' => env('WEBHOOK_GENERIC_SECRET'),
            'previous_secret' => env('WEBHOOK_GENERIC_PREVIOUS_SECRET'),
            'tolerance' => (int) env('WEBHOOK_SIGNATURE_TOLERANCE', 300),
        ],
    ],

];
