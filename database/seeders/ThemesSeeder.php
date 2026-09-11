<?php

namespace Database\Seeders;

use App\Domains\Theming\Models\Theme;
use App\Domains\Theming\Support\BuiltInThemes;
use App\Domains\Theming\Support\ThemePalette;
use App\Domains\Theming\Support\TokenCatalogue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the eight built-in themes (blueprint §5).
 *
 * Each theme is stored as its full token set for both scopes and both modes —
 * 69 tokens x 2 scopes x 2 modes = 276 rows per theme — rather than as a
 * palette that is expanded on every request. Two reasons:
 *
 *  1. A stored token is EDITABLE. If derivation stayed at render time, an
 *     administrator could only ever change the seven palette colours, and D-07
 *     says they get the complete design system.
 *  2. Rendering must not depend on the derivation maths being identical
 *     between versions. What was published is what is served.
 *
 * Re-running is safe: tokens an administrator has changed are left alone, so
 * `db:seed` after an upgrade adds newly introduced tokens without reverting
 * anyone's work.
 */
class ThemesSeeder extends Seeder
{
    public function run(): void
    {
        $catalogue = TokenCatalogue::tokens();

        foreach (BuiltInThemes::all() as $slug => $definition) {
            $theme = Theme::firstOrNew(['slug' => $slug]);

            $theme->fill([
                'uuid' => $theme->uuid ?? (string) Str::uuid(),
                'name' => $definition['name'],
                'description' => $definition['description'],
                'is_builtin' => true,
                'supports_dark' => $definition['supports_dark'],
            ]);

            // The first theme to exist becomes the active one. An existing
            // active theme is never displaced by seeding — that would change a
            // live site's appearance during a routine upgrade.
            if (! $theme->exists && $slug === 'light' && ! Theme::where('is_active', true)->exists()) {
                $theme->is_active = true;
                $theme->published_at = now();
            }

            $theme->save();

            $rows = [];
            $now = now();

            foreach ([TokenCatalogue::SCOPE_CUSTOMER, TokenCatalogue::SCOPE_ADMIN] as $scope) {
                foreach ([TokenCatalogue::MODE_LIGHT, TokenCatalogue::MODE_DARK] as $mode) {
                    $tokens = ThemePalette::derive($definition[$mode], $scope, $mode);

                    foreach ($tokens as $key => $value) {
                        $rows[] = [
                            'theme_id' => $theme->getKey(),
                            'scope' => $scope,
                            'mode' => $mode,
                            'token_group' => $catalogue[$key]['group'] ?? 'brand',
                            'token_key' => $key,
                            'token_value' => $value,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }

            // insertOrIgnore, not upsert: the unique key makes an existing row
            // win, which is precisely the "never revert an administrator's
            // edit" behaviour this seeder promises.
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('theme_tokens')->insertOrIgnore($chunk);
            }
        }
    }
}
