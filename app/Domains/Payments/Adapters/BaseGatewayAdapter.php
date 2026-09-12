<?php

namespace App\Domains\Payments\Adapters;

use App\Domains\Billing\Models\Currency;
use App\Domains\Payments\Contracts\PaymentGateway;
use App\Domains\Payments\Exceptions\GatewayFailed;
use App\Domains\Payments\Models\PaymentGatewayCredential;
use App\Domains\Payments\Models\PaymentGatewayRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * What every HTTP-speaking gateway adapter shares.
 *
 * The important part is what does NOT come back out: a gateway's own error
 * text never crosses this boundary. Payment APIs commonly echo the failing
 * request into their error body, and that request carried the merchant secret.
 * A class and a status is enough to act on and cannot leak.
 */
abstract class BaseGatewayAdapter implements PaymentGateway
{
    protected PaymentGatewayRecord $gateway;

    public function forGateway(PaymentGatewayRecord $gateway): static
    {
        $clone = clone $this;
        $clone->gateway = $gateway;

        return $clone;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function supportedCurrencies(): array
    {
        return (array) ($this->gateway->supported_currencies ?? []);
    }

    public function supportedCountries(): array
    {
        return (array) ($this->gateway->supported_countries ?? []);
    }

    protected function credential(): PaymentGatewayCredential
    {
        $credential = $this->gateway->activeCredential();

        if (! $credential) {
            throw new GatewayFailed(GatewayFailed::AUTHENTICATION);
        }

        return $credential;
    }

    /**
     * An authenticated client.
     *
     * The credential is read HERE and nowhere else in an adapter, so there is
     * one place to audit and no chance of it being logged on the way past.
     */
    abstract protected function client(): PendingRequest;

    protected function baseClient(): PendingRequest
    {
        return Http::timeout($this->gateway->timeout_seconds ?: 30)
            ->connectTimeout(min(15, $this->gateway->timeout_seconds ?: 15))
            ->acceptJson()
            ->asJson()
            ->withOptions(['http_errors' => false]);
    }

    protected function url(string $path): string
    {
        return rtrim((string) $this->gateway->api_base_url, '/').'/'.ltrim($path, '/');
    }

    /**
     * Send a request and time it, converting every failure into a class.
     *
     * @param  callable(PendingRequest): Response  $send
     * @return array{0: Response, 1: int}
     */
    protected function send(callable $send): array
    {
        $startedAt = hrtime(true);

        try {
            $response = $send($this->client());
        } catch (ConnectionException $e) {
            // A blocked outbound connection and a slow gateway are different
            // problems with different remedies, and an owner on shared hosting
            // hits the first constantly.
            throw new GatewayFailed(
                str_contains(strtolower($e->getMessage()), 'timed out')
                    ? GatewayFailed::TIMEOUT
                    : GatewayFailed::NETWORK,
                null,
                $this->elapsed($startedAt),
            );
        } catch (GatewayFailed $e) {
            // Already classified — usually a missing credential from client().
            throw $e;
        } catch (\Throwable) {
            throw new GatewayFailed(GatewayFailed::UNKNOWN, null, $this->elapsed($startedAt));
        }

        $latency = $this->elapsed($startedAt);

        if ($response->failed()) {
            throw new GatewayFailed($this->classify($response), $response->status(), $latency);
        }

        return [$response, $latency];
    }

    protected function classify(Response $response): string
    {
        return GatewayFailed::fromHttpStatus($response->status());
    }

    /**
     * Strip anything that must never be stored from a payload we keep.
     *
     * Two rules: never a card number, and never a secret. The blueprint's
     * "never store raw payment card data" is enforced HERE, at the point
     * anything from a gateway is written down, rather than trusted to every
     * caller remembering.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function scrub(array $payload): array
    {
        $forbidden = [
            'card', 'card_number', 'number', 'cvv', 'cvc', 'expiry_month', 'expiry_year',
            'key_secret', 'secret', 'password', 'authorization', 'signature',
            'token', 'vpa_pin',
        ];

        $clean = [];

        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $forbidden, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->scrub($value) : $value;
        }

        return $clean;
    }

    /**
     * Compare signatures in constant time.
     *
     * `===` on a signature leaks its content through timing. It is one
     * function call to do this correctly and there is no reason not to.
     */
    protected function signatureMatches(string $expected, string $received): bool
    {
        return $expected !== '' && $received !== '' && hash_equals($expected, $received);
    }

    /** Minor units — paise, cents. Gateways charge in integers, not floats. */
    protected function toMinorUnits(float $amount, string $currency): int
    {
        $places = Currency::find3($currency)?->decimal_places ?? 2;

        return (int) round($amount * (10 ** $places));
    }

    protected function fromMinorUnits(int|float|string $amount, string $currency): float
    {
        $places = Currency::find3($currency)?->decimal_places ?? 2;

        return round(((float) $amount) / (10 ** $places), $places);
    }

    protected function elapsed(float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
