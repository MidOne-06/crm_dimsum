<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class MovimientosAlmacenesGatewayClient
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.stock_gateway.base_url'), '/').'/movimientos-almacenes';
    }

    /** @return array<string, mixed> */
    public function movimientos(array $filters): array
    {
        return $this->get('/api/movimientos', $filters);
    }

    /** @return array<string, mixed> */
    public function detalle(string $id): array
    {
        return $this->get('/api/movimientos/'.rawurlencode($id));
    }

    public function anular(string $id): array
    {
        return $this->post('/api/movimientos/'.rawurlencode($id).'/anular');
    }

    /** @param array<string, mixed> $payload */
    public function editar(string $id, array $payload): array
    {
        return $this->post('/api/movimientos/'.rawurlencode($id).'/editar', $payload);
    }

    /** @param array<string, mixed> $payload */
    public function previsualizarNuevo(array $payload): array
    {
        return $this->post('/api/nuevo/previsualizar', $payload);
    }

    /** @param array<string, mixed> $payload */
    public function crear(array $payload): array
    {
        return $this->post('/api/nuevo/guardar', $payload);
    }

    /** @return array{content:string,contentType:string} */
    public function reporte(string $id, string $variant): array
    {
        $response = Http::baseUrl($this->baseUrl)->timeout(180)->get('/api/reporte', ['id' => $id, 'variant' => $variant]);
        if ($response->failed()) {
            $body = $response->json();
            throw new RuntimeException($body['error'] ?? 'No se pudo descargar el PDF del movimiento.');
        }

        return ['content' => $response->body(), 'contentType' => $response->header('Content-Type') ?: 'application/pdf'];
    }

    /** @return array<string, mixed> */
    public function contextoFiltros(): array
    {
        return $this->get('/api/contexto-filtros');
    }

    /** @return array<int, array<string, mixed>> */
    public function locales(): array
    {
        return $this->get('/api/locals')['locals'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function almacenes(string $localId): array
    {
        return $this->get('/api/almacenes', ['local_id' => $localId])['almacenes'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function almacenesTodos(): array
    {
        return $this->get('/api/almacenes-todos')['almacenes'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function items(string $search, string $localId): array
    {
        return $this->get('/api/items', ['q' => $search, 'local_id' => $localId])['items'] ?? [];
    }

    /** @return array<string, mixed> */
    private function get(string $path, array $query = []): array
    {
        return retry(3, function () use ($path, $query): array {
            $response = Http::baseUrl($this->baseUrl)->timeout(90)->get($path, $query);
            $body = $response->json();

            if ($response->failed()) {
                throw new RuntimeException($body['error'] ?? 'No se pudo consultar Movimientos entre almacenes.');
            }

            return is_array($body) ? $body : [];
        }, 2000);
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $payload = []): array
    {
        $response = Http::baseUrl($this->baseUrl)->timeout(120)->post($path, $payload);
        $body = $response->json();
        if ($response->failed()) {
            throw new RuntimeException($body['error'] ?? 'No se pudo completar la operación en Restaurant.');
        }

        return is_array($body) ? $body : [];
    }
}
