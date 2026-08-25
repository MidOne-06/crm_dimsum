<?php

namespace App\Services;

use App\Models\OpmEjecucion;
use App\Models\OpmParametro;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OpmBatchRunner
{
    public static function spawn(OpmParametro $parametro): OpmEjecucion
    {
        $catalogo = app(OpmCatalogSynchronizer::class)->active();

        // Crear registro histórico de esta ejecución
        $ejecucion = DB::transaction(function () use ($parametro, $catalogo): OpmEjecucion {
            $locked = OpmParametro::query()->lockForUpdate()->findOrFail($parametro->id);

            if ($locked->ejecuciones()->where('estado', 'ejecutando')->exists()) {
                throw new RuntimeException('Este parámetro ya tiene una ejecución en curso.');
            }

            $execution = OpmEjecucion::create([
                'parametro_id' => $locked->id,
                'catalogo_id' => $catalogo->id,
                'catalogo_hash' => $catalogo->sha256,
                'estado' => 'ejecutando',
                'modo_extraccion' => 'catalogo_completo_todos_candidatos',
                'iniciado_at' => now(),
            ]);

            $locked->update([
                'estado' => 'ejecutando',
                'total_productos' => 0,
                'total_precios' => 0,
                'total_detalles' => 0,
                'ejecutado_at' => null,
            ]);

            return $execution;
        });

        $id = $parametro->id;
        $eid = $ejecucion->id;
        $dir = str_replace('/', DIRECTORY_SEPARATOR, storage_path("app/opm_batch/parametro_{$id}/ejecucion_{$eid}"));

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $proxyConfigFile = app(OpmProxyConfigurationService::class)->runtimeFile($dir);

        $script = str_replace('/', DIRECTORY_SEPARATOR, base_path('scripts/opm-batch.mjs'));
        $logFile = $dir.DIRECTORY_SEPARATOR.'batch.log';
        $node = env('NODE_EXECUTABLE', PHP_OS_FAMILY === 'Windows' ? 'C:\\Program Files\\nodejs\\node.exe' : 'node');

        $envFile = str_replace('/', DIRECTORY_SEPARATOR, base_path('.env'));

        if (PHP_OS_FAMILY !== 'Windows') {
            $envArgument = is_file($envFile)
                ? ' --env-file='.escapeshellarg($envFile)
                : '';
            $proxyArgument = $proxyConfigFile
                ? ' --proxy-config-file='.escapeshellarg($proxyConfigFile)
                : '';
            $command = sprintf(
                'nohup %s%s %s --parametro=%d --ejecucion=%d --todos-candidatos=true%s >> %s 2>&1 &',
                escapeshellcmd($node),
                $envArgument,
                escapeshellarg($script),
                $id,
                $eid,
                $proxyArgument,
                escapeshellarg($logFile),
            );

            exec($command);

            return $ejecucion;
        }

        $batFile = $dir.DIRECTORY_SEPARATOR.'run.bat';
        $proxyArgument = $proxyConfigFile ? ' "--proxy-config-file='.$proxyConfigFile.'"' : '';
        $lines = [
            '@echo off',
            '"'.$node.'" --env-file="'.$envFile.'" "'.$script.'" --parametro='.$id.' --ejecucion='.$eid.' --todos-candidatos=true'.$proxyArgument.' >> "'.$logFile.'" 2>&1',
        ];

        file_put_contents($batFile, implode("\r\n", $lines)."\r\n");
        pclose(popen('start /B cmd /C "'.$batFile.'"', 'r'));

        return $ejecucion;
    }

    /**
     * Inicia una ejecución limitada a un solo nombre del catálogo. Se persiste
     * el autocomplete ya validado para evitar una segunda llamada al proxy.
     *
     * @param  array<string, mixed>  $autocompleteResponse
     */
    public static function spawnScoped(OpmParametro $parametro, string $query, array $autocompleteResponse): OpmEjecucion
    {
        $query = trim($query);
        $candidates = $autocompleteResponse['data'] ?? null;

        if ($query === '' || ! is_array($candidates) || $candidates === []) {
            throw new RuntimeException('La previsualización de DIGEMID no contiene candidatos para ejecutar.');
        }

        $catalogo = app(OpmCatalogSynchronizer::class)->active();

        $ejecucion = DB::transaction(function () use ($parametro, $query, $catalogo): OpmEjecucion {
            $locked = OpmParametro::query()->lockForUpdate()->findOrFail($parametro->id);

            if ($locked->ejecuciones()->where('estado', 'ejecutando')->exists()) {
                throw new RuntimeException('Este parámetro ya tiene una ejecución en curso.');
            }

            $execution = OpmEjecucion::create([
                'parametro_id' => $locked->id,
                'catalogo_id' => $catalogo->id,
                'catalogo_hash' => $catalogo->sha256,
                'consulta_producto' => $query,
                'modo_extraccion' => 'producto_controlado',
                'estado' => 'ejecutando',
                'iniciado_at' => now(),
            ]);

            $locked->update([
                'estado' => 'ejecutando',
                'total_productos' => 0,
                'total_precios' => 0,
                'total_detalles' => 0,
                'ejecutado_at' => null,
            ]);

            return $execution;
        });

        try {
            $dir = self::workDir($parametro->id, $ejecucion->id);

            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException('No se pudo preparar el directorio de la ejecución.');
            }

            $cache = [
                'key' => OpmDigemidLiveValidator::normalizeText($query),
                'status' => 'OK',
                'data' => array_values($candidates),
                'api_response' => $autocompleteResponse,
                'timestamp' => now()->toIso8601String(),
                'source' => 'previsualizacion_confirmada',
            ];
            $cacheFile = $dir.DIRECTORY_SEPARATOR.'cache-autocomplete.ndjson';

            if (file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('No se pudo guardar la previsualización para la ejecución.');
            }

            self::writeJsonAtomically($dir.DIRECTORY_SEPARATOR.'previsualizacion-confirmada.json', [
                'consulta_producto' => $query,
                'parametro_id' => $parametro->id,
                'ejecucion_id' => $ejecucion->id,
                'confirmada_at' => now()->toIso8601String(),
                'respuesta_autocomplete_digemid' => $autocompleteResponse,
            ]);

            self::launch($parametro->id, $ejecucion->id, [
                '--consulta-base64='.base64_encode($query),
                '--todos-candidatos=true',
            ]);

            return $ejecucion;
        } catch (\Throwable $exception) {
            $ejecucion->update(['estado' => 'error']);
            $parametro->update(['estado' => 'error']);

            throw $exception;
        }
    }

    /** @param array<string, mixed> $payload */
    private static function writeJsonAtomically(string $destination, array $payload): void
    {
        $temporary = $destination.'.tmp';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false || file_put_contents($temporary, $json, LOCK_EX) === false || ! rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo guardar el JSON de auditoría de la previsualización.');
        }
    }

    /** @param array<int, string> $extraArguments */
    private static function launch(int $parametroId, int $ejecucionId, array $extraArguments = []): void
    {
        $dir = self::workDir($parametroId, $ejecucionId);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $proxyConfigFile = app(OpmProxyConfigurationService::class)->runtimeFile($dir);

        if ($proxyConfigFile) {
            $extraArguments[] = '--proxy-config-file='.$proxyConfigFile;
        }

        $script = str_replace('/', DIRECTORY_SEPARATOR, base_path('scripts/opm-batch.mjs'));
        $logFile = $dir.DIRECTORY_SEPARATOR.'batch.log';
        $node = env('NODE_EXECUTABLE', PHP_OS_FAMILY === 'Windows' ? 'C:\\Program Files\\nodejs\\node.exe' : 'node');
        $envFile = str_replace('/', DIRECTORY_SEPARATOR, base_path('.env'));
        $arguments = implode(' ', array_map('escapeshellarg', $extraArguments));

        if (PHP_OS_FAMILY !== 'Windows') {
            $envArgument = is_file($envFile) ? ' --env-file='.escapeshellarg($envFile) : '';
            $command = sprintf(
                'nohup %s%s %s --parametro=%d --ejecucion=%d %s >> %s 2>&1 &',
                escapeshellcmd($node),
                $envArgument,
                escapeshellarg($script),
                $parametroId,
                $ejecucionId,
                $arguments,
                escapeshellarg($logFile),
            );
            exec($command);

            return;
        }

        $batFile = $dir.DIRECTORY_SEPARATOR.'run.bat';
        $windowsArguments = implode(' ', array_map(static fn (string $argument): string => '"'.str_replace('"', '', $argument).'"', $extraArguments));
        $lines = [
            '@echo off',
            '"'.$node.'" --env-file="'.$envFile.'" "'.$script.'" --parametro='.$parametroId.' --ejecucion='.$ejecucionId.' '.$windowsArguments.' >> "'.$logFile.'" 2>&1',
        ];

        file_put_contents($batFile, implode("\r\n", $lines)."\r\n");
        pclose(popen('start /B cmd /C "'.$batFile.'"', 'r'));
    }

    public static function workDir(int $parametroId, int $ejecucionId): string
    {
        return str_replace('/', DIRECTORY_SEPARATOR,
            storage_path("app/opm_batch/parametro_{$parametroId}/ejecucion_{$ejecucionId}")
        );
    }

    public static function progressFile(int $parametroId, int $ejecucionId): string
    {
        return self::workDir($parametroId, $ejecucionId).DIRECTORY_SEPARATOR.'progress.json';
    }

    public static function logFile(int $parametroId, int $ejecucionId): string
    {
        return self::workDir($parametroId, $ejecucionId).DIRECTORY_SEPARATOR.'batch.log';
    }

    public static function readProgress(int $parametroId, int $ejecucionId): ?array
    {
        $file = self::progressFile($parametroId, $ejecucionId);
        if (! file_exists($file)) {
            return null;
        }
        $data = json_decode(file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    public static function readLog(int $parametroId, int $ejecucionId, int $last = 40): array
    {
        $file = self::logFile($parametroId, $ejecucionId);
        if (! file_exists($file)) {
            return [];
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        $size = filesize($file) ?: 0;
        $offset = max(0, $size - 262_144);
        if ($offset > 0) {
            fseek($handle, $offset);
            fgets($handle);
        }

        $lines = [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = self::redactLogLine($line);
            }
        }
        fclose($handle);

        return array_values(array_slice($lines, -min(max(1, $last), 100)));
    }

    private static function redactLogLine(string $line): string
    {
        $secrets = array_filter([
            config('database.connections.pgsql.password'),
            env('PG_PASSWORD'),
            env('DATAIMPULSE_PASSWORD'),
            app(OpmProxyConfigurationService::class)->passwordForRedaction(),
        ], static fn ($secret): bool => is_string($secret) && $secret !== '');

        foreach ($secrets as $secret) {
            $line = str_replace($secret, '[redacted]', $line);
        }

        $line = preg_replace('#(https?://)[^@\s/]+@#', '$1[redacted]@', $line) ?? $line;

        return strlen($line) > 2_000 ? substr($line, 0, 2_000).'…' : $line;
    }
}
