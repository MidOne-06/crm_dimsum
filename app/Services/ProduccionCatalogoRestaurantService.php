<?php

namespace App\Services;

use App\Models\ProduccionProducto;
use Illuminate\Support\Str;

class ProduccionCatalogoRestaurantService
{
    /** Productos terminados de Fábrica y salsas, validados contra Restaurant. */
    private const CODIGOS_INICIALES = [
        'SM001', 'SM002', 'SM003', 'WK001',
        'MP001', 'MP002', 'MP003', 'MP004',
        'ER001', 'AA003', 'AB001', 'KP001', 'WT001', 'SK001', 'TP001',
        'CS001', 'CH001', 'SA001', 'SA002', 'SA003', 'SA004', 'SA005',
    ];

    public function __construct(private readonly MovimientosAlmacenesGatewayClient $gateway) {}

    /** @return array<string, string> */
    public function opciones(string $busqueda): array
    {
        $busqueda = trim($busqueda);

        if ($busqueda === '') {
            return [];
        }

        return collect($this->gateway->items($busqueda, '1'))
            ->filter(fn (array $item): bool => $this->esProductoProduccion($item))
            ->unique(fn (array $item): string => $this->clave($item))
            ->take(50)
            ->mapWithKeys(fn (array $item): array => [$this->clave($item) => $this->etiqueta($item)])
            ->all();
    }

    /** @return array{restaurant_item_id:string,restaurant_item_tipo:string,restaurant_presentacion_id:?string,codigo:?string,nombre:string,unidad:string}|null */
    public function productoDesdeClave(?string $clave): ?array
    {
        if (blank($clave)) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr((string) $clave, '-_', '+/'), true) ?: '', true);

        if (! is_array($payload) || ! $this->esProductoProduccion($payload)) {
            return null;
        }

        return $this->normalizarItem($payload);
    }

    /** @return array{creados:int,actualizados:int,faltantes:array<int,string>} */
    public function sincronizarCatalogoInicial(): array
    {
        $creados = 0;
        $actualizados = 0;
        $faltantes = [];

        foreach (self::CODIGOS_INICIALES as $codigo) {
            $item = collect($this->gateway->items($codigo, '1'))
                ->first(fn (array $fila): bool => (string) ($fila['item_tipo'] ?? '') === '1'
                    && strtoupper(trim((string) ($fila['codigo'] ?? ''))) === $codigo
                    && $this->esProductoProduccion($fila));

            if (! $item) {
                $faltantes[] = $codigo;
                continue;
            }

            $datos = $this->normalizarItem($item);
            $producto = ProduccionProducto::query()->firstOrNew([
                'restaurant_item_id' => $datos['restaurant_item_id'],
                'restaurant_item_tipo' => $datos['restaurant_item_tipo'],
                'restaurant_presentacion_id' => $datos['restaurant_presentacion_id'],
            ]);
            $nuevo = ! $producto->exists;
            $producto->fill($datos);
            if ($nuevo) {
                $producto->activo = true;
            }
            $producto->save();

            $nuevo ? $creados++ : $actualizados++;
        }

        return compact('creados', 'actualizados', 'faltantes');
    }

    /** @param array<string, mixed> $payload @return array{restaurant_item_id:string,restaurant_item_tipo:string,restaurant_presentacion_id:?string,codigo:?string,nombre:string,unidad:string} */
    private function normalizarItem(array $payload): array
    {
        $codigo = trim((string) ($payload['codigo'] ?? ''));

        return [
            'restaurant_item_id' => (string) $payload['id'],
            'restaurant_item_tipo' => (string) $payload['item_tipo'],
            'restaurant_presentacion_id' => filled($payload['presentacion_id'] ?? null) ? (string) $payload['presentacion_id'] : null,
            'codigo' => in_array($codigo, ['', '-'], true) ? null : $codigo,
            'nombre' => trim((string) $payload['descripcion']),
            'unidad' => trim((string) ($payload['unidad'] ?? '')) ?: 'UNIDAD',
        ];
    }

    /**
     * Pedido explícito del usuario (2026-09-17): el filtro original solo
     * dejaba pasar item_tipo='1', pensado nada más para los 22 códigos
     * iniciales -- pero esa taxonomía de Restaurant es una bolsa mezclada
     * (tipo 1 incluye desde cajas de torta hasta piedras de afilar) y
     * excluía categorías reales de producción, como postres (bizcochuelo
     * vive en tipo='2'). Se abre a TODOS los tipos -- la selección sigue
     * siendo manual (el operario busca y elige el ítem exacto), así que un
     * resultado irrelevante en la lista es solo ruido, no un dato que se
     * guarde sin revisar. Se mantiene la única exclusión de negocio ya
     * pedida antes: nunca gaseosas.
     */
    private function esProductoProduccion(array $item): bool
    {
        $nombre = Str::upper((string) ($item['descripcion'] ?? ''));

        return filled($nombre) && ! str_contains($nombre, 'GASEOSA')
            && ! Str::contains($nombre, ['COCA COLA', 'INCA KOLA', 'PEPSI', 'SPRITE', 'FANTA', 'SEVEN UP', '7UP']);
    }

    /** @param array<string, mixed> $item */
    private function clave(array $item): string
    {
        $payload = [
            'id' => (string) ($item['id'] ?? ''),
            'item_tipo' => (string) ($item['item_tipo'] ?? ''),
            'codigo' => (string) ($item['codigo'] ?? ''),
            'descripcion' => trim((string) ($item['descripcion'] ?? '')),
            'presentacion_id' => filled($item['presentacion_id'] ?? null) ? (string) $item['presentacion_id'] : null,
            'unidad' => trim((string) ($item['unidad'] ?? '')),
        ];

        return rtrim(strtr(base64_encode((string) json_encode($payload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $item */
    private function etiqueta(array $item): string
    {
        $codigo = trim((string) ($item['codigo'] ?? ''));
        $presentacion = trim((string) ($item['presentacion'] ?? ''));

        return collect([$codigo !== '-' ? $codigo : null, $item['descripcion'] ?? null, $presentacion ?: null, $item['unidad'] ?? null])
            ->filter(fn ($value): bool => filled($value))
            ->implode(' · ');
    }
}
