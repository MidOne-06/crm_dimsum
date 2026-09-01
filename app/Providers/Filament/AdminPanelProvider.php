<?php

namespace App\Providers\Filament;

use App\Filament\Pages\EditProfile;
use App\Filament\Pages\Login;
use App\Filament\Pages\Stock\NuevaSalidaStock;
use App\Http\Middleware\RedirectTerminalToNewStockExit;
use App\Models\BrandingSetting;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
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
            ->login(Login::class)
            ->profile(EditProfile::class, isSimple: false)
            ->homeUrl(function (): ?string {
                $user = auth()->user();

                return $user?->roles()->where('slug', 'terminal')->exists()
                    ? NuevaSalidaStock::getUrl()
                    : null;
            })
            ->brandName(fn (): string => BrandingSetting::current()->brand_name)
            ->brandLogo(fn (): string => BrandingSetting::current()->logoUrl())
            ->brandLogoHeight(fn (): string => BrandingSetting::current()->logoHeight())
            ->favicon(fn (): string => BrandingSetting::current()->faviconUrl())
            ->sidebarCollapsibleOnDesktop()
            // El modo claro, oscuro y "según el sistema" lo controla Filament.
            ->darkMode()
            ->themeSwitcher()
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->databaseNotifications()
            ->databaseNotificationsPolling('10s')
            ->colors(fn (): array => [
                'primary' => Color::hex(BrandingSetting::current()->primaryColor()),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                RedirectTerminalToNewStockExit::class,
            ]);
    }
}
