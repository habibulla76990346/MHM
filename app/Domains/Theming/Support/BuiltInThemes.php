<?php

namespace App\Domains\Theming\Support;

/**
 * The eight built-in themes (blueprint §5).
 *
 * Each defines only its PALETTE — the handful of colours that make it
 * distinct. Every remaining token is derived from those by ThemePalette, so a
 * theme is ~12 decisions rather than ~95, and adding a ninth theme stays a
 * small job.
 *
 * Built-ins cannot be deleted, only duplicated, so there is always a
 * known-good theme to return to.
 */
class BuiltInThemes
{
    /**
     * @return array<string, array{name: string, description: string, supports_dark: bool, light: array<string,string>, dark: array<string,string>}>
     */
    public static function all(): array
    {
        return [
            'light' => [
                'name' => 'Light',
                'description' => 'Clean neutral default.',
                'supports_dark' => true,
                'light' => [
                    'primary' => '#1f2937', 'accent' => '#4f46e5',
                    'background' => '#f8fafc', 'surface' => '#ffffff',
                    'text' => '#0f172a', 'muted' => '#64748b', 'border' => '#e2e8f0',
                ],
                'dark' => [
                    'primary' => '#e2e8f0', 'accent' => '#818cf8',
                    'background' => '#0b1120', 'surface' => '#111827',
                    'text' => '#e8edf5', 'muted' => '#94a3b8', 'border' => '#1e293b',
                ],
            ],

            'dark' => [
                'name' => 'Dark',
                'description' => 'Standard dark mode, comfortable for long sessions.',
                'supports_dark' => true,
                'light' => [
                    'primary' => '#334155', 'accent' => '#6366f1',
                    'background' => '#f1f5f9', 'surface' => '#ffffff',
                    'text' => '#0f172a', 'muted' => '#64748b', 'border' => '#dbe3ec',
                ],
                'dark' => [
                    'primary' => '#cbd5e1', 'accent' => '#818cf8',
                    'background' => '#0f172a', 'surface' => '#1e293b',
                    'text' => '#f1f5f9', 'muted' => '#94a3b8', 'border' => '#334155',
                ],
            ],

            'midnight' => [
                'name' => 'Midnight',
                'description' => 'Deep blue-black with high contrast.',
                'supports_dark' => true,
                'light' => [
                    'primary' => '#1e1b4b', 'accent' => '#6366f1',
                    'background' => '#f5f5ff', 'surface' => '#ffffff',
                    'text' => '#1e1b4b', 'muted' => '#6b7280', 'border' => '#e0e0f5',
                ],
                'dark' => [
                    'primary' => '#c7d2fe', 'accent' => '#a5b4fc',
                    'background' => '#050816', 'surface' => '#0d1229',
                    'text' => '#eef2ff', 'muted' => '#9aa4c8', 'border' => '#1c2450',
                ],
            ],

            'professional' => [
                'name' => 'Professional',
                'description' => 'Restrained corporate palette.',
                'supports_dark' => true,
                'light' => [
                    'primary' => '#0f4c81', 'accent' => '#0284c7',
                    'background' => '#f7f9fb', 'surface' => '#ffffff',
                    'text' => '#152238', 'muted' => '#5b6b82', 'border' => '#dde5ee',
                ],
                'dark' => [
                    'primary' => '#7cc0f0', 'accent' => '#38bdf8',
                    'background' => '#0a1220', 'surface' => '#111d30',
                    'text' => '#e6eef8', 'muted' => '#8fa3bd', 'border' => '#1d2c44',
                ],
            ],

            'minimal' => [
                'name' => 'Minimal',
                'description' => 'Near-monochrome with generous whitespace.',
                'supports_dark' => true,
                'light' => [
                    'primary' => '#171717', 'accent' => '#525252',
                    'background' => '#fafafa', 'surface' => '#ffffff',
                    'text' => '#171717', 'muted' => '#737373', 'border' => '#e5e5e5',
                ],
                'dark' => [
                    'primary' => '#fafafa', 'accent' => '#a3a3a3',
                    'background' => '#0a0a0a', 'surface' => '#171717',
                    'text' => '#fafafa', 'muted' => '#a3a3a3', 'border' => '#2e2e2e',
                ],
            ],

            'glass' => [
                'name' => 'Glass',
                'description' => 'Translucent surfaces with layered depth.',
                'supports_dark' => true,
                'light' => [
                    'primary' => '#0369a1', 'accent' => '#06b6d4',
                    'background' => '#eef4f8', 'surface' => '#ffffff',
                    'text' => '#0b2436', 'muted' => '#5c7a8e', 'border' => '#d3e2ec',
                ],
                'dark' => [
                    'primary' => '#67e8f9', 'accent' => '#22d3ee',
                    'background' => '#071620', 'surface' => '#0e2330',
                    'text' => '#e4f4fb', 'muted' => '#8db2c4', 'border' => '#173444',
                ],
            ],

            'ocean' => [
                'name' => 'Ocean',
                'description' => 'Blue-teal palette.',
                'supports_dark' => true,
                'light' => [
                    'primary' => '#0d7490', 'accent' => '#14b8a6',
                    'background' => '#f2fbfc', 'surface' => '#ffffff',
                    'text' => '#0b2f38', 'muted' => '#5a808a', 'border' => '#d5eaef',
                ],
                'dark' => [
                    'primary' => '#5eead4', 'accent' => '#2dd4bf',
                    'background' => '#04191e', 'surface' => '#0a2a31',
                    'text' => '#e0f7fa', 'muted' => '#87b4bd', 'border' => '#12414a',
                ],
            ],

            'custom' => [
                'name' => 'Custom',
                'description' => 'An empty starting point for your own brand.',
                'supports_dark' => true,
                'light' => [
                    'primary' => '#1f2937', 'accent' => '#6366f1',
                    'background' => '#ffffff', 'surface' => '#ffffff',
                    'text' => '#111827', 'muted' => '#6b7280', 'border' => '#e5e7eb',
                ],
                'dark' => [
                    'primary' => '#e5e7eb', 'accent' => '#818cf8',
                    'background' => '#0b0f18', 'surface' => '#141a26',
                    'text' => '#f3f4f6', 'muted' => '#9ca3af', 'border' => '#242c3b',
                ],
            ],
        ];
    }
}
