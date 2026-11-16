<?php

use App\Http\Controllers\Webhooks\ReceiveWebhookController;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/webhooks/payment', ReceiveWebhookController::class)
        ->middleware(VerifyWebhookSignature::class)
        ->name('webhooks.payment');
});
