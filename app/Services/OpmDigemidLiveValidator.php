<?php

namespace App\Services;

use App\Models\OpmEjecucion;
use App\Models\OpmProductoCandidato;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Consulta puntual de autocomplete de DIGEMID para contrastarla con una
 * ejecución ya importada. No escribe datos ni inicia procesos batch.
 */
final class OpmDigemidLiveValidator
{
    private const BASE_URL = 'https://ms-opm.minsa.gob.pe/msopmcovid';

    /**
     * @return array<string, mixed>
     */
    public function compare(OpmEjecucion $execution, string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 3) {
            throw new RuntimeException('Ingrese al menos 3 caracteres para consultar DIGEMID.');
        }

        $remote = $this->autocompleteCandidates($query)
            ->map(fn (array $candidate): array => [
                'nombre_producto' => $candidate['nombreProducto'] ?? null,
                'concentracion' => $candidate['concent'] ?? null,
                'forma' => $candidate['nombreFormaFarmaceutica'] ?? null,
                'grupo' => $candidate['grupo'] ?? null,
                'cod_grupo_ff' => $candidate['codGrupoFF'] ?? null,
            ]);

        $local = OpmProductoCandidato::query()
            ->where('ejecucion_id', $execution->id)
            ->where('consulta_normalizada', self::normalizeText($query))
            ->orderBy('nombre_producto')
            ->orderBy('concentracion')
            ->get([
                'nombre_producto', 'concentracion', 'forma', 'grupo', 'cod_grupo_ff',
            ])
            ->map(fn (OpmProductoCandidato $candidate): array => [
                'nombre_producto' => $candidate->nombre_producto,
                'concentracion' => $candidate->concentracion,
                'forma' => $candidate->forma,
                'grupo' => $candidate->grupo,
                'cod_grupo_ff' => $candidate->cod_grupo_ff,
            ]);

        return $this->buildReport($execution, $query, $remote, $local);
    }

    /**
     * Construye la comparación por la misma terna que define un candidato:
     * nombre, concentración y forma farmacéutica.
     *
     * @param  Collection<int, array<string, mixed>>  $remote
     * @param  Collection<int, array<string, mixed>>  $local
     * @return array<string, mixed>
     */
    public function buildReport(OpmEjecucion $execution, string $query, Collection $remote, Collection $local): array
    {
        $remoteByKey = $remote
            ->map(fn (array $candidate): array => $this->presentCandidate($candidate))
            ->keyBy(fn (array $candidate): string => $candidate['key']);

        $localByKey = $local
            ->map(fn (array $candidate): array => $this->presentCandidate($candidate))
            ->keyBy(fn (array $candidate): string => $candidate['key']);

        $rows = $remoteByKey->keys()
            ->merge($localByKey->keys())
            ->unique()
            ->map(function (string $key) use ($remoteByKey, $localByKey): array {
                $inRemote = $remoteByKey->has($key);
                $inLocal = $localByKey->has($key);

                return [
                    'status' => $inRemote && $inLocal
                        ? 'coincide'
                        : ($inRemote ? 'falta_local' : 'adicional_local'),
                    'digemid' => $remoteByKey->get($key),
                    'local' => $localByKey->get($key),
                ];
            })
            ->sortBy([
                ['status', 'asc'],
                [fn (array $row): string => $row['digemid']['nombre_producto'] ?? $row['local']['nombre_producto'] ?? '', 'asc'],
            ])
            ->values();

        return [
            'query' => $query,
            'execution_id' => $execution->id,
            'execution_label' => sprintf(
                '#%s · %s · %s',
                $execution->id,
                $execution->parametro?->nombre ?? 'Sin parámetro',
                $execution->iniciado_at?->format('d/m/Y H:i') ?? 'Sin fecha',
            ),
            'digemid_count' => $remoteByKey->count(),
            'local_count' => $localByKey->count(),
            'matched_count' => $rows->where('status', 'coincide')->count(),
            'missing_count' => $rows->where('status', 'falta_local')->count(),
            'additional_count' => $rows->where('status', 'adicional_local')->count(),
            'rows' => $rows->all(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function autocompleteCandidates(string $query): Collection
    {
        return collect($this->autocompleteResponse($query)['data'])
            ->filter(fn (mixed $candidate): bool => is_array($candidate) && filled($candidate['nombreProducto'] ?? null))
            ->values();
    }

    /**
     * Conserva el JSON original de DIGEMID para la ejecución auditada.
     *
     * @return array<string, mixed>
     */
    public function autocompleteResponse(string $query): array
    {
        $response = $this->request()
            ->post(self::BASE_URL.'/producto/autocompleteciudadano', [
                'filtro' => [
                    'nombreProducto' => $query,
                    'pagina' => 1,
                    'tamanio' => 10,
                    'tokenGoogle' => '',
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("DIGEMID respondió HTTP {$response->status()}.");
        }

        $payload = $response->json();

        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
            throw new RuntimeException('DIGEMID no devolvió una respuesta JSON válida.');
        }

        return $payload;
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10)
            ->withHeaders([
                'Origin' => 'https://opm-digemid.minsa.gob.pe',
                'Referer' => 'https://opm-digemid.minsa.gob.pe/',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150 Safari/537.36',
            ]);

        $proxy = app(OpmProxyConfigurationService::class)->proxyUrl();

        return $proxy ? $request->withOptions(['proxy' => $proxy]) : $request;
    }

    /** @param array<string, mixed> $candidate */
    private function presentCandidate(array $candidate): array
    {
        return [
            'key' => implode('|', [
                self::normalizeText($candidate['nombre_producto'] ?? ''),
                self::normalizeConcentration($candidate['concentracion'] ?? ''),
                self::normalizeText($candidate['forma'] ?? ''),
                (string) ($candidate['grupo'] ?? ''),
                (string) ($candidate['cod_grupo_ff'] ?? ''),
            ]),
            'nombre_producto' => $candidate['nombre_producto'] ?: 'Sin nombre',
            'concentracion' => $candidate['concentracion'] ?: 'Sin concentración',
            'forma' => $candidate['forma'] ?: 'Sin forma farmacéutica',
        ];
    }

    public static function normalizeText(string $value): string
    {
        return mb_strtoupper((string) Str::of($value)->ascii()->squish());
    }

    public static function normalizeConcentration(string $value): string
    {
        $normalized = str_replace([',', 'µ', 'μ'], ['.', 'U', 'U'], self::normalizeText($value));
        $normalized = preg_replace('/\s+/', '', $normalized) ?? '';

        return preg_replace_callback('/(\d+(?:\.\d+)?)G(?=\/|\+|$)/', function (array $matches): string {
            $milligrams = (float) $matches[1] * 1000;

            return rtrim(rtrim((string) $milligrams, '0'), '.').'MG';
        }, $normalized) ?? $normalized;
    }
}
