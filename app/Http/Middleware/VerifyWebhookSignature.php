<?php

namespace App\Http\Middleware;

use App\Services\Webhooks\PaymentProviderRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifies the provider from X-Payment-Provider and authenticates the raw request before
 * anything else reads the body (401 for both an unknown provider and a bad signature).
 */
class VerifyWebhookSignature
{
    public const PROVIDER_HEADER = 'X-Payment-Provider';

    public function __construct(private readonly PaymentProviderRegistry $providers)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $providerId = $request->header(self::PROVIDER_HEADER);
        $providerId = is_string($providerId) ? $providerId : null;

        $provider = $this->providers->resolve($providerId);

        Context::add('payment_provider', $providerId);

        $verification = $provider->verify($request);

        $request->attributes->add([
            'payment_provider_id' => $providerId,
            'payment_provider' => $provider,
            'signature_verified_with' => $verification->verifiedWith,
        ]);

        return $next($request);
    }
}
