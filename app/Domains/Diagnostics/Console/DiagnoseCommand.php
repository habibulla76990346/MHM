<?php

namespace App\Domains\Diagnostics\Console;

use App\Domains\Diagnostics\CheckRegistry;
use App\Domains\Diagnostics\Models\DiagnosticRun;
use App\Domains\Diagnostics\Services\DiagnosticRunner;
use App\Domains\Diagnostics\Services\ReportExporter;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\DeploymentMode;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;
use Illuminate\Console\Command;

class DiagnoseCommand extends Command
{
    protected $signature = 'aziv:diagnose
                            {--automatic : Run only checks that are free and side-effect free}
                            {--json : Machine-readable output}
                            {--export= : Write a support-safe text report to this path}
                            {--record : Save the run, update the baselines, and announce anything that changed}';

    protected $description = 'Report this server\'s capabilities and any problems, with what to do about each';

    public function handle(CheckRegistry $registry, DiagnosticRunner $runner, ReportExporter $exporter): int
    {
        $run = null;

        if ($this->option('record')) {
            // Recorded runs go through the runner so the history, the
            // baselines and the transition notice all stay in step. There is
            // one place that writes them.
            $outcome = $runner->run(
                trigger: $this->option('automatic') ? DiagnosticRun::SCHEDULED : DiagnosticRun::MANUAL,
            );
            $results = $outcome['results'];
            $run = $outcome['run'];
        } else {
            $results = $registry->run(automaticOnly: (bool) $this->option('automatic'));
        }

        if ($path = $this->option('export')) {
            // Written rather than printed: the point is a file to attach to a
            // support ticket, and a terminal scrollback is not that.
            file_put_contents($path, $exporter->toText($results, $run));
            $this->components->info('Support-safe report written to '.$path);
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'deployment_mode' => DeploymentMode::resolve()->value,
                'results' => array_map(fn (CheckResult $r) => $r->toArray(), $results),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode($results);
        }

        $this->newLine();
        $this->line('  <options=bold>Aziv AI — System Health</>');
        $this->line('  <fg=gray>'.DeploymentMode::resolve()->label().'</>');
        $this->newLine();

        foreach ($results as $r) {
            [$icon, $colour] = match ($r->status) {
                Status::Green => ['●', 'green'],
                Status::Yellow => ['●', 'yellow'],
                Status::Red => ['●', 'red'],
                Status::Grey => ['○', 'gray'],
            };

            $sev = $r->severity === Severity::Informational ? '' : ' <fg=gray>['.$r->severity->label().']</>';
            $this->line(sprintf('  <fg=%s>%s</> %s%s', $colour, $icon, $r->title, $sev));
            $this->line('     <fg=gray>'.$r->category->label().' · '.$r->responsibility->label().'</>');

            if ($r->technicalReason !== '') {
                $this->line('     '.$r->technicalReason);
            }
            if ($r->recommendedAction !== '') {
                $this->line('     <fg=yellow>→ '.$r->recommendedAction.'</>');
            }
            if ($r->adminAction !== '') {
                $this->line('     <options=bold>Do this:</> '.$r->adminAction);
            }
            if ($r->requiresHostingSupport && $r->supportWording !== '') {
                $this->line('     <options=bold>Send your host:</> <fg=cyan>"'.$r->supportWording.'"</>');
            }
            $this->newLine();
        }

        $counts = [
            'red' => count(array_filter($results, fn ($r) => $r->status === Status::Red)),
            'yellow' => count(array_filter($results, fn ($r) => $r->status === Status::Yellow)),
            'grey' => count(array_filter($results, fn ($r) => $r->status === Status::Grey)),
            'green' => count(array_filter($results, fn ($r) => $r->status === Status::Green)),
        ];

        $this->line(sprintf(
            '  <fg=green>%d working</>  <fg=yellow>%d limited</>  <fg=red>%d problems</>  <fg=gray>%d not applicable</>',
            $counts['green'], $counts['yellow'], $counts['red'], $counts['grey']
        ));
        $this->newLine();

        return $this->exitCode($results);
    }

    /** Non-zero when anything Critical is RED, so the installer and CI can gate on it. */
    private function exitCode(array $results): int
    {
        foreach ($results as $r) {
            if ($r->status === Status::Red && $r->severity === Severity::Critical) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
