<?php

namespace App\Domains\AI\Adapters;

use App\Domains\AI\Contracts\ProviderAdapter;
use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Routing\RetryPolicy;
use App\Domains\AI\Support\ErrorClass;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * What every HTTP-speaking adapter shares: authentication, timing, retries and
 * — most importantly — turning a provider's failure into one of Aziv AI's
 * error classes without ever keeping its words.
 */
abstract class BaseAdapter implements ProviderAdapter
{
    protected AiProvider $provider;

    public function forProvider(AiProvider $provider): static
    {
        $clone = clone $this;
        $clone->provider = $provider;

        return $clone;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    protected function credential(): AiProviderCredential
    {
        $credential = $this->provider->activeCredential();

        if (! $credential) {
            throw new ProviderFailed(ErrorClass::AUTHENTICATION);
        }

        return $credential;
    }

    /**
     * An authenticated client for this provider.
     *
     * The credential is read HERE and nowhere else in the adapter, so there is
     * one place to audit and no chance of it being logged on the way past.
     */
    protected function client(): PendingRequest
    {
        $credential = $this->credential();
        $secret = $credential->secret();
        $extra = $credential->extra();

        $request = Http::timeout($this->provider->timeout_seconds ?: 60)
            ->connectTimeout(min(15, $this->provider->timeout_seconds ?: 15))
            ->acceptJson()
            ->withOptions(['http_errors' => false]);

        $request = match ($this->provider->auth_method) {
            'header' => $request->withHeaders([
                ($extra['header_name'] ?? 'X-API-Key') => $secret,
            ]),
            'query' => $request->withQueryParameters([
                ($extra['query_name'] ?? 'key') => $secret,
            ]),
            default => $request->withToken($secret),
        };

        foreach ((array) ($extra['headers'] ?? []) as $name => $value) {
            $request = $request->withHeaders([$name => $value]);
        }

        return $request;
    }

    protected function url(string $path): string
    {
        return rtrim((string) $this->provider->api_base_url, '/').'/'.ltrim($path, '/');
    }

    /**
     * Send a request and time it, converting every failure mode into a
     * ProviderFailed carrying a class.
     *
     * @param  callable(PendingRequest): Response  $send
     * @return array{0: Response, 1: int} the response and its latency in ms
     */
    protected function send(callable $send): array
    {
        $startedAt = hrtime(true);

        try {
            $response = $send($this->client());
        } catch (ConnectionException $e) {
            $latency = $this->elapsed($startedAt);

            // A connection failure and a timeout are different problems with
            // different remedies, and an owner on shared hosting hits the first
            // constantly — outbound HTTPS is often blocked.
            throw new ProviderFailed(
                str_contains(strtolower($e->getMessage()), 'timed out') ? ErrorClass::TIMEOUT : ErrorClass::NETWORK,
                null,
                $latency,
            );
        } catch (ProviderFailed $e) {
            // Already classified — usually a missing credential raised by
            // client(). Re-classifying it as "unknown" would throw away the
            // one thing that tells an owner what to do.
            throw $e;
        } catch (\Throwable) {
            throw new ProviderFailed(ErrorClass::UNKNOWN, null, $this->elapsed($startedAt));
        }

        $latency = $this->elapsed($startedAt);

        if ($response->failed()) {
            throw new ProviderFailed(
                $this->classify($response),
                $response->status(),
                $latency,
                // A header, not prose: safe to keep, and the only thing that
                // makes a back-off match what the provider actually wants.
                app(RetryPolicy::class)->retryAfterFrom($response->header('Retry-After')),
            );
        }

        return [$response, $latency];
    }

    /**
     * Classify a failure from its status, refined by the body where that is
     * safe.
     *
     * Only well-known machine-readable markers are read — never free text —
     * because free text is where a provider echoes the request back.
     */
    protected function classify(Response $response): string
    {
        $status = $response->status();

        if ($status === 400 || $status === 404) {
            $body = $response->json();
            $code = strtolower((string) (
                data_get($body, 'error.code')
                ?? data_get($body, 'error.type')
                ?? data_get($body, 'error.status')
                ?? ''
            ));

            return match (true) {
                str_contains($code, 'context_length') || str_contains($code, 'too_long') => ErrorClass::CONTEXT_TOO_LONG,
                str_contains($code, 'model_not_found') || str_contains($code, 'not_found') => ErrorClass::MODEL_NOT_FOUND,
                str_contains($code, 'content_filter') || str_contains($code, 'safety') => ErrorClass::CONTENT_FILTERED,
                str_contains($code, 'insufficient_quota') || str_contains($code, 'billing') => ErrorClass::QUOTA_EXCEEDED,
                str_contains($code, 'invalid_api_key') || str_contains($code, 'unauthenticated') => ErrorClass::AUTHENTICATION,
                default => ErrorClass::fromHttpStatus($status),
            };
        }

        return ErrorClass::fromHttpStatus($status);
    }

    protected function elapsed(float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
