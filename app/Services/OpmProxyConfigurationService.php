<?php

namespace App\Services;

use App\Models\OpmProxyConfiguration;
use RuntimeException;

class OpmProxyConfigurationService
{
    /** @return array{enabled: bool, host: string, port: int, username: string, password: string, source: string} */
    public function values(): array
    {
        $configuration = OpmProxyConfiguration::query()->first();

        if ($configuration) {
            return [
                'enabled' => $configuration->enabled,
                'host' => trim($configuration->host),
                'port' => $configuration->port,
                'username' => trim((string) $configuration->username),
                'password' => (string) $configuration->password,
                'source' => 'panel',
            ];
        }

        return [
            'enabled' => true,
            'host' => trim((string) env('DATAIMPULSE_HOST', 'gw.dataimpulse.com')),
            'port' => (int) env('DATAIMPULSE_PORT', 823),
            'username' => trim((string) env('DATAIMPULSE_USER')),
            'password' => (string) env('DATAIMPULSE_PASSWORD'),
            'source' => 'environment',
        ];
    }

    public function proxyUrl(): ?string
    {
        $values = $this->values();

        if (! $values['enabled'] || $values['username'] === '' || $values['password'] === '') {
            return null;
        }

        return sprintf(
            'http://%s:%s@%s:%d',
            rawurlencode($values['username']),
            rawurlencode($values['password']),
            $values['host'],
            $values['port'],
        );
    }

    public function runtimeFile(string $directory): ?string
    {
        $configuration = OpmProxyConfiguration::query()->first();

        if (! $configuration) {
            return null;
        }

        $values = $this->values();

        if ($values['enabled'] && ($values['username'] === '' || $values['password'] === '')) {
            throw new RuntimeException('El proxy está habilitado, pero faltan usuario o contraseña en la configuración.');
        }

        $file = $directory.DIRECTORY_SEPARATOR.'proxy-runtime.json';
        $json = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (file_put_contents($file, $json, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo preparar la configuración de proxy para la ejecución.');
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($file, 0600);
        }

        return $file;
    }

    public function passwordForRedaction(): string
    {
        return $this->values()['password'];
    }
}
