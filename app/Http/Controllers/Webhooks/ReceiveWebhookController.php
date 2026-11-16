<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookReceipt;
use App\Http\Controllers\Controller;
use App\Services\Webhooks\Contracts\PaymentProvider;
use App\Services\Webhooks\Exceptions\InvalidWebhookPayload;
use App\Services\Webhooks\Exceptions\MalformedWebhookPayload;
use App\Services\Webhooks\ReceivePaymentWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use stdClass;

/**
 * POST /api/v1/webhooks/payment — runs after VerifyWebhookSignature authenticated the sender
 * (contracts/webhook-http.md).
 */
class ReceiveWebhookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, ReceivePaymentWebhook $receiver): JsonResponse
    {
        /** @var string $providerId */
        $providerId = $request->attributes->get('payment_provider_id');
        /** @var PaymentProvider $provider */
        $provider = $request->attributes->get('payment_provider');

        $rawBody = $request->getContent();
        $payload = $this->decodeObject($rawBody);

        $validator = Validator::make($payload, $provider->rules());

        if ($validator->fails()) {
            throw new InvalidWebhookPayload($validator);
        }

        $event = $provider->toPaymentEvent($providerId, $validator->validated());
        $receipt = $receiver->handle($event, $payload, $rawBody);

        if ($receipt === WebhookReceipt::Duplicate) {
            return $this->receiptResponse($receipt, $event->eventId);
        }

        Log::info('webhook.accepted', [
            'provider' => $event->provider,
            'event_id' => $event->eventId,
            'event_type' => $event->type,
            'livemode' => $event->livemode,
            'verified_with' => $request->attributes->get('signature_verified_with'),
        ]);

        return $this->receiptResponse($receipt, $event->eventId);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MalformedWebhookPayload
     */
    private function decodeObject(string $rawBody): array
    {
        if ($rawBody === '' || ! json_validate($rawBody) || ! json_decode($rawBody) instanceof stdClass) {
            throw new MalformedWebhookPayload();
        }

        return json_decode($rawBody, true);
    }

    private function receiptResponse(WebhookReceipt $receipt, string $eventId): JsonResponse
    {
        return response()->json([
            'status' => $receipt->value,
            'message' => match ($receipt) {
                WebhookReceipt::Accepted => 'Event accepted for processing.',
                WebhookReceipt::Duplicate => 'Event already received and is being processed.',
            },
            'event_id' => $eventId,
            'correlation_id' => (string) Context::get('correlation_id'),
        ]);
    }
}
