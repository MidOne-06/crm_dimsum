<?php

namespace App\Services;

use App\Models\ProduccionDiariaAuditoria;
use App\Models\ProduccionDiariaCierre;
use App\Models\ProduccionDiariaDetalle;
use App\Models\ProduccionDiariaSalida;
use App\Models\ProduccionDiariaTanda;
use App\Models\ProduccionProducto;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProduccionCierreService
{
    /** @return array{observacion: ?string, items: array<int, array<string, mixed>>} */
    public function formData(string $fecha, ?ProduccionDiariaCierre $cierre = null): array
    {
        $cierre ??= ProduccionDiariaCierre::query()->with('detalles')->whereDate('fecha', $fecha)->first();
        $cierre?->loadMissing('detalles');

        return [
            'observacion' => $cierre?->observacion,
            'items' => $this->itemsParaFormulario($fecha, $cierre),
        ];
    }

    /** @param array<string, mixed> $state */
    public function guardar(string $fecha, array $state, string $destino, int $usuarioId): ProduccionDiariaCierre
    {
        if (! in_array($destino, ['borrador', 'enviado'], true)) {
            throw ValidationException::withMessages(['items' => 'El destino de cierre no es válido.']);
        }

        return DB::transaction(function () use ($fecha, $state, $destino, $usuarioId): ProduccionDiariaCierre {
            $cierre = ProduccionDiariaCierre::query()->whereDate('fecha', $fecha)->lockForUpdate()->with('detalles')->first();
            if ($cierre?->estado === 'aprobado') {
                throw ValidationException::withMessages(['items' => 'El cierre ya fue aprobado.']);
            }
            if ($cierre?->estado === 'enviado') {
                throw ValidationException::withMessages(['items' => 'El cierre está enviado; debe devolverse a borrador antes de modificarlo.']);
            }
            if ($cierre) {
                $this->asegurarSinCierresAprobadosPosteriores($cierre);
            }

            $items = $this->normalizarItems($fecha, (array) ($state['items'] ?? []), $destino === 'enviado');
            $antes = $cierre ? $this->snapshot($cierre) : null;
            $cierre ??= new ProduccionDiariaCierre(['fecha' => $fecha, 'area' => 'FABRICA', 'creado_por' => $usuarioId]);
            $cierre->fill(['estado' => $destino, 'observacion' => $state['observacion'] ?? null]);
            if ($destino === 'enviado') {
                $cierre->fill(['enviado_por' => $usuarioId, 'enviado_en' => now()]);
            }
            $cierre->save();
            $cierre->detalles()->delete();
            $cierre->detalles()->createMany($items);

            ProduccionDiariaAuditoria::create([
                'cierre_id' => $cierre->id,
                'accion' => $antes ? ($destino === 'enviado' ? 'enviado' : 'actualizado') : 'creado',
                'antes' => $antes,
                'despues' => $this->snapshot($cierre->fresh('detalles')),
                'usuario_id' => $usuarioId,
            ]);

            return $cierre;
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function itemsParaFormulario(string $fecha, ?ProduccionDiariaCierre $cierre): array
    {
        $items = $this->itemsDesdeCatalogo($fecha)->keyBy('producto_id');

        foreach ($cierre?->detalles ?? [] as $detalle) {
            if (! $detalle->producto_id || ! $items->has($detalle->producto_id)) {
                continue;
            }
            $producido = $this->totalProducido($fecha, $detalle->producto_id);
            $salidas = $this->totalSalidas($fecha, $detalle->producto_id);
            $items->put($detalle->producto_id, [
                'producto_id' => $detalle->producto_id, 'item_codigo' => $detalle->item_codigo, 'item_nombre' => $detalle->item_nombre,
                'unidad' => $detalle->unidad, 'stock_inicial' => $detalle->stock_inicial, 'producido_hoy' => $producido, 'salidas_hoy' => $salidas,
                'stock_esperado' => round((float) $detalle->stock_inicial + $producido - $salidas, 4), 'stock_final' => $detalle->stock_final,
                'diferencia' => $detalle->diferencia, 'observacion' => $detalle->observacion, 'origen_inicial' => 'registro_guardado',
            ]);
        }

        return $items->values()->all();
    }

    /** @return \Illuminate\Support\Collection<int, ProduccionProducto> */
    private function productosDelDia(string $fecha): \Illuminate\Support\Collection
    {
        $idsConMovimiento = ProduccionDiariaTanda::query()->whereHas('cierre', fn ($query) => $query->whereDate('fecha', $fecha))->whereNotNull('producto_id')->pluck('producto_id')
            ->merge(ProduccionDiariaSalida::query()->whereHas('cierre', fn ($query) => $query->whereDate('fecha', $fecha))->whereNotNull('producto_id')->pluck('producto_id'))
            ->unique();

        return ProduccionProducto::query()
            ->where(fn ($query) => $query->where('activo', true)->orWhereIn('id', $idsConMovimiento))
            ->orderBy('nombre')
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function itemsDesdeCatalogo(string $fecha): \Illuminate\Support\Collection
    {
        return $this->productosDelDia($fecha)->map(function (ProduccionProducto $producto) use ($fecha): array {
            $inicial = $this->stockInicialAnterior($fecha, $producto->id);
            $producido = $this->totalProducido($fecha, $producto->id);
            $salidas = $this->totalSalidas($fecha, $producto->id);

            return [
                'producto_id' => $producto->id, 'item_codigo' => $producto->codigo, 'item_nombre' => $producto->nombre, 'unidad' => $producto->unidad,
                'stock_inicial' => $inicial ?? 0, 'producido_hoy' => $producido, 'salidas_hoy' => $salidas,
                'stock_esperado' => round(($inicial ?? 0) + $producido - $salidas, 4), 'stock_final' => null, 'diferencia' => null,
                'observacion' => null, 'origen_inicial' => $inicial === null ? 'apertura' : 'cierre_anterior',
            ];
        });
    }

    /** @param array<int, array<string, mixed>> $items @return array<int, array<string, mixed>> */
    private function normalizarItems(string $fecha, array $items, bool $requiereFinal): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'No hay productos activos en el catálogo de Producción.']);
        }
        $catalogo = $this->productosDelDia($fecha)->keyBy('id');

        return collect($items)->map(function (array $item) use ($fecha, $catalogo, $requiereFinal): array {
            $producto = $catalogo->get($item['producto_id'] ?? null);
            if (! $producto) {
                throw ValidationException::withMessages(['items' => 'El producto no pertenece al catálogo de Producción de hoy.']);
            }
            $inicialAnterior = $this->stockInicialAnterior($fecha, $producto->id);
            $inicial = $inicialAnterior ?? (float) ($item['stock_inicial'] ?? 0);
            $producido = $this->totalProducido($fecha, $producto->id);
            $salidas = $this->totalSalidas($fecha, $producto->id);
            $final = filled($item['stock_final'] ?? null) ? (float) $item['stock_final'] : null;
            if ($inicial < 0 || ($final !== null && $final < 0)) {
                throw ValidationException::withMessages(['items' => 'Las cantidades no pueden ser negativas.']);
            }
            if ($requiereFinal && $final === null) {
                throw ValidationException::withMessages(['items' => "{$producto->nombre}: registra el stock final físico."]);
            }
            $esperado = round($inicial + $producido - $salidas, 4);
            $diferencia = $final === null ? null : round($final - $esperado, 4);
            $observacion = trim((string) ($item['observacion'] ?? '')) ?: null;
            if ($requiereFinal && $diferencia !== null && abs($diferencia) > 0.0001 && $observacion === null) {
                throw ValidationException::withMessages(['items' => "{$producto->nombre}: indica el motivo de la diferencia."]);
            }

            return [
                'producto_id' => $producto->id, 'item_id' => (string) $producto->id, 'item_tipo' => 'produccion', 'item_codigo' => $producto->codigo,
                'item_nombre' => $producto->nombre, 'unidad' => $producto->unidad, 'stock_inicial' => $inicial, 'producido_hoy' => $producido,
                'salidas_hoy' => $salidas, 'stock_esperado' => $esperado, 'stock_final' => $final, 'diferencia' => $diferencia, 'observacion' => $observacion,
            ];
        })->values()->all();
    }

    private function totalProducido(string $fecha, int $productoId): float
    {
        return (float) ProduccionDiariaTanda::query()->whereHas('cierre', fn ($query) => $query->whereDate('fecha', $fecha))->where('producto_id', $productoId)->sum('cantidad');
    }

    private function totalSalidas(string $fecha, int $productoId): float
    {
        return (float) ProduccionDiariaSalida::query()->whereHas('cierre', fn ($query) => $query->whereDate('fecha', $fecha))->where('producto_id', $productoId)->sum('cantidad');
    }

    private function stockInicialAnterior(string $fecha, int $productoId): ?float
    {
        $value = ProduccionDiariaDetalle::query()->select('produccion_diaria_detalles.stock_final')
            ->join('produccion_diaria_cierres as cierres', 'cierres.id', '=', 'produccion_diaria_detalles.cierre_id')
            ->where('produccion_diaria_detalles.producto_id', $productoId)->where('cierres.estado', 'aprobado')->whereDate('cierres.fecha', '<', $fecha)
            ->whereNotNull('produccion_diaria_detalles.stock_final')->orderByDesc('cierres.fecha')->value('produccion_diaria_detalles.stock_final');

        return $value === null ? null : (float) $value;
    }

    private function asegurarSinCierresAprobadosPosteriores(ProduccionDiariaCierre $cierre): void
    {
        if (ProduccionDiariaCierre::query()->whereDate('fecha', '>', $cierre->fecha->toDateString())->where('estado', 'aprobado')->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['items' => 'No se puede modificar este cierre porque un cierre posterior ya fue aprobado.']);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(ProduccionDiariaCierre $cierre): array
    {
        return [
            'fecha' => $cierre->fecha?->toDateString(), 'estado' => $cierre->estado, 'observacion' => $cierre->observacion,
            'detalles' => $cierre->detalles->map(fn (ProduccionDiariaDetalle $detalle): array => $detalle->only([
                'producto_id', 'stock_inicial', 'producido_hoy', 'salidas_hoy', 'stock_esperado', 'stock_final', 'diferencia', 'observacion',
            ]))->all(),
        ];
    }
}
