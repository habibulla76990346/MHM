<?php

namespace App\Http\Controllers\Branding;

use App\Domains\Branding\Services\BrandingService;
use App\Domains\Theming\Services\ThemeService;
use App\Domains\Theming\Support\TokenCatalogue;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The PWA manifest (owner decision D-10: installability in Phase 2).
 *
 * Generated rather than shipped as a static file, because every value in it —
 * the name, the icons, the colour of the system chrome — is administrator
 * controlled. A static manifest.webmanifest would show the previous owner's
 * branding on the home screen of anyone who installed the app, and would need
 * rewriting on disk every time branding changed.
 *
 * Owner Addendum A: "PWA-ready ... Do not turn this into a native mobile
 * application at this stage." This is the whole of that scope — installable,
 * with no service worker until Phase 9.
 */
class ManifestController extends Controller
{
    public function __invoke(BrandingService $branding, ThemeService $themes): JsonResponse
    {
        $icon = $branding->path('app_icon');
        $mime = str_ends_with($icon, '.png') ? 'image/png' : 'image/jpeg';

        return response()->json([
            'name' => settings('branding.app_name'),
            'short_name' => settings('branding.short_name'),
            'description' => settings('branding.tagline'),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            // Both come from the active theme, so an installed app re-skins
            // with the site instead of keeping the colours it was installed with.
            'background_color' => $themes->themeColor(TokenCatalogue::MODE_LIGHT),
            'theme_color' => $themes->themeColor(TokenCatalogue::MODE_LIGHT),
            'icons' => [
                [
                    'src' => asset($icon),
                    'sizes' => '512x512',
                    'type' => $mime,
                    'purpose' => 'any',
                ],
                [
                    // A maskable icon is padded so Android can crop it to
                    // whatever shape the launcher uses without clipping the
                    // mark. Only the shipped one is guaranteed to be padded,
                    // so a replaced icon is not offered as maskable.
                    'src' => asset($branding->isCustomised('app_icon')
                        ? $icon
                        : 'brand/app-icon-maskable-512.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => $branding->isCustomised('app_icon') ? 'any' : 'maskable',
                ],
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            // Short: branding changes must reach an installed app reasonably
            // soon, but this is fetched on every cold start.
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
