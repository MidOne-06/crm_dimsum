<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente hacia el gateway Node de D:\DS-TI\API-TI (server.js), que mantiene
 * una sesión Playwright autenticada contra Logística Dim Sum (Restaurant.pe)
 * y expone endpoints REST de solo lectura sobre esa sesión.
 */
class StockGatewayClient
{
    // El gateway organiza sus endpoints en módulos (D:\DS-TI\API-TI\modules\cuadres-stock);
    // todas las rutas de este cliente viven bajo ese prefijo.
    private const MODULE_PREFIX = '/cuadres-stock';

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

    /** @return array{estados: array, tipos: array} */
    public function filterOptions(): array
    {
        return $this->get('/api/filter-options');
    }

    /** @return array<int, array{id: string, type: string, subtype: ?string, name: string, code: string}> */
    public function searchItems(string $query): array
    {
        if ($query === '') {
            return [];
        }

        return $this->get('/api/items', ['q' => $query])['items'] ?? [];
    }

    /** @return array{filters: array, header: array, rows: array, total: int} */
    public function cuadres(array $filters): array
    {
        return $this->get('/api/cuadres', $filters);
    }

    public function cuadreDetail(string|int $id): array
    {
        return $this->get('/api/cuadres/'.$id);
    }

    /** @return array{filters: array, cuadresIncluidos: int, master: array, summary: array} */
    public function stockReport(array $filters): array
    {
        return $this->get('/api/stock-report', $filters);
    }

    private function get(string $path, array $query = []): array
    {
        $response = Http::baseUrl($this->baseUrl)->timeout(60)->get($path, $query);

        $body = $response->json();

        if ($response->failed()) {
            throw new RuntimeException($body['error'] ?? 'No se pudo consultar el servicio de stock.');
        }

        return is_array($body) ? $body : [];
    }
}
