<?php

namespace App\Services;

use Illuminate\Support\Str;

class ProduccionCatalogoRestaurantService
{
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

    /** @param array<string, mixed> $item */
    private function esProductoProduccion(array $item): bool
    {
        if ((string) ($item['item_tipo'] ?? '') !== '1') {
            return false;
        }

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
