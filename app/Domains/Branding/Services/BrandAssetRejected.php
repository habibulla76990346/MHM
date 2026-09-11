<?php

namespace App\Domains\Branding\Services;

use RuntimeException;

/**
 * Why a brand image could not be published, in words an administrator can act
 * on. Owner Addendum G: never "Something went wrong."
 */
class BrandAssetRejected extends RuntimeException
{
    public static function unknownPurpose(string $purpose): self
    {
        return new self("There is no brand asset called \"{$purpose}\".");
    }

    public static function unsupportedFormat(string $mime): self
    {
        return new self(
            "A {$mime} file cannot be used as branding. Upload a PNG, JPEG, WebP or ICO. "
            .'SVG is not accepted: it can contain scripts, and it would be served from your own domain.'
        );
    }

    public static function quarantined(): self
    {
        return new self('That file was quarantined by the security scan and cannot be published.');
    }

    public static function missing(): self
    {
        return new self('The uploaded file is no longer in storage. Upload it again.');
    }

    public static function notAnImage(): self
    {
        return new self('That file is not a real image, whatever its name says. Upload a PNG, JPEG, WebP or ICO.');
    }

    public static function notWritable(): self
    {
        return new self(
            'Aziv AI could not write to the public/brand folder, so your branding was not changed. '
            .'Ask your hosting provider to make public/brand writable by the web server (permission 755). '
            .'Until then the built-in Aziv AI branding is used.'
        );
    }
}
