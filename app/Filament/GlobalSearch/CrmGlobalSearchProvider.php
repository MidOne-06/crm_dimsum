<?php

namespace App\Filament\GlobalSearch;

use App\Filament\Pages\RequerimientosStock\ListaRequerimientos;
use App\Filament\Pages\Stock\GuiasInternas;
use App\Filament\Pages\Stock\MovimientosAlmacenes;
use App\Models\GuiaInterna;
use App\Models\MovimientoAlmacenHistorico;
use App\Models\RequerimientoStockHistorico;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;

/**
 * El buscador global de Filament de fábrica SOLO revisa Resources (ver
 * Filament\Resources\Resource\Concerns\HasGlobalSearch) -- este proyecto
 * tiene 20, pero casi toda la operación real (guías internas,
 * requerimientos de stock, movimientos entre almacenes) vive en Pages
 * personalizadas con HasTable, que Filament nunca considera para el
 * buscador global sea cual sea su configuración. Pedido explícito del
 * usuario (2026-09-15): "quiero que cubra todo".
 *
 * Este proveedor delega en el de Filament para los 20 Resources (sin
 * reinventar esa parte -- ver el fix real del mismo día:
 * $recordTitleAttribute como propiedad de CLASE del Resource, no
 * ->recordTitleAttribute() de la tabla, que es una cosa completamente
 * distinta y no alimenta el buscador global) y AGREGA categorías propias
 * para los documentos operativos reales que tienen un código único al que
 * un usuario esperaría "saltar" al buscarlo.
 *
 * Kardex y Ventas quedaron fuera a propósito, no por omisión: Kardex es un
 * libro de movimientos (millones de filas, sin un "documento" individual
 * al que ir) y Ventas externas es un consolidado diario por canal, no un
 * documento con código propio -- ninguno de los dos encaja en "buscar por
 * código y saltar a un registro puntual", que es el caso de uso real de
 * este buscador.
 */
class CrmGlobalSearchProvider implements GlobalSearchProvider
{
    private const LIMITE = 10;

    public function getResults(string $query): ?GlobalSearchResults
    {
        $resultados = (new DefaultGlobalSearchProvider())->getResults($query) ?? GlobalSearchResults::make();

        $this->buscarGuiasInternas($resultados, $query);
        $this->buscarRequerimientos($resultados, $query);
        $this->buscarMovimientosAlmacenes($resultados, $query);

        return $resultados;
    }

    private function buscarGuiasInternas(GlobalSearchResults $resultados, string $query): void
    {
        if (! auth()->user()?->hasPermission('guias-internas.view')) {
            return;
        }

        $guias = GuiaInterna::query()
            ->where('restaurant_id', 'ilike', "%{$query}%")
            ->orderByDesc('fecha_emision')
            ->limit(self::LIMITE)
            ->get();

        if ($guias->isEmpty()) {
            return;
        }

        $resultados->category('Guías internas', $guias->map(fn (GuiaInterna $guia): GlobalSearchResult => new GlobalSearchResult(
            title: "Guía {$guia->restaurant_id}",
            url: GuiasInternas::getUrl(),
            details: [
                'Origen' => $guia->local_origen ?? '—',
                'Destino' => $guia->local_destino ?? '—',
                'Estado' => $guia->estado ?? '—',
                'Emisión' => $guia->fecha_emision?->format('d/m/Y') ?? '—',
            ],
        ))->all());
    }

    private function buscarRequerimientos(GlobalSearchResults $resultados, string $query): void
    {
        if (! auth()->user()?->hasPermission('requerimientos-stock.view')) {
            return;
        }

        $requerimientos = RequerimientoStockHistorico::query()
            ->where('erp_id', 'ilike', "%{$query}%")
            ->orderByDesc('fecha_registro')
            ->limit(self::LIMITE)
            ->get();

        if ($requerimientos->isEmpty()) {
            return;
        }

        $resultados->category('Requerimientos de stock', $requerimientos->map(fn (RequerimientoStockHistorico $req): GlobalSearchResult => new GlobalSearchResult(
            title: "Requerimiento {$req->erp_id}",
            url: ListaRequerimientos::getUrl(),
            details: [
                'Solicitado por' => $req->solicitado_por ?? '—',
                'Producción' => $req->local_produccion ?? '—',
                'Estado' => $req->estado ?? '—',
                'Registro' => $req->fecha_registro?->format('d/m/Y') ?? '—',
            ],
        ))->all());
    }

    private function buscarMovimientosAlmacenes(GlobalSearchResults $resultados, string $query): void
    {
        if (! auth()->user()?->hasPermission('movimientos-almacenes.view')) {
            return;
        }

        $movimientos = MovimientoAlmacenHistorico::query()
            ->where('restaurant_id', 'ilike', "%{$query}%")
            ->orderByDesc('fecha')
            ->limit(self::LIMITE)
            ->get();

        if ($movimientos->isEmpty()) {
            return;
        }

        $resultados->category('Movimientos entre almacenes', $movimientos->map(fn (MovimientoAlmacenHistorico $mov): GlobalSearchResult => new GlobalSearchResult(
            title: "Movimiento {$mov->restaurant_id}",
            url: MovimientosAlmacenes::getUrl(),
            details: [
                'Origen' => $mov->local_origen ?? '—',
                'Destino' => $mov->local_destino ?? '—',
                'Estado' => $mov->estado ?? '—',
                'Fecha' => $mov->fecha?->format('d/m/Y') ?? '—',
            ],
        ))->all());
    }
}
