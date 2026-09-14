<?php

namespace App\Support\Install;

use RuntimeException;

/**
 * Writing `.env` from the browser installer (Owner Addendum E §4).
 *
 * A LINE EDITOR, NOT A GENERATOR. It reads the file that is there and replaces
 * the values it was given, keeping every comment, every blank line and every
 * key it does not know about. A writer that regenerated the file would silently
 * throw away the documentation that makes `.env.example` worth shipping, and
 * would drop anything a host had added for its own reasons.
 *
 * QUOTING IS NOT COSMETIC. A database password containing a space, a `#` or a
 * `$` breaks dotenv parsing in three different ways, and the symptom is
 * "connection refused" with a perfectly correct password. Values are quoted
 * whenever they contain anything that is not plainly safe.
 *
 * IT NEVER LOGS AND NEVER ECHOES. The values passing through here are the
 * database password and the application key.
 */
class EnvWriter
{
    public function __construct(private readonly string $path) {}

    /** @param array<string, string|int|bool|null> $values */
    public function write(array $values): void
    {
        $contents = is_file($this->path) ? (string) file_get_contents($this->path) : '';

        foreach ($values as $key => $value) {
            $contents = $this->set($contents, $key, $this->format($value));
        }

        if (@file_put_contents($this->path, $contents) === false) {
            throw new RuntimeException('The .env file could not be written. Check the permissions on the application folder.');
        }

        // Owner-only: this file is every credential on the server, and a
        // default umask on shared hosting is routinely group-readable.
        @chmod($this->path, 0600);
    }

    /** Copy a template into place, once, without overwriting a real file. */
    public function seedFrom(string $template): void
    {
        if (is_file($this->path) || ! is_file($template)) {
            return;
        }

        @copy($template, $this->path);
        @chmod($this->path, 0600);
    }

    private function set(string $contents, string $key, string $value): string
    {
        $line = $key.'='.$value;
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            return (string) preg_replace($pattern, str_replace('\\', '\\\\', $line), $contents, 1);
        }

        return rtrim($contents, "\n")."\n".$line."\n";
    }

    private function format(string|int|bool|null $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        // Anything outside this set changes how dotenv reads the line.
        if (preg_match('/^[A-Za-z0-9_.\-\/:@]+$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
