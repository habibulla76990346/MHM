<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;
use Illuminate\Support\Facades\Http;

/**
 * THE check that replaced blocker E-2.
 *
 * The owner has not chosen a hosting provider and should not have to.
 * Pre-verifying one specific server is the wrong shape of answer for software
 * that must install anywhere; testing every server it lands on is correct in
 * every case. A host that blocks outbound HTTPS cannot run Aziv AI at all —
 * so this is Critical in BOTH deployment modes, and the installer refuses to
 * complete when it fails.
 *
 * No provider credential is used: the probe is an unauthenticated request to a
 * well-known endpoint. We are testing the network, not the account.
 */
class OutboundHttpsCheck extends BaseCheck
{
    /** Unauthenticated, low-cost, stable endpoints. */
    private const PROBES = [
        'https://api.openai.com/v1/models',   // 401 expected — reaching it is the point
        'https://generativelanguage.googleapis.com/v1beta/models',
    ];

    public function key(): string { return 'network.outbound_https'; }
    public function title(): string { return 'Outbound HTTPS connectivity'; }
    public function category(): Category { return Category::Network; }

    public function run(): CheckResult
    {
        $errors = [];
        $reachedHost = null;
        $latency = null;

        foreach (self::PROBES as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            $start = microtime(true);
            try {
                $response = Http::timeout(10)->connectTimeout(8)->withoutVerifying(false)->get($url);
                // ANY HTTP response proves the connection was established.
                // 401/403 is a success for this check — we reached the server.
                $reachedHost = $host;
                $latency = (microtime(true) - $start) * 1000;
                break;
            } catch (\Throwable $e) {
                $errors[] = $host.': '.$this->classify($e);
            }
        }

        $ok = $reachedHost !== null;

        return new CheckResult(
            key: $this->key(),
            title: $ok ? $this->title() : 'Outbound HTTPS connection failed',
            category: $this->category(),
            status: $ok ? Status::Green : Status::Red,
            severity: $ok ? Severity::Informational : Severity::Critical,
            responsibility: Responsibility::Network,
            technicalReason: $ok
                ? sprintf('Reached %s in %dms.', $reachedHost, (int) $latency)
                : 'Could not establish an outbound HTTPS connection. '.implode(' | ', $errors),
            recommendedAction: $ok
                ? ''
                : 'This server could not open an outbound HTTPS connection. Aziv AI needs this for every AI request and every payment gateway call — without it the platform cannot function.',
            adminAction: $ok
                ? ''
                : 'Contact your hosting provider and ask whether outgoing HTTPS (port 443) requests to external APIs are permitted on your plan.',
            requiresHostingSupport: ! $ok,
            supportWording: $ok
                ? ''
                : 'Does my hosting plan allow outgoing HTTPS (port 443) cURL requests to external APIs? Requests to api.openai.com are currently failing.',
            durationMs: $latency ?? 0.0,
        );
    }

    private function classify(\Throwable $e): string
    {
        $m = $e->getMessage();

        return match (true) {
            str_contains($m, 'Could not resolve host') => 'DNS resolution failed',
            str_contains($m, 'Connection refused') => 'connection refused',
            str_contains($m, 'Connection timed out'), str_contains($m, 'timed out') => 'connection timed out',
            str_contains($m, 'SSL'), str_contains($m, 'certificate') => 'TLS/certificate error',
            default => 'connection failed ('.class_basename($e).')',
        };
    }
}
