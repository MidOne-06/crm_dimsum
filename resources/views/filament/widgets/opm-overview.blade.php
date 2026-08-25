@php
    $user = filament()->auth()->user();
@endphp

<x-filament-widgets::widget class="opm-overview-widget">
    <section class="opm-dashboard-hero">
        <div>
            <p class="opm-page-intro__eyebrow">Centro de operaciones</p>
            <h2>Hola, {{ filament()->getUserName($user) }}</h2>
            <p>Gestiona las consultas de inventario y revisa los ajustes de stock desde un único lugar.</p>
        </div>

        <div class="opm-dashboard-hero__actions">
            @if (\App\Filament\Pages\Stock\StockConsolidado::canAccess())
                <a class="opm-dashboard-link" href="{{ \App\Filament\Pages\Stock\StockConsolidado::getUrl() }}">
                    <x-filament::icon icon="heroicon-o-archive-box" class="h-5 w-5" />
                    Ver consolidado
                </a>
            @endif
            @if (\App\Filament\Pages\Stock\StockActual::canAccess())
                <a class="opm-dashboard-link opm-dashboard-link--primary" href="{{ \App\Filament\Pages\Stock\StockActual::getUrl() }}">
                    <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-5 w-5" />
                    Consultar stock
                </a>
            @endif
        </div>
    </section>
</x-filament-widgets::widget>
