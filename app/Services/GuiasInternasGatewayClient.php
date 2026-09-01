<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GuiasInternasGatewayClient
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.stock_gateway.base_url'), '/') . '/guias-internas';
    }

    public function guias(array $filters): array { return $this->get('/api/guias', $filters); }
    public function detalle(string $id): array { return $this->get('/api/guias/' . $id); }
    public function locales(): array { return $this->get('/api/locals')['locals'] ?? []; }
    public function almacenes(string $local): array { return $this->get('/api/almacenes', ['local_id' => $local])['almacenes'] ?? []; }
    public function motivos(): array { return $this->get('/api/motivos')['motivos'] ?? []; }
    public function recurrentes(string $local): array { return $this->get('/api/recurrentes', ['local_id' => $local]); }
    public function siguienteCorrelativo(string $serie): array { return $this->get('/api/siguiente-correlativo', ['serie' => $serie]); }
    public function items(string $q, string $local): array { return $this->get('/api/items', ['q' => $q, 'local_id' => $local])['items'] ?? []; }
    public function motorizados(string $q): array { return $this->get('/api/motorizados', ['q' => $q])['motorizados'] ?? []; }
    public function transportistas(string $q): array { return $this->get('/api/transportistas', ['q' => $q])['transportistas'] ?? []; }
    public function clientes(string $q): array { return $this->get('/api/clientes', ['q' => $q])['clientes'] ?? []; }
    public function guardarMotorizado(array $payload): array { return $this->post('/api/motorizados', $payload); }
    /** @param array<int, string|int> $ids */
    public function importarRequerimientos(array $ids): array { return $this->post('/api/requerimientos/importar', ['ids' => array_values($ids)]); }
    public function guardar(array $payload): array { return $this->post('/api/guardar', $payload); }
    public function anular(string $id, bool $devolverCantidades = true): array { return $this->post('/api/anular', ['id' => $id, 'devolverCantidades' => $devolverCantidades]); }

    /** @return array{content:string,contentType:string} */
    public function reporte(string $id, string $variant): array
    {
        $response = Http::baseUrl($this->baseUrl)->timeout(180)->get('/api/reporte', ['id' => $id, 'variant' => $variant]);
        if ($response->failed()) {
            $body = $response->json();
            throw new RuntimeException($body['error'] ?? 'No se pudo descargar la guía interna.');
        }
        return ['content' => $response->body(), 'contentType' => $response->header('Content-Type') ?: 'application/octet-stream'];
    }

    private function get(string $path, array $query = []): array
    {
        // Reintenta ante caídas transitorias del gateway (timeout, conexión
        // rechazada, 5xx) para que un blip de red no tumbe una extracción
        // masiva de decenas de páginas -- ver GuiasInternasHistoricoService.
        return retry(3, function () use ($path, $query): array {
            $response = Http::baseUrl($this->baseUrl)->timeout(90)->get($path, $query);
            $body = $response->json();
            if ($response->failed()) throw new RuntimeException($body['error'] ?? 'No se pudo consultar Guías internas.');

            return is_array($body) ? $body : [];
        }, 2000);
    }

    private function post(string $path, array $payload): array
    {
        $response = Http::baseUrl($this->baseUrl)->timeout(120)->post($path, $payload);
        $body = $response->json();
        if ($response->failed()) throw new RuntimeException($body['error'] ?? 'No se pudo completar la operación en Restaurant.');

        return is_array($body) ? $body : [];
    }
}
