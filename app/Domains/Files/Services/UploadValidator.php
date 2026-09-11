<?php

namespace App\Domains\Files\Services;

use App\Domains\Files\Exceptions\UploadRejected;
use Illuminate\Http\UploadedFile;

/**
 * The nine Phase 1 upload controls (Owner Addendum H / docs/18-upload-security.md).
 *
 * Every upload in the platform passes through here — chat attachment, avatar,
 * brand logo, knowledge-base document. There is deliberately no second upload
 * path, because a second path is how these controls get bypassed in practice.
 */
class UploadValidator
{
    /**
     * US-7: never accepted, whatever the allowlist says. Executable and
     * script types, plus server config files.
     */
    private const ALWAYS_FORBIDDEN = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phps', 'phar',
        'exe', 'dll', 'so', 'sh', 'bash', 'bat', 'cmd', 'com', 'msi',
        'jsp', 'jspx', 'asp', 'aspx', 'cgi', 'pl', 'py', 'rb',
        'htaccess', 'htpasswd', 'ini', 'conf',
    ];

    /**
     * MIME types that must never be stored regardless of extension — a PHP
     * script renamed `.jpg` is caught here even if `jpg` is allowed.
     */
    private const FORBIDDEN_MIME_PATTERNS = [
        'php', 'x-httpd', 'x-executable', 'x-sharedlib', 'x-dosexec',
        'x-msdownload', 'x-shellscript', 'x-perl', 'x-python', 'java-archive',
    ];

    /** Detected MIME must be consistent with the claimed extension. */
    private const EXTENSION_MIME_MAP = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];

    /**
     * @throws UploadRejected
     */
    public function validate(UploadedFile $file): ValidatedUpload
    {
        $this->assertUploadSucceeded($file);

        $extension = $this->resolveExtension($file);
        $detectedMime = $this->detectMime($file);

        $this->assertExtensionAllowed($extension, $file);
        $this->assertMimeAllowed($detectedMime);
        $this->assertExtensionMatchesContent($extension, $detectedMime);
        $this->assertWithinSizeLimit($file);

        return new ValidatedUpload(
            file: $file,
            extension: $extension,
            detectedMime: $detectedMime,
            declaredMime: $file->getClientMimeType(),
            // US-5: the client filename is display text and nothing else. The
            // stored name is generated, so no user input reaches a path.
            originalName: $this->sanitiseDisplayName($file->getClientOriginalName()),
            sizeBytes: $file->getSize() ?: 0,
        );
    }

    private function assertUploadSucceeded(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            // A file larger than the server's post_max_size arrives here as an
            // invalid upload with a misleading message, so name the likely
            // cause rather than reporting a generic failure.
            throw new UploadRejected(
                __('That file could not be uploaded. It may be larger than this server accepts (:limit).', [
                    'limit' => ini_get('upload_max_filesize'),
                ]),
            );
        }
    }

    /** US-3: reject multi-extension filenames rather than normalising them. */
    private function resolveExtension(UploadedFile $file): string
    {
        $name = $file->getClientOriginalName();
        $parts = array_slice(explode('.', strtolower($name)), 1);

        if ($parts === []) {
            throw new UploadRejected(__('Files must have a file type extension.'));
        }

        foreach ($parts as $part) {
            $part = preg_replace('/[^a-z0-9]/', '', $part) ?? '';
            if ($part !== '' && in_array($part, self::ALWAYS_FORBIDDEN, true)) {
                // Catches invoice.php.jpg and photo.jpg.php alike. Normalising
                // to "the last extension" is exactly where bypasses live.
                throw new UploadRejected(__('That file type is not allowed.'));
            }
        }

        return preg_replace('/[^a-z0-9]/', '', end($parts)) ?: '';
    }

    /** US-2: the real type comes from the bytes, never from the request. */
    private function detectMime(UploadedFile $file): string
    {
        $detected = $file->getMimeType();      // finfo-based

        return $detected ?: 'application/octet-stream';
    }

    private function assertExtensionAllowed(string $extension, UploadedFile $file): void
    {
        if ($extension === '' || in_array($extension, self::ALWAYS_FORBIDDEN, true)) {
            throw new UploadRejected(__('That file type is not allowed.'));
        }

        // US-1: an allowlist. A denylist fails the moment someone finds a type
        // nobody thought to block.
        $allowed = array_map('strtolower', (array) settings('uploads.allowed_extensions'));

        if (! in_array($extension, $allowed, true)) {
            throw new UploadRejected(__('Only these file types are accepted: :types', [
                'types' => implode(', ', $allowed),
            ]));
        }
    }

    private function assertMimeAllowed(string $mime): void
    {
        foreach (self::FORBIDDEN_MIME_PATTERNS as $pattern) {
            if (str_contains(strtolower($mime), $pattern)) {
                throw new UploadRejected(__('That file contains executable content and was rejected.'));
            }
        }

        // SVG is treated as active content: it can carry JavaScript. Until the
        // sanitiser lands in Phase 8 it is simply not accepted.
        if (str_contains($mime, 'svg')) {
            throw new UploadRejected(__('SVG files are not accepted.'));
        }
    }

    private function assertExtensionMatchesContent(string $extension, string $mime): void
    {
        $expected = self::EXTENSION_MIME_MAP[$extension] ?? null;

        if ($expected === null) {
            return;   // allowlisted but unmapped: the MIME checks above still apply
        }

        foreach ($expected as $candidate) {
            if (str_starts_with($mime, $candidate)) {
                return;
            }
        }

        throw new UploadRejected(__('That file\'s contents do not match its file type.'));
    }

    private function assertWithinSizeLimit(UploadedFile $file): void
    {
        $configured = (int) settings('uploads.max_size_kb') * 1024;
        $serverLimit = $this->serverUploadLimitBytes();

        // Whichever is lower actually applies. A configured cap above the
        // server's own produces a confusing silent failure, so respect both.
        $limit = $serverLimit > 0 ? min($configured, $serverLimit) : $configured;

        if (($file->getSize() ?: 0) > $limit) {
            throw new UploadRejected(__('That file is too large. The maximum is :size MB.', [
                'size' => round($limit / 1048576, 1),
            ]));
        }
    }

    private function serverUploadLimitBytes(): int
    {
        $toBytes = static function (string|false $value): int {
            if ($value === false || $value === '' || $value === '-1') {
                return 0;
            }
            $unit = strtolower(substr(trim($value), -1));
            $num = (int) $value;

            return match ($unit) {
                'g' => $num * 1024 ** 3,
                'm' => $num * 1024 ** 2,
                'k' => $num * 1024,
                default => $num,
            };
        };

        $upload = $toBytes(ini_get('upload_max_filesize'));
        $post = $toBytes(ini_get('post_max_size'));
        $limits = array_filter([$upload, $post]);

        return $limits === [] ? 0 : min($limits);
    }

    /** Display-only. Never used to build a path. */
    private function sanitiseDisplayName(string $name): string
    {
        $name = str_replace(["\0", "\r", "\n", '/', '\\'], '', $name);
        $name = preg_replace('/[[:cntrl:]]/', '', $name) ?? $name;

        return mb_substr(trim($name), 0, 180) ?: 'file';
    }
}
