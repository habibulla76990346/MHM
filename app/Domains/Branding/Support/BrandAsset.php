<?php

namespace App\Domains\Branding\Support;

/**
 * The brand assets an administrator can replace (blueprint §4, owner decision
 * D-09: the shipped artwork is the initial branding and stays replaceable).
 *
 * Each entry names the setting that holds the published path, the file that
 * ships with the product, and what the asset is actually for — because an
 * owner uploading a logo needs to know which one appears on a dark background.
 */
class BrandAsset
{
    /**
     * @return array<string, array{label: string, help: string, default: string, width: int, height: int}>
     */
    public static function all(): array
    {
        return [
            'logo_light' => [
                'label' => 'Logo for light backgrounds',
                'help' => 'Shown on white and light themes. Wide format.',
                'default' => 'brand/logo-light-bg.png',
                'width' => 960, 'height' => 320,
            ],
            'logo_dark' => [
                'label' => 'Logo for dark backgrounds',
                'help' => 'Shown on dark themes. The theme picks between the two automatically.',
                'default' => 'brand/logo-dark-bg.png',
                'width' => 960, 'height' => 320,
            ],
            'mark' => [
                'label' => 'Compact mark',
                'help' => 'The square symbol without wording, used where space is tight.',
                'default' => 'brand/mark-compact-dark.png',
                'width' => 512, 'height' => 512,
            ],
            'favicon' => [
                'label' => 'Favicon',
                'help' => 'The small icon in a browser tab. Square, at least 48x48.',
                'default' => 'brand/favicon-48.png',
                'width' => 48, 'height' => 48,
            ],
            'app_icon' => [
                'label' => 'App icon',
                'help' => 'Used when someone installs Aziv AI to a phone home screen. Square, at least 512x512.',
                'default' => 'brand/app-icon-512.png',
                'width' => 512, 'height' => 512,
            ],
        ];
    }

    /** @return array<int, string> */
    public static function purposes(): array
    {
        return array_keys(self::all());
    }

    public static function settingKey(string $purpose): string
    {
        return 'branding.asset_'.$purpose;
    }

    /** The `files.purpose` value for an upload destined to become this asset. */
    public static function filePurpose(string $purpose): string
    {
        return 'brand_'.$purpose;
    }
}
