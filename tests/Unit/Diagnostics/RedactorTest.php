<?php

namespace Tests\Unit\Diagnostics;

use App\Domains\Diagnostics\Support\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    #[DataProvider('secrets')]
    public function test_it_removes_secret_shaped_values(string $input, string $mustNotContain): void
    {
        $this->assertStringNotContainsString(
            $mustNotContain,
            Redactor::scrub($input),
            'A secret-shaped value survived redaction. The diagnostic report is designed to be '
            .'forwarded to a hosting provider, so this is a disclosure bug.'
        );
    }

    public static function secrets(): array
    {
        return [
            'connection string password' => ['mysql://aziv:hunter2@127.0.0.1/aziv', 'hunter2'],
            'bearer token' => ['Authorization: Bearer sk-proj-AbCdEf1234567890XyZ', 'sk-proj-AbCdEf1234567890XyZ'],
            'env assignment' => ['DB_PASSWORD=s3cr3tvalue failed', 's3cr3tvalue'],
            'razorpay key' => ['key rzp_live_AbCd1234EfGh invalid', 'rzp_live_AbCd1234EfGh'],
            'google api key' => ['AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q', 'AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q'],
            'app secret' => ['APP_SECRET=abc123xyz789', 'abc123xyz789'],
        ];
    }

    public function test_it_leaves_ordinary_diagnostic_text_intact(): void
    {
        $text = 'Connected to 10.11.14-MariaDB in 5ms.';
        $this->assertSame($text, Redactor::scrub($text));
    }

    public function test_it_handles_empty_input(): void
    {
        $this->assertSame('', Redactor::scrub(null));
        $this->assertSame('', Redactor::scrub(''));
    }
}
