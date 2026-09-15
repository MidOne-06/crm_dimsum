<?php

namespace App\Providers\Filament;

use App\Filament\GlobalSearch\CrmGlobalSearchProvider;
use App\Filament\Pages\EditProfile;
use App\Filament\Pages\Login;
use App\Filament\Pages\Stock\NuevaSalidaStock;
use App\Http\Middleware\RedirectTerminalToNewStockExit;
use App\Models\BrandingSetting;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Saade\FilamentFullCalendar\FilamentFullCalendarPlugin;

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
            // Pedido explícito del usuario (2026-09-15): "quiero que cubra
            // todo". El buscador global de Filament de fábrica SOLO revisa
            // Resources (20 en este proyecto -- catálogos de configuración),
            // nunca las Pages personalizadas donde vive casi toda la
            // operación real (guías, requerimientos, movimientos entre
            // almacenes). CrmGlobalSearchProvider delega en el proveedor de
            // Filament para los Resources y agrega esas 3 categorías más --
            // ver su docblock para por qué Kardex y Ventas quedaron fuera a
            // propósito.
            ->globalSearch(CrmGlobalSearchProvider::class)
            ->sidebarCollapsibleOnDesktop()
            // Orden real de operación, pedido explícito del usuario tras
            // auditar los 72 módulos del sistema (2026-09-15): "Stock
            // Inicial" mezclaba la carga de stock con la Directiva de
            // Transferencia completa y con parámetros de configuración de
            // su fórmula -- separado en 3 grupos reales. "Configuración" (solo
            // Apariencia) y "Sincronización" (solo Panel de sincronización)
            // eran grupos de un único ítem, fusionados dentro de "Seguridad".
            // Los 12 grupos quedan registrados acá (en vez de dejar que
            // Filament los infiera por orden de aparición) para controlar el
            // orden real Y para que todos arranquen colapsados -- ver
            // ->collapsed() de cada uno.
            ->navigationGroups([
                NavigationGroup::make('Stock Inicial')->collapsed(),
                NavigationGroup::make('Directiva de Transferencia')->collapsed(),
                NavigationGroup::make('Requerimientos de Stock')->collapsed(),
                NavigationGroup::make('Guías internas')->collapsed(),
                NavigationGroup::make('Movimientos entre almacenes')->collapsed(),
                NavigationGroup::make('Stock Actual')->collapsed(),
                NavigationGroup::make('Kardex')->collapsed(),
                NavigationGroup::make('Ventas')->collapsed(),
                NavigationGroup::make('Producción')->collapsed(),
                NavigationGroup::make('Entregas')->collapsed(),
                NavigationGroup::make('Configuración DT')->collapsed(),
                NavigationGroup::make('Seguridad')->collapsed(),
            ])
            // Pedido explícito del usuario (2026-09-15): el menú lateral y
            // cada categoría deben arrancar ocultos/colapsados en cada
            // carga real de la página (login, refrescar, pestaña nueva),
            // no solo en el primer render de Filament -- Filament no trae
            // una opción nativa para esto porque el estado abierto/cerrado
            // se persiste en el propio navegador (Alpine $persist, vía
            // localStorage), no en el servidor. Se fuerza acá con un
            // script mínimo que corre en <head>, antes de que Alpine
            // arranque, sobre las mismas claves que usa
            // vendor/filament/filament/resources/js/stores/sidebar.js.
            // Al navegar DENTRO del panel (Livewire wire:navigate) el
            // <head> no se vuelve a cargar, así que abrir el menú a mano
            // sigue funcionando con normalidad mientras se navega -- solo
            // una carga de página realmente nueva lo vuelve a ocultar.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render(<<<'BLADE'
                    <script>
                        (function () {
                            try {
                                localStorage.setItem('isOpen', 'false');
                                localStorage.setItem('isOpenDesktop', 'false');
                                localStorage.setItem('collapsedGroups', JSON.stringify([
                                    'Stock Inicial', 'Directiva de Transferencia', 'Requerimientos de Stock',
                                    'Guías internas', 'Movimientos entre almacenes', 'Stock Actual', 'Kardex',
                                    'Ventas', 'Producción', 'Entregas', 'Configuración DT', 'Seguridad',
                                ]));
                            } catch (e) {}
                        })();
                    </script>
                    BLADE),
            )
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
            ->plugins([
                FilamentFullCalendarPlugin::make()
                    ->timezone(config('app.timezone'))
                    ->locale('es')
                    ->config(['firstDay' => 1, 'height' => 'auto']),
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
