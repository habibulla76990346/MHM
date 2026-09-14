<?php

namespace App\Filament\Pages;

use App\Domains\Diagnostics\Models\DiagnosticRun;
use App\Domains\Diagnostics\Services\DiagnosticRunner;
use App\Domains\Diagnostics\Services\MaintenanceService;
use App\Domains\Diagnostics\Services\ReportExporter;
use App\Domains\Security\Services\ActivityLogger;
use App\Support\Installer;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADMIN → Maintenance (Owner Addendum E §4).
 *
 * THE SCREEN FOR SOMEBODY WITH NO TERMINAL. On hosting without SSH an update
 * that adds a migration cannot be finished, a stale config cache cannot be
 * rebuilt, and a queue nothing is draining cannot be nudged — and each of those
 * ends as "the site is broken and I cannot fix it".
 *
 * IT OFFERS FOUR NAMED TASKS AND TAKES NO COMMAND FROM THE CALLER. A page that
 * ran what it was given would be a shell with a web form in front of it, which
 * is the thing being avoided rather than provided.
 *
 * AUTHORISE, ACT, AUDIT — all three. `maintenance.run` is granted to a full
 * administrator and nobody else, because running migrations from a web page is
 * exactly the authority an attacker who got a support login would want next;
 * `maintenance.view` is enough to read the recent log and nothing more.
 */
class Maintenance extends Page
{
    protected static ?string $navigationLabel = 'Maintenance';

    protected static ?string $title = 'Maintenance';

    protected static ?string $slug = 'maintenance';

    protected static ?int $navigationSort = 92;

    protected string $view = 'filament.pages.maintenance';

    /** The result of the last task, so the page can show what happened. */
    public string $output = '';

    public string $lastTask = '';

    public bool $lastOk = true;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('maintenance.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function canRun(): bool
    {
        return auth()->user()?->can('maintenance.run') ?? false;
    }

    /** @return array<string, array<string, mixed>> */
    public function tasks(): array
    {
        return MaintenanceService::tasks();
    }

    #[Computed]
    public function log(): string
    {
        return app(MaintenanceService::class)->recentLog();
    }

    #[Computed]
    public function installState(): array
    {
        $installer = app(Installer::class);

        return [
            'locked' => $installer->isLocked(),
            'open' => $installer->isOpen(),
            'details' => $installer->details(),
        ];
    }

    public function runTask(string $task): void
    {
        if (! $this->canRun()) {
            Notification::make()->title('Not permitted')->danger()->send();

            return;
        }

        if (! MaintenanceService::exists($task)) {
            // Only the four the screen offers. Anything else arrived from
            // somewhere other than this page.
            Notification::make()->title('Unknown task')->danger()->send();

            return;
        }

        $result = app(MaintenanceService::class)->run($task);

        $this->lastTask = $task;
        $this->lastOk = $result['ok'];
        // Already scrubbed by the service: `migrate` quotes the connection it
        // failed on, and that string carries the database password.
        $this->output = $result['output'];

        app(ActivityLogger::class)->log(
            action: 'maintenance.'.$task,
            before: null,
            after: ['ok' => $result['ok']],
        );

        Notification::make()
            ->title($result['ok'] ? 'Done' : 'That did not work')
            ->{$result['ok'] ? 'success' : 'danger'}()
            ->send();

        unset($this->log);
    }

    /**
     * Download the support-safe health report.
     *
     * A FILE, not a page, because the point is something to attach to a
     * ticket. It contains no credential — proved adversarially by
     * `ReportExportTest` against the real catalogue, not a fixture.
     */
    public function downloadReport(): StreamedResponse
    {
        abort_unless(auth()->user()?->can('diagnostics.export') ?? false, 403);

        $exporter = app(ReportExporter::class);
        $outcome = app(DiagnosticRunner::class)->run(
            trigger: DiagnosticRun::MANUAL,
            actor: auth()->user(),
        );

        app(ActivityLogger::class)->log('diagnostics.exported', null, null, ['run' => $outcome['run']->uuid]);

        $text = $exporter->toText($outcome['results'], $outcome['run']);

        return response()->streamDownload(
            fn () => print ($text),
            $exporter->filename(),
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
