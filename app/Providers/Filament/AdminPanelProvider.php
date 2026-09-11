<?php

namespace App\Providers\Filament;

use App\Domains\Theming\Services\ThemeService;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            // The admin-scope token block, from the same component and the same
            // theme the customer application uses. This is what owner decision
            // D-07 asks for: branding propagates to the Admin Panel with no
            // template-specific override anywhere.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('components.theme.styles', ['scope' => 'admin'])->render(),
            )
            ->login()
            ->colors($this->themeColors())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * Filament styles its own components from 50-950 ramps, so the brand colour
     * has to be expanded before it can reach them (see ColorRamp).
     *
     * A panel provider is constructed on EVERY request, including during
     * `migrate` on an empty database and while the database is down. Reading
     * the theme here must therefore never be able to take the panel with it —
     * an administrator locked out of the Admin Panel cannot use the System
     * Health screen to find out why the database is unreachable.
     *
     * @return array<string, array<int, string>|string>
     */
    private function themeColors(): array
    {
        try {
            $colors = app(ThemeService::class)->filamentColors();
        } catch (\Throwable) {
            $colors = [];
        }

        return $colors === [] ? ['primary' => Color::Slate] : $colors;
    }
}
