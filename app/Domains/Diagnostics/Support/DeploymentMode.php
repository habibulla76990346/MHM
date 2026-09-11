<?php

namespace App\Domains\Diagnostics\Support;

/**
 * Which environment Aziv AI believes it is running in.
 *
 * The mode never changes what the application CAN do (Owner Addendum B,
 * requirement 6 — no feature is removed because of shared-hosting limits).
 * It changes how a finding is GRADED, so expected shared-hosting limitations
 * read GREY or YELLOW instead of alarming RED.
 */
enum DeploymentMode: string
{
    case Shared = 'shared';
    case Cloud = 'cloud';

    public static function resolve(): self
    {
        $configured = config('aziv.deployment_mode', 'auto');

        if ($configured === 'shared' || $configured === 'cloud') {
            return self::from($configured);
        }

        return self::detect();
    }

    /**
     * Detection is a weight of evidence, not a single test — every signal
     * here is individually fallible.
     */
    public static function detect(): self
    {
        $sharedSignals = 0;
        $cloudSignals = 0;

        // cPanel leaves unmistakable traces.
        foreach (['/usr/local/cpanel', '/usr/local/directadmin', '/usr/local/plesk'] as $path) {
            if (@is_dir($path)) {
                $sharedSignals += 2;
            }
        }

        // A home directory shaped like /home/<user>/public_html.
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot && str_contains($docRoot, 'public_html')) {
            $sharedSignals += 2;
        }

        // Functions shared hosts routinely disable.
        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        if (count(array_intersect($disabled, ['exec', 'shell_exec', 'proc_open', 'symlink'])) >= 2) {
            $sharedSignals++;
        }

        // Redis reachable is a strong cloud signal; rarely offered on shared.
        if (extension_loaded('redis') && config('cache.default') === 'redis') {
            $cloudSignals += 2;
        }

        // Containerised or orchestrated environments.
        if (@is_file('/.dockerenv') || getenv('KUBERNETES_SERVICE_HOST')) {
            $cloudSignals += 2;
        }

        return $sharedSignals > $cloudSignals ? self::Shared : self::Cloud;
    }

    public function label(): string
    {
        return match ($this) {
            self::Shared => 'Shared hosting / cPanel',
            self::Cloud => 'Cloud / VPS',
        };
    }
}
