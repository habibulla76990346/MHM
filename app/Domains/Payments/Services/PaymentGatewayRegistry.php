<?php

namespace App\Domains\Payments\Services;

use App\Domains\Payments\Adapters\FixtureGatewayAdapter;
use App\Domains\Payments\Adapters\RazorpayAdapter;
use App\Domains\Payments\Contracts\PaymentGateway;
use App\Domains\Payments\Models\PaymentGatewayRecord;

/**
 * The ONE place a gateway's name appears (Addendum D §8).
 *
 * Everything else in the platform holds a gateway id and calls the interface.
 * Adding a sixth gateway is an adapter class and a row here — no migration, no
 * change to subscriptions, plans, invoices, credits or checkout.
 *
 * This file is the single exception the "no gateway name in billing code" gate
 * allows, alongside the adapters themselves. If a name ever appears anywhere
 * else, that gate fails the build.
 */
class PaymentGatewayRegistry
{
    /** @var array<string, class-string<PaymentGateway>> */
    private array $adapters = [
        RazorpayAdapter::KEY => RazorpayAdapter::class,
        // Development only, and it says so at every entry point: it takes no
        // money, calls nothing, and never reports a payment as complete.
        FixtureGatewayAdapter::KEY => FixtureGatewayAdapter::class,
    ];

    /** @var array<string, PaymentGateway> resolved per gateway id */
    private array $resolved = [];

    /** @return array<string, class-string<PaymentGateway>> */
    public function all(): array
    {
        return $this->adapters;
    }

    /** @return array<string, string> key => display name, for the admin picker */
    public function options(): array
    {
        $options = [];

        foreach ($this->adapters as $key => $class) {
            $options[$key] = ucfirst($key);
        }

        return $options;
    }

    public function register(string $key, string $adapterClass): void
    {
        $this->adapters[$key] = $adapterClass;
    }

    public function knows(string $key): bool
    {
        return isset($this->adapters[$key]);
    }

    /**
     * The adapter for a configured gateway, bound to its row.
     *
     * Returns null rather than throwing when a gateway names an adapter that
     * no longer exists: an upgrade that removed one must leave the rest of the
     * platform working, and the selector treats a gateway with no adapter as
     * simply unavailable.
     */
    public function for(?PaymentGatewayRecord $gateway): ?PaymentGateway
    {
        if (! $gateway) {
            return null;
        }

        $cacheKey = (string) $gateway->getKey();

        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        $class = $gateway->adapter_class ?: ($this->adapters[$gateway->key] ?? null);

        if (! $class || ! class_exists($class) || ! is_subclass_of($class, PaymentGateway::class)) {
            return null;
        }

        /** @var PaymentGateway $adapter */
        $adapter = app($class);

        return $this->resolved[$cacheKey] = $adapter->forGateway($gateway);
    }

    /** Resolve by the gateway's key — used by the webhook route. */
    public function byKey(string $key): ?PaymentGateway
    {
        return $this->for(PaymentGatewayRecord::where('key', $key)->first());
    }

    /** Forget resolved adapters, so a credential change takes effect at once. */
    public function flush(): void
    {
        $this->resolved = [];
    }
}
