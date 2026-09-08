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
    public function crear(array $payload): array
    {
        return $this->post('/api/nuevo/guardar', $payload);
    }

    /** Hidrata en Restaurant el canje de guías sin registrar ningún movimiento. */
    public function prepararCanjeGuias(array $ids): array
    {
        return $this->post('/api/guias-importadas', ['ids' => $ids]);
    }

    /** Registra en Restaurant el movimiento que canjea las guías seleccionadas. */
    public function canjearGuias(array $payload): array
    {
        return $this->post('/api/guias-importadas/canjear', $payload);
    }

    /** Agrupa en vivo guías compatibles sin registrar movimientos. */
    public function prepararCanjeGuiasMasivo(array $ids): array
    {
        // Timeout largo (ver canjearGuiasMasivo): hidrata hasta 20 guías una
        // por una y a propósito en secuencia, según el propio gateway.
        return $this->post('/api/guias-importadas/canje-masivo/preparar', ['ids' => $ids], 300);
    }

    /**
     * Registra cada grupo compatible como un movimiento independiente.
     *
     * Timeout de 300s (no los 120s por defecto de post()): esta llamada
     * procesa hasta 20 guías EN SECUENCIA dentro de Restaurant (a propósito,
     * la sesión no soporta ráfagas -- ver ConfirmarCanjeMasivoJob), y cada
     * una pasa por 5 etapas propias. Encontrado en vivo con la primera
     * corrida real de 611 guías: varios lotes de 20 tardaron más de 120s
     * (picos de sesión reconectando), el cliente HTTP de Laravel los daba
     * por "fallidos" (cURL error 28, timeout) mientras Restaurant seguía
     * procesando la petición en segundo plano y SÍ terminaba de registrar el
     * movimiento real -- confirmado en los logs del gateway, sin ningún
     * "falló en registro del movimiento" para esos lotes, y las guías
     * desaparecieron del listado de "activas y pendientes" después. Ese
     * timeout corto no perdía datos (el lote quedaba mal contabilizado como
     * "fallida" en vez de "confirmada", nunca se reintentaba solo), pero sí
     * generaba un reporte final incorrecto -- y un reintento manual futuro
     * sobre una guía que en realidad ya se había confirmado sí sería
     * peligroso (movimiento duplicado). 300s da margen real de sobra para
     * un lote de 20 aun con reconexión de sesión de por medio.
     */
    public function canjearGuiasMasivo(array $payload): array
    {
        return $this->post('/api/guias-importadas/canje-masivo/confirmar', $payload, 300);
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
    public function tiposMovimiento(): array
    {
        return $this->get('/api/tipos-movimiento')['tipos'] ?? [];
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
    private function post(string $path, array $payload = [], int $timeout = 120): array
    {
        $response = Http::baseUrl($this->baseUrl)->timeout($timeout)->post($path, $payload);
        $body = $response->json();
        if ($response->failed()) {
            throw new RuntimeException($body['error'] ?? 'No se pudo completar la operación en Restaurant.');
        }

        return is_array($body) ? $body : [];
    }
}
