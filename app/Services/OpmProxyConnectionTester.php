<?php

namespace App\Services;

use App\Models\OpmProxyConfiguration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class OpmProxyConnectionTester
{
    /**
     * Comprueba la conectividad con el proxy usando api.ipify.org.
     * No persiste ni registra las credenciales recibidas.
     *
     * @param  array<string, mixed>  $input
     * @return array{ip: string, latency_ms: int}
     */
    public function test(array $input, ?OpmProxyConfiguration $storedConfiguration = null): array
    {
        if (! ($input['enabled'] ?? false)) {
            throw new InvalidArgumentException('Active el proxy antes de ejecutar la prueba.');
        }

        $host = trim((string) ($input['host'] ?? ''));
        $port = filter_var($input['port'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? $storedConfiguration?->password ?? '');

        if ($host === '' || preg_match('/[\s@:\/\\\\]/', $host) || ! $port || $username === '' || $password === '') {
            throw new InvalidArgumentException('Complete un servidor, puerto, usuario y contraseña válidos para probar el proxy.');
        }

        $proxyUrl = sprintf(
            'http://%s:%s@%s:%d',
            rawurlencode($username),
            rawurlencode($password),
            $host,
            $port,
        );
        $startedAt = hrtime(true);

        try {
            $response = Http::accept('text/plain')
                ->timeout(15)
                ->connectTimeout(8)
                ->withOptions(['proxy' => $proxyUrl])
                ->get('https://api.ipify.org/');
        } catch (ConnectionException $exception) {
            throw new RuntimeException('No se pudo conectar mediante el proxy. Revise servidor, puerto y credenciales.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException("El proxy respondió HTTP {$response->status()} al comprobar la conexión.");
        }

        $ip = trim($response->body());

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('La respuesta de la prueba no contiene una dirección IP válida.');
        }

        return [
            'ip' => $ip,
            'latency_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ];
    }
}
