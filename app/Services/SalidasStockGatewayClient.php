<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SalidasStockGatewayClient
{
    private string $baseUrl;
    public function __construct() { $this->baseUrl = rtrim((string) config('services.stock_gateway.base_url'), '/').'/salidas-stock'; }
    public function salidas(array $filters): array { return $this->get('/api/salidas', $filters); }
    public function detalle(string $id): array { return $this->get('/api/salidas/'.$id); }
    public function locales(): array { return $this->get('/api/locals')['locals'] ?? []; }
    public function categorias(): array { return $this->get('/api/categories')['categories'] ?? []; }
    public function almacenes(string $localId): array { return $this->get('/api/almacenes', ['local_id' => $localId])['almacenes'] ?? []; }
    public function items(string $query, string $localId): array { return $this->get('/api/items', ['q' => $query, 'local_id' => $localId])['items'] ?? []; }
    public function guardar(array $payload): array {
        $response = Http::baseUrl($this->baseUrl)->timeout(120)->post('/api/guardar', $payload);
        $body = $response->json();
        if ($response->failed()) throw new RuntimeException($body['error'] ?? 'No se pudo registrar la salida de stock.');
        return is_array($body) ? $body : [];
    }
    private function get(string $path, array $query = []): array {
        $response = Http::baseUrl($this->baseUrl)->timeout(60)->get($path, $query);
        $body = $response->json();
        if ($response->failed()) throw new RuntimeException($body['error'] ?? 'No se pudo consultar Salidas de Stock.');
        return is_array($body) ? $body : [];
    }
}
