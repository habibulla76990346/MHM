import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// NOTE: the Laravel skeleton ships a `bunny()` font plugin that downloads a
// webfont from fonts.bunny.net at BUILD TIME. That is removed deliberately:
//
//  1. It makes the build depend on outbound network access, so it fails in
//     CI, in an offline build, and behind a restrictive proxy.
//  2. Owner Addendum E requires assets to be compiled in CI and shipped
//     pre-built, so the build must be deterministic and self-contained.
//
// Typography is a design token (`--font-sans`) and becomes admin-configurable
// in Phase 2. Any self-hosted webfont added later belongs in resources/fonts
// and is bundled, not fetched.

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/filament/admin/theme.css'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    // NOTE: do not set `build.manifest` here. laravel-vite-plugin already
    // emits public/build/manifest.json (where Laravel looks) and hashed asset
    // filenames — the prerequisite for release packaging and for any future
    // service worker cache strategy. Setting it explicitly moves the manifest
    // to .vite/manifest.json and breaks @vite() at runtime.
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
