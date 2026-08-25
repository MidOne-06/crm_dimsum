<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente hacia el módulo /cargar-stock-final del gateway Node (D:\DS-TI\API-TI).
 * guardar() escribe contra el ERP de Dim Sum en producción (crea un cuadre
 * manual real, visible en Logística) — úsalo solo tras confirmación explícita
 * del usuario, nunca de forma automática o en pruebas.
 */
class StockFinalGatewayClient
{
    private const MODULE_PREFIX = '/cargar-stock-final';

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.stock_gateway.base_url'), '/').self::MODULE_PREFIX;
    }

    /** @return array<int, array{id: string, name: string}> */
    public function locals(): array
    {
        return $this->get('/api/locals')['locals'] ?? [];
    }

    /** @return array<int, array{value: string, label: string}> */
    public function tipos(): array
    {
        return $this->get('/api/tipos')['tipos'] ?? [];
    }

    /** @return array<int, array{id: string, nombre: string}> */
    public function almacenes(string $localId): array
    {
        if ($localId === '') {
            return [];
        }

        return $this->get('/api/almacenes', ['local_id' => $localId])['almacenes'] ?? [];
    }

    /** @return array<int, array{id: string, label: string}> */
    public function categorias(): array
    {
        return $this->get('/api/categorias')['categorias'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function items(array $filters): array
    {
        if (($filters['local_id'] ?? '') === '' || ($filters['almacen_id'] ?? '') === '') {
            return [];
        }

        return $this->get('/api/items', $filters)['items'] ?? [];
    }

    /**
     * Crea un cuadre manual de stock real en Dim Sum. $items debe ser el arreglo
     * completo devuelto por items(), con los valores editados por el usuario
     * (inventario_cantidad / costoNuevo dentro de almacenes[0]); el gateway
     * filtra internamente y solo guarda los que de verdad cambiaron.
     *
     * @return array{itemsGuardados: int, data: mixed}
     */
    public function guardar(string $localId, string $fecha, string $razon, array $items): array
    {
        $response = Http::baseUrl($this->baseUrl)->timeout(180)->post('/api/guardar', [
            'local_id' => $localId,
            'fecha' => $fecha,
            'razon' => $razon,
            'items' => $items,
        ]);

        $body = $response->json();

        if ($response->failed()) {
            throw new RuntimeException($body['error'] ?? 'No se pudo guardar el cuadre en Logística.');
        }

        return is_array($body) ? $body : [];
    }

    /** @return array<int, array{id: string, nombre: string, almacen_id: string, almacen_nombre: ?string, fecharegistro: string}> */
    public function plantillas(string $localId): array
    {
        if ($localId === '') {
            return [];
        }

        return $this->get('/api/plantillas', ['local_id' => $localId])['plantillas'] ?? [];
    }

    /** @return array{id: string, nombre: string, local_id: string, almacen_id: string, items: array<int, array{item_id: string, item_codigo: ?string, item_descripcion: ?string, cantidad: float, costo: float}>} */
    public function plantilla(string $plantillaId): array
    {
        return $this->get('/api/plantilla', ['id' => $plantillaId]);
    }

    /**
     * Crea una plantilla (guardarComo=3) o un cuadre + plantilla (guardarComo=2)
     * en Logística. A diferencia de guardar(), aquí se manda la lista COMPLETA
     * de ítems visibles (no solo los cambiados) porque una plantilla es una
     * lista de referencia, no un delta de cambios.
     *
     * @return array{itemsGuardados: int, data: mixed}
     */
    public function guardarPlantilla(string $localId, string $fecha, string $nombre, array $items, int $guardarComo = 3): array
    {
        $response = Http::baseUrl($this->baseUrl)->timeout(180)->post('/api/guardar-plantilla', [
            'local_id' => $localId,
            'fecha' => $fecha,
            'nombre' => $nombre,
            'items' => $items,
            'guardarComo' => $guardarComo,
        ]);

        $body = $response->json();

        if ($response->failed()) {
            throw new RuntimeException($body['error'] ?? 'No se pudo guardar la plantilla en Logística.');
        }

        return is_array($body) ? $body : [];
    }

    private function get(string $path, array $query = []): array
    {
        $response = Http::baseUrl($this->baseUrl)->timeout(120)->get($path, $query);

        $body = $response->json();

        if ($response->failed()) {
            throw new RuntimeException($body['error'] ?? 'No se pudo consultar el servicio de carga de stock.');
        }

        return is_array($body) ? $body : [];
    }
}
