<?php

namespace Tests\Feature\Theming;

use App\Domains\Theming\Services\CssSanitiser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Custom CSS is the one genuinely dangerous field in the theme system. It is
 * Super Admin only, and it is still sanitised — permission gating answers
 * "who", never "what".
 */
class CssSanitiserTest extends TestCase
{
    private function sanitiser(): CssSanitiser
    {
        return new CssSanitiser;
    }

    public static function dangerousCss(): array
    {
        return [
            'remote stylesheet' => ["@import url('https://evil.test/x.css');", 'evil.test'],
            'protocol-relative import' => ["@import '//evil.test/x.css';", 'evil.test'],
            'remote font fetch' => ['@font-face{src:url(https://evil.test/f.woff2)}', 'evil.test'],
            'protocol-relative url' => ['body{background:url(//evil.test/a.png)}', 'evil.test'],
            'data url' => ['body{background:url(data:image/svg+xml,<svg onload=alert(1)>)}', 'data:'],
            'legacy expression' => ['body{width:expression(alert(1))}', 'expression('],
            'behavior binding' => ['body{behavior:url(x.htc)}', 'behavior:'],
            'moz binding' => ['body{-moz-binding:url(x.xml)}', '-moz-binding'],
            'javascript url' => ['a{background:url(javascript:alert(1))}', 'javascript:'],
            'charset shift' => ['@charset "UTF-16";body{color:red}', '@charset'],
            'element escape' => ['</style><script>alert(1)</script>', '<script'],
        ];
    }

    #[DataProvider('dangerousCss')]
    public function test_dangerous_constructs_are_removed(string $css, string $needle): void
    {
        $this->assertStringNotContainsStringIgnoringCase($needle, $this->sanitiser()->sanitise($css));
    }

    /**
     * Stripping comments AFTER the forbidden patterns would let a construct
     * hide inside one and be reassembled by a lenient parser.
     */
    public function test_a_construct_hidden_inside_a_comment_cannot_survive(): void
    {
        $out = $this->sanitiser()->sanitise('@im/* */port url(https://evil.test/x.css);');

        $this->assertStringNotContainsString('evil.test', $out);
    }

    public function test_the_element_cannot_be_closed_from_inside(): void
    {
        $out = $this->sanitiser()->sanitise('body{color:red}</style ><img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('</style', $out);
    }

    public function test_ordinary_css_is_left_usable(): void
    {
        $css = '.hero{padding:2rem;border-radius:var(--radius-lg)}';

        $this->assertSame($css, $this->sanitiser()->sanitise($css));
    }

    public function test_a_megabyte_of_css_is_capped(): void
    {
        $this->assertLessThanOrEqual(50000, strlen($this->sanitiser()->sanitise(str_repeat('a{color:red}', 100000))));
    }

    /** The administrator is told what was taken out, not silently overruled. */
    public function test_the_report_names_what_was_removed(): void
    {
        $report = $this->sanitiser()->report("@import 'x.css'; body{background:url(https://evil.test/a.png)}");

        $this->assertContains('@import (remote stylesheets)', $report);
        $this->assertContains('remote url() fetches', $report);
        $this->assertSame([], $this->sanitiser()->report('.hero{padding:2rem}'));
    }
}
