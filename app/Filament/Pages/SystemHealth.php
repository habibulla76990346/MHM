<?php

namespace App\Filament\Pages;

use App\Domains\Diagnostics\CheckRegistry;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\DeploymentMode;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;

/**
 * ADMIN → System Health / Diagnostics (Owner Addendum G).
 *
 * Answers three questions for someone who is not a developer: what exactly is
 * wrong, whose problem it is, and what to do about it.
 *
 * Results are CACHED and the screen shows their age. Two reasons: diagnostics
 * must never run during an ordinary page load (some probe the network), and a
 * Filament page is a Livewire component that re-renders on every interaction —
 * recomputing would re-probe external services on each click.
 */
class SystemHealth extends Page
{
    public const CACHE_KEY = 'aziv:diagnostics:last_run';

    private const CACHE_MINUTES = 10;

    protected static ?string $navigationLabel = 'System Health';

    protected static ?string $title = 'System Health';

    protected static ?string $slug = 'system-health';

    protected string $view = 'filament.pages.system-health';

    /**
     * Livewire state must be serialisable, so only this flag is public —
     * the typed CheckResult objects live behind a computed property.
     */
    public bool $freshRun = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('diagnostics.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run')
                ->label('Run full diagnostics')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn () => auth()->user()?->can('diagnostics.run'))
                ->action(function () {
                    // A full run may include checks that cost money or send
                    // real email, so it is always explicit — never scheduled.
                    Cache::forget(self::CACHE_KEY);
                    $this->freshRun = true;
                    unset($this->results);

                    $problems = $this->problemCount();

                    Notification::make()
                        ->title('Diagnostics complete')
                        ->body($problems === 0 ? 'No problems found.' : $problems.' problem(s) need attention.')
                        ->status($problems === 0 ? 'success' : 'warning')
                        ->send();
                }),
        ];
    }

    /**
     * @return array{results: array<int, CheckResult>, ran_at: string}
     */
    #[Computed]
    public function run(): array
    {
        // What goes INTO the cache is plain arrays, never CheckResult objects.
        // A cached object is a serialised object, and a serialised object
        // outlives the class definition that wrote it: on any store that
        // cannot resolve the class at unserialize time it returns as
        // __PHP_Incomplete_Class and this page dies with a fatal type error —
        // the one page an administrator opens when something is already wrong.
        // The test suite runs the array driver, which never serialises, so
        // this is invisible to PHPUnit and shows up only on a real server.
        if ($this->freshRun) {
            $payload = [
                'results' => array_map(
                    fn (CheckResult $r) => $r->toArray(),
                    app(CheckRegistry::class)->run(automaticOnly: false),
                ),
                'ran_at' => now()->toIso8601String(),
            ];

            Cache::put(self::CACHE_KEY, $payload, now()->addMinutes(self::CACHE_MINUTES));

            return $payload;
        }

        return Cache::remember(self::CACHE_KEY, now()->addMinutes(self::CACHE_MINUTES), function () {
            return [
                // Only the free, side-effect-free subset runs unattended.
                'results' => array_map(
                    fn (CheckResult $r) => $r->toArray(),
                    app(CheckRegistry::class)->run(automaticOnly: true),
                ),
                'ran_at' => now()->toIso8601String(),
            ];
        });
    }

    /** @return array<int, CheckResult> */
    #[Computed]
    public function results(): array
    {
        return array_map(CheckResult::fromArray(...), $this->run()['results']);
    }

    public function ranAt(): string
    {
        return \Illuminate\Support\Carbon::parse($this->run()['ran_at'])->diffForHumans();
    }

    public function deploymentMode(): string
    {
        return DeploymentMode::resolve()->label();
    }

    public function problemCount(): int
    {
        return count(array_filter($this->results(), fn (CheckResult $r) => $r->status === Status::Red));
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $count = fn (Status $s) => count(array_filter($this->results(), fn (CheckResult $r) => $r->status === $s));

        return [
            'green' => $count(Status::Green),
            'yellow' => $count(Status::Yellow),
            'red' => $count(Status::Red),
            'grey' => $count(Status::Grey),
        ];
    }

    /** Findings grouped by category; within each, most severe first. */
    public function grouped(): array
    {
        $grouped = [];

        foreach ($this->results() as $result) {
            $grouped[$result->category->label()][] = $result;
        }

        return $grouped;
    }

    public function severityLabel(Severity $severity): string
    {
        return $severity->label();
    }
}
