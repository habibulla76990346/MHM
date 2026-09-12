<?php

namespace Tests\Feature\Billing;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Two of the owner's governing constraints, turned into a gate.
 *
 *   *"GST must NOT be hard-coded."* (Addendum F)
 *   *"Do not hard-code Razorpay-specific logic into subscriptions, plans,
 *   invoices or transactions."* (Addendum D)
 *
 * Both are stated in the plan as review rules. A review rule survives exactly
 * as long as the person who remembers it, so both are checked here instead.
 *
 * THE SCAN READS CODE, NOT PROSE. Comments and docblocks are stripped with
 * PHP's own tokeniser before anything is matched, because explaining WHY a tax
 * name must not appear inevitably requires writing the tax name. A naive grep
 * would fail on its own documentation, and the usual fix for that is to delete
 * the explanation — which is the wrong thing to lose.
 */
class NoHardCodedTaxOrGatewayTest extends TestCase
{
    /**
     * Tax names and treatments. None of these may appear in code: every one is
     * a row an administrator creates and names.
     */
    private const TAX_TOKENS = [
        'gst', 'cgst', 'sgst', 'igst', 'utgst', 'vat', 'hsn', 'gstin',
    ];

    /**
     * Gateway names. These may appear only inside their own adapter and the
     * registry that names them — nowhere near subscriptions, plans, invoices,
     * credits or checkout.
     */
    private const GATEWAY_TOKENS = [
        'razorpay', 'phonepe', 'payu', 'cashfree', 'ccavenue',
        'stripe', 'paddle', 'paypal',
    ];

    /**
     * Where a gateway name is legitimate: an adapter's own file, and the
     * registry that maps a key to a class. Everywhere else in the scanned area
     * is a defect.
     */
    private const GATEWAY_ALLOWED = [
        'app/Domains/Payments/Adapters/',
        'app/Domains/Payments/Services/PaymentGatewayRegistry.php',
    ];

    /**
     * The area the gateway rule governs — Addendum D's own list: plans and
     * subscriptions, invoices, credits, payments, and the checkout and webhook
     * paths.
     *
     * SCOPED ON PURPOSE. Scanning the whole application for a payment brand
     * that is also an ordinary English word flags a theme token called
     * "table-stripe", and a gate that cries wolf is a gate somebody switches
     * off. The rule is about billing code, so the check is too.
     */
    private const GATEWAY_SCOPE = [
        'app/Domains/Billing/',
        'app/Domains/Credits/',
        'app/Domains/Tax/',
        'app/Domains/Payments/',
        'app/Domains/Chat/',
        'app/Http/Controllers/Billing/',
        'app/Http/Controllers/Checkout/',
        'app/Http/Controllers/Webhooks/',
        'app/Filament/Resources/Plans/',
        'app/Filament/Resources/Invoices/',
        'app/Filament/Resources/Coupons/',
    ];

    public function test_no_tax_name_rate_or_code_appears_anywhere_in_application_code(): void
    {
        $offences = [];

        foreach ($this->phpFiles(dirname(__DIR__, 3).'/app') as $path => $code) {
            foreach ($this->identifiersAndStrings($code) as [$line, $text]) {
                if (array_intersect($this->words($text), self::TAX_TOKENS) !== []) {
                    $offences[] = "{$path}:{$line} — “{$text}”";
                }
            }
        }

        $this->assertSame([], $offences, implode("\n", array_merge(
            ['Tax is data, not code. These belong in tax_rates rows an administrator creates:'],
            $offences,
        )));
    }

    public function test_no_gateway_name_appears_in_billing_code(): void
    {
        $offences = [];

        foreach ($this->phpFiles(dirname(__DIR__, 3).'/app') as $path => $code) {
            if (! $this->isInGatewayScope($path) || $this->isAllowedToNameAGateway($path)) {
                continue;
            }

            foreach ($this->identifiersAndStrings($code) as [$line, $text]) {
                if (array_intersect($this->words($text), self::GATEWAY_TOKENS) !== []) {
                    $offences[] = "{$path}:{$line} — “{$text}”";
                }
            }
        }

        $this->assertSame([], $offences, implode("\n", array_merge(
            ['Subscriptions, plans, invoices, credits and checkout hold a gateway ID and call the interface:'],
            $offences,
        )));
    }

    private function isInGatewayScope(string $path): bool
    {
        foreach (self::GATEWAY_SCOPE as $directory) {
            if (str_starts_with($path, $directory)) {
                return true;
            }
        }

        return false;
    }

    private function isAllowedToNameAGateway(string $path): bool
    {
        foreach (self::GATEWAY_ALLOWED as $allowed) {
            if (str_contains($path, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every PHP file under a directory, keyed by its repository-relative path.
     *
     * A RecursiveIterator rather than glob('**'), which does not recurse in
     * PHP — a mistake that once left the hard-coded-colour gate checking two
     * directory levels for a whole phase.
     *
     * @return array<string, string>
     */
    private function phpFiles(string $directory): array
    {
        $root = dirname(__DIR__, 3).'/';
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[str_replace($root, '', $file->getPathname())] = file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * Split an identifier or string into its component words, lowercased.
     *
     * WHY WORDS AND NOT A SUBSTRING SEARCH. `$gstRate` has to be caught, and a
     * `\bgst\b` regex misses it because the next character is a letter. But a
     * plain substring search for "vat" flags `activatePeriod`, and a gate that
     * cries wolf gets switched off. Splitting on camelCase and separators and
     * then matching WHOLE words catches the first and ignores the second.
     *
     * @return array<int, string>
     */
    private function words(string $text): array
    {
        $parts = preg_split('/(?<!^)(?=[A-Z])|[^A-Za-z]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map('strtolower', $parts);
    }

    /**
     * Identifiers and string literals, with comments and docblocks removed.
     *
     * A LIST rather than a map keyed by line: two offences on one line are two
     * offences, and keying by line would silently report only the last.
     *
     * @return array<int, array{0: int, 1: string}>
     */
    private function identifiersAndStrings(string $code): array
    {
        $found = [];

        foreach (token_get_all($code) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            // Prose is not code. An explanation of why a name is forbidden has
            // to be able to use the name.
            if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                continue;
            }

            if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_STRING, T_VARIABLE, T_ENCAPSED_AND_WHITESPACE], true)) {
                $found[] = [$line, trim($text, "'\"")];
            }
        }

        return $found;
    }
}
