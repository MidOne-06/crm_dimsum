<?php

namespace App\Services;

use App\Models\RequerimientoStockHistorico;
use App\Models\RequerimientoStockHistoricoDetalle;
use Illuminate\Support\Facades\DB;

class RequerimientoStockHistoricoService
{
    /** @param array{cabecera: array<string, mixed>, detalles: array<int, array<string, mixed>>} $detalle */
    public function sincronizar(array $detalle): RequerimientoStockHistorico
    {
        $cabecera = $detalle['cabecera'] ?? [];
        $erpId = (string) ($cabecera['codigo'] ?? '');

        if (! ctype_digit($erpId)) {
            throw new \RuntimeException('No se recibió un código válido para el requerimiento.');
        }

        return DB::transaction(function () use ($cabecera, $detalle, $erpId): RequerimientoStockHistorico {
            $anterior = RequerimientoStockHistorico::query()->where('erp_id', $erpId)->first();
            $requerimiento = RequerimientoStockHistorico::query()->updateOrCreate(
                ['erp_id' => $erpId],
                [
                    'fecha_registro' => $cabecera['fecha_registro'] ?: null,
                    'fecha_abastecimiento' => $cabecera['fecha_abastecimiento'] ?: null,
                    'solicitado_por' => $cabecera['solicitado_por'] ?: null,
                    'local_produccion' => $cabecera['local_produccion'] ?: null,
                    'encargado' => $cabecera['encargado'] ?: null,
                    'receptor' => $cabecera['receptor'] ?: null,
                    'estado' => $cabecera['estado'] ?: null,
                    'observacion' => $cabecera['observacion'] ?: null,
                    'sincronizado_en' => now(),
                    'ultima_sincronizacion_error' => null,
                    'payload_restaurant' => $detalle['origen_restaurant'] ?? null,
                ],
            );

            $detailIds = [];
            foreach ($detalle['detalles'] ?? [] as $position => $item) {
                $detailId = (string) ($item['erp_detalle_id'] ?? ($erpId.'-'.$position));
                $detailIds[] = $detailId;
                $requerimiento->detalles()->updateOrCreate(
                    ['erp_detalle_id' => $detailId],
                    [
                        'codigo' => $item['codigo'] ?: null,
                        'item' => $item['item'] ?: null,
                        'categoria' => $item['categoria'] ?: null,
                        'presentacion' => $item['presentacion'] ?: null,
                        'cantidad_solicitada' => $item['cantidad_solicitada'] ?? 0,
                        'cantidad_despachada' => $item['cantidad_despachada'] ?? 0,
                        'cantidad_preparada' => $item['cantidad_preparada'] ?? 0,
                        'unidad' => $item['unidad'] ?: null,
                        'almacen' => $item['almacen'] ?: null,
                        'observacion' => $item['observacion'] ?: null,
                        'activo' => true,
                        'eliminado_en' => null,
                        'payload_restaurant' => $item['payload_restaurant'] ?? null,
                    ],
                );
            }

            // Restaurant puede retirar un detalle. Se conserva localmente como
            // inactivo para que el historial sea auditable y no se pierda.
            if ($detailIds !== []) {
                $requerimiento->detalles()->whereNotIn('erp_detalle_id', $detailIds)->update([
                    'activo' => false,
                    'eliminado_en' => now(),
                ]);
            }

            $this->registrarEvento($requerimiento, $anterior, $detalle);

            return $requerimiento;
        });
    }

    /** @return array{cabecera: array<string, mixed>, detalles: array<int, array<string, mixed>>}|null */
    public function obtenerLocal(string $erpId): ?array
    {
        $requerimiento = RequerimientoStockHistorico::query()
            ->with(['detalles' => fn ($query) => $query->where('activo', true)->orderBy('id')])
            ->where('erp_id', $erpId)
            ->first();

        if (! $requerimiento) return null;

        return [
            'cabecera' => [
                'codigo' => $requerimiento->erp_id, 'fecha_registro' => optional($requerimiento->fecha_registro)->format('Y-m-d H:i:s'),
                'fecha_abastecimiento' => optional($requerimiento->fecha_abastecimiento)->format('Y-m-d H:i:s'),
                'solicitado_por' => $requerimiento->solicitado_por, 'local_produccion' => $requerimiento->local_produccion,
                'encargado' => $requerimiento->encargado, 'receptor' => $requerimiento->receptor,
                'estado' => $requerimiento->estado, 'observacion' => $requerimiento->observacion,
                'sincronizado_en' => optional($requerimiento->sincronizado_en)->format('d/m/Y H:i:s'),
            ],
            'detalles' => $requerimiento->detalles->map(fn (RequerimientoStockHistoricoDetalle $item): array => [
                'erp_detalle_id' => $item->erp_detalle_id, 'codigo' => $item->codigo, 'item' => $item->item,
                'categoria' => $item->categoria, 'presentacion' => $item->presentacion,
                'cantidad_solicitada' => $item->cantidad_solicitada, 'cantidad_despachada' => $item->cantidad_despachada,
                'cantidad_preparada' => $item->cantidad_preparada, 'unidad' => $item->unidad,
                'almacen' => $item->almacen, 'observacion' => $item->observacion,
            ])->all(),
        ];
    }

    private function registrarEvento(RequerimientoStockHistorico $requerimiento, ?RequerimientoStockHistorico $anterior, array $detalle): void
    {
        $actual = [
            'cabecera' => $detalle['cabecera'] ?? [],
            'detalles' => $detalle['detalles'] ?? [],
            'origen_restaurant' => $detalle['origen_restaurant'] ?? null,
        ];
        $previo = $anterior ? ['cabecera' => $anterior->only(['fecha_registro', 'fecha_abastecimiento', 'solicitado_por', 'local_produccion', 'encargado', 'receptor', 'estado', 'observacion'])] : null;

        DB::table('requerimientos_stock_historico_eventos')->insert([
            'requerimiento_stock_historico_id' => $requerimiento->id,
            'tipo' => $anterior ? 'sincronizacion' : 'creacion',
            'antes' => $previo ? json_encode($previo, JSON_UNESCAPED_UNICODE) : null,
            'despues' => json_encode($actual, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
