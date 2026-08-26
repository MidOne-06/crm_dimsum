<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente hacia el gateway Node de D:\DS-TI\API-TI (server.js), módulo
 * requerimientos-stock: crea Requerimientos de Stock (traslados/solicitudes
 * de abastecimiento entre locales) contra Logística Dim Sum (Restaurant.pe).
 */
class RequerimientoStockGatewayClient
{
    private const MODULE_PREFIX = '/requerimientos-stock';

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

    /** @return array<int, array{id: string, nombre: string}> */
    public function almacenes(string $localId): array
    {
        return $this->get('/api/almacenes', ['local_id' => $localId])['almacenes'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function searchItems(string $query): array
    {
        if ($query === '') {
            return [];
        }

        return $this->get('/api/items', ['q' => $query])['items'] ?? [];
    }

    /** @return array{filters: array<string, mixed>, total: int, rows: array<int, array<string, mixed>>} */
    public function lista(array $filters): array
    {
        $query = array_filter([
            'pagina' => $filters['pagina'] ?? 1,
            'registros' => $filters['registros'] ?? 25,
            'fecha_inicio' => $filters['fecha_inicio'] ?? now()->toDateString(),
            'fecha_fin' => $filters['fecha_fin'] ?? now()->toDateString(),
            'locales' => implode(',', $filters['locales'] ?? []),
            'locales_produccion' => implode(',', $filters['locales_produccion'] ?? []),
            'estado' => $filters['estado'] ?? -1,
            'codigo' => $filters['codigo'] ?? '',
            'encargado' => $filters['encargado'] ?? '',
            'por_fecha' => $filters['por_fecha'] ?? 0,
            'items' => implode(',', array_map(
                fn (array $item): string => ($item['id'] ?? '').':'.($item['tipo'] ?? ''),
                $filters['items'] ?? [],
            )),
        ], fn (mixed $value): bool => $value !== '');

        return $this->get('/api/lista', $query);
    }

    /** @return array{total: int, rows: array<int, array<string, mixed>>} */
    public function plantillas(string $localId, int $pagina = 1, int $registros = 25): array
    {
        return $this->get('/api/plantillas', [
            'local_id' => $localId,
            'pagina' => $pagina,
            'registros' => $registros,
        ]);
    }

    /** @return array<string, mixed> */
    public function importarPlantilla(string $templateId, bool $incluirCantidadesCero = false): array
    {
        $response = Http::baseUrl($this->baseUrl)->timeout(120)->post('/api/plantillas/importar', [
            'templateId' => $templateId,
            'incluirCantidadesCero' => $incluirCantidadesCero,
        ]);
        $body = $response->json();
        if ($response->failed()) {
            throw new RuntimeException($body['error'] ?? 'No se pudo importar la plantilla.');
        }

        return is_array($body) ? $body : [];
    }

    /**
     * @param  array<int, array{item: array<string, mixed>, cantidad: float|int}>  $items
     */
    public function guardar(
        string $localOrigenId,
        string $almacenOrigenId,
        string $localDestinoId,
        string $encargado,
        string $fecha,
        array $items,
        string $receptor = '',
        string $observacion = '',
        bool $esSolicitudCompra = false,
    ): array {
        $response = Http::baseUrl($this->baseUrl)->timeout(120)->post('/api/guardar', [
            'localOrigenId' => $localOrigenId,
            'almacenOrigenId' => $almacenOrigenId,
            'localDestinoId' => $localDestinoId,
            'encargado' => $encargado,
            'receptor' => $receptor,
            'observacion' => $observacion,
            'fecha' => $fecha,
            'esSolicitudCompra' => $esSolicitudCompra,
            'items' => $items,
        ]);

        $body = $response->json();

        if ($response->failed()) {
            throw new RuntimeException($body['error'] ?? 'No se pudo guardar el requerimiento.');
        }

        return is_array($body) ? $body : [];
    }

    private function get(string $path, array $query = []): array
    {
        $response = Http::baseUrl($this->baseUrl)->timeout(120)->get($path, $query);

        $body = $response->json();

        if ($response->failed()) {
            throw new RuntimeException($body['error'] ?? 'No se pudo consultar el servicio de stock.');
        }

        return is_array($body) ? $body : [];
    }
}
