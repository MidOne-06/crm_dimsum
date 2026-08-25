<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente hacia el módulo /kardex del gateway Node (D:\DS-TI\API-TI). Solo
 * lectura -- genera y descarga los mismos reportes que "2 Descargar" en
 * Kardex General de Restaurant.pe Logística, no escribe nada en el ERP.
 */
class KardexGatewayClient
{
    private const MODULE_PREFIX = '/kardex';

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
        if ($localId === '') {
            return [];
        }

        return $this->get('/api/almacenes', ['local_id' => $localId])['almacenes'] ?? [];
    }

    /** @return array<int, array{id: int, label: string}> */
    public function motivos(): array
    {
        return $this->get('/api/motivos')['motivos'] ?? [];
    }

    /**
     * Genera y descarga el reporte de kardex (mismo endpoint report.php que usa
     * "Descargar" en el ERP). Devuelve el binario crudo tal cual, listo para
     * un streamDownload() -- no es JSON.
     *
     * @return array{content: string, contentType: string}
     */
    public function reporte(array $filters): array
    {
        // ProcesarLocalKardexJob tolera hasta 600s por intento (3 intentos
        // con backoff) precisamente porque un reporte grande vía navegador
        // headless contra Restaurant.pe puede tardar -- 120s aquí lo mataba
        // siempre antes de que ese presupuesto sirviera de algo.
        $response = Http::baseUrl($this->baseUrl)->timeout(300)->get('/api/reporte', $filters);

        if ($response->failed()) {
            $body = $response->json();
            throw new RuntimeException($body['error'] ?? 'No se pudo generar el reporte de kardex.');
        }

        return [
            'content' => $response->body(),
            'contentType' => $response->header('Content-Type') ?: 'application/octet-stream',
        ];
    }

    private function get(string $path, array $query = []): array
    {
        $response = Http::baseUrl($this->baseUrl)->timeout(60)->get($path, $query);

        $body = $response->json();

        if ($response->failed()) {
            throw new RuntimeException($body['error'] ?? 'No se pudo consultar el servicio de kardex.');
        }

        return is_array($body) ? $body : [];
    }
}
