<?php

namespace Tests\Feature\Diagnostics;

use App\Domains\Diagnostics\CheckRegistry;
use App\Domains\Diagnostics\Services\ReportExporter;
use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The report an owner forwards to their hosting provider (Owner Addendum G).
 *
 * READ ADVERSARIALLY, because that is the threat model. The whole value of
 * this file is that it can be pasted into a support ticket read by six people
 * and archived for ever — which means the interesting question is not "does it
 * explain the problem" but "what did it take with it".
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private function exporter(): ReportExporter
    {
        return app(ReportExporter::class);
    }

    /** A finding carrying every shape of secret a real check might pick up. */
    private function leakyResults(): array
    {
        return [
            new CheckResult(
                key: 'fixture.leaky',
                title: 'Provider connectivity',
                category: Category::AiProviders,
                status: Status::Red,
                severity: Severity::Critical,
                responsibility: Responsibility::Configuration,
                technicalReason: 'Auth failed with key sk-live-ABCDEFGHIJKLMNOPQRSTUVWX; '
                    .'DB_PASSWORD=SuperSecret123 ; Authorization: Bearer eyJhbGciOiJIUzI1NiJ9AAAAAAAAAAAAAAAAAAAAAAAAAAAAA ; '
                    .'connection mysql://root:hunter2@db.internal/aziv ; AIzaSyD-1234567890abcdefghijklmnopqrstu',
                recommendedAction: 'The key rzp_live_ABCDEFGHIJKL was rejected.',
                adminAction: 'Re-enter the credential.',
            ),
        ];
    }

    public function test_the_report_explains_the_problem(): void
    {
        $text = $this->exporter()->toText($this->leakyResults());

        $this->assertStringContainsString('Provider connectivity', $text);
        $this->assertStringContainsString('RED', $text);
        $this->assertStringContainsString('Re-enter the credential.', $text);
        // It has to say plainly that it is safe to forward, or nobody will.
        $this->assertStringContainsString('safe to send to your hosting provider', $text);
    }

    public function test_not_one_credential_survives_the_export(): void
    {
        $text = $this->exporter()->toText($this->leakyResults());

        foreach ([
            'sk-live-ABCDEFGHIJKLMNOPQRSTUVWX',
            'SuperSecret123',
            'eyJhbGciOiJIUzI1NiJ9',
            'hunter2',
            'AIzaSyD-1234567890abcdefghijklmnopqrstu',
            'rzp_live_ABCDEFGHIJKL',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $text, $secret.' reached the exported report.');
        }
    }

    public function test_the_real_catalogue_produces_a_report_with_nothing_secret_in_it(): void
    {
        // Not a fixture: the actual checks, against this actual machine. A
        // scrubbing rule that only holds for invented input is not a rule.
        $results = app(CheckRegistry::class)->run();
        $text = $this->exporter()->toText($results);

        $this->assertNotSame('', trim($text));

        // Anything shaped like a key, whatever produced it.
        foreach ([
            '/\bsk-[A-Za-z0-9_\-]{16,}/',
            '/\brzp_(live|test)_[A-Za-z0-9]+/',
            '/\bAIza[0-9A-Za-z_\-]{20,}/',
            '/(PASSWORD|SECRET|TOKEN)\s*=\s*\S+/i',
            '/[a-z]+:\/\/[^\s:@\/]+:[^\s@\/]+@/i',
        ] as $pattern) {
            $this->assertSame(0, preg_match($pattern, $text),
                'The exported report matched '.$pattern);
        }
    }

    public function test_the_filename_says_what_it_is_and_names_nothing_else(): void
    {
        $name = $this->exporter()->filename();

        $this->assertStringStartsWith('aziv-health-', $name);
        $this->assertStringEndsWith('.txt', $name);
        // No hostname, no database name, no path.
        $this->assertDoesNotMatchRegularExpression('/[\/\\\\]/', $name);
    }

    public function test_problems_are_at_the_top_where_a_support_agent_reads(): void
    {
        $results = [
            CheckResult::pass('fine.one', 'Everything fine', Category::Php, Responsibility::Hosting, 'All good.'),
            new CheckResult(
                key: 'bad.one',
                title: 'Something broken',
                category: Category::Database,
                status: Status::Red,
                severity: Severity::Critical,
                responsibility: Responsibility::Hosting,
                technicalReason: 'It is broken.',
            ),
        ];

        $text = $this->exporter()->toText($results);

        $this->assertLessThan(
            strpos($text, 'Everything fine'),
            strpos($text, 'Something broken'),
            'The healthy rows were printed before the problem.',
        );
    }

    public function test_the_command_writes_the_report_to_a_file(): void
    {
        $path = sys_get_temp_dir().'/aziv-report-test.txt';
        @unlink($path);

        $this->artisan('aziv:diagnose', ['--export' => $path, '--automatic' => true]);

        $this->assertFileExists($path);
        $this->assertStringContainsString('Aziv AI — system health report', (string) file_get_contents($path));

        unlink($path);
    }
}
