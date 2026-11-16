<?php

namespace App\Services\Webhooks;

use App\Services\Webhooks\Contracts\PaymentProvider;
use App\Services\Webhooks\Exceptions\PaymentProviderMisconfigured;
use App\Services\Webhooks\Exceptions\UnknownPaymentProvider;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Resolves the provider named in the X-Payment-Provider header from config('webhooks.providers').
 */
final class PaymentProviderRegistry
{
    private const IDENTIFIER_PATTERN = '/^[a-z0-9-]{1,50}$/D';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @throws UnknownPaymentProvider
     * @throws PaymentProviderMisconfigured
     */
    public function resolve(?string $providerId): PaymentProvider
    {
        if ($providerId === null || preg_match(self::IDENTIFIER_PATTERN, $providerId) !== 1) {
            throw new UnknownPaymentProvider($providerId);
        }

        /** @var array<string, mixed>|null $config */
        $config = config("webhooks.providers.{$providerId}");

        if (! is_array($config)) {
            throw new UnknownPaymentProvider($providerId);
        }

        if (blank($config['secret'] ?? null)) {
            throw new PaymentProviderMisconfigured($providerId, ['secret']);
        }

        $provider = $this->container->make($config['driver'], ['options' => $config]);

        if (! $provider instanceof PaymentProvider) {
            throw new LogicException("Driver of payment provider [{$providerId}] must implement ".PaymentProvider::class.'.');
        }

        return $provider;
    }
}
