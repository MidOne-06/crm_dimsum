<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SalesGatewayClient
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.stock_gateway.base_url'), '/').'/ventas';
    }

    public function locals(): array
    {
        return $this->get('/api/locals')['locals'] ?? [];
    }

    public function currencies(): array
    {
        return $this->get('/api/monedas')['monedas'] ?? [];
    }

    public function filterOptions(): array
    {
        return $this->get('/api/opciones');
    }

    public function sales(array $filters): array
    {
        return $this->get('/api/ventas', $filters);
    }

    public function saleDetail(string|int $id): array
    {
        return $this->get('/api/ventas/'.$id);
    }

    private function get(string $path, array $query = []): array
    {
        // Auditoría 2026-09-15: este cliente era el único de los gateway
        // clients de lectura sin reintento -- un blip transitorio del
        // gateway (reconexión de sesión Playwright, ver comentario en
        // MovimientosAlmacenesGatewayClient) tumbaba en el primer intento
        // cualquier job de ventas que lo llamara (21 jobs fallidos el
        // 2026-09-15). Mismo patrón que StockGatewayClient/GuiasInternas/etc.
        return retry(3, function () use ($path, $query): array {
            $response = Http::baseUrl($this->baseUrl)->timeout(120)->get($path, $query);
            $body = $response->json();

            if ($response->failed()) {
                throw new RuntimeException($body['error'] ?? 'No se pudo consultar el servicio de ventas.');
            }

            return is_array($body) ? $body : [];
        }, 2000);
    }
}
